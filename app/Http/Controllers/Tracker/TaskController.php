<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tracker\Concerns\AuthorizesProject;
use App\Models\Tracker\Comment;
use App\Models\Tracker\Project;
use App\Models\Tracker\Status;
use App\Models\Tracker\Task;
use App\Services\Tracker\TrackerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    use AuthorizesProject;

    public function __construct(private TrackerService $tracker) {}

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->requireProjectEdit($request->user(), $project);
        $data = $request->validate(['status_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:1000']]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $project, $data) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $this->requireProjectEdit($request->user(), $project);
            $status = $project->statuses()->findOrFail($data['status_id']);
            $task = $project->tasks()->create(['status_id' => $status->id,
                'task_number' => (int) $project->tasks()->withTrashed()->max('task_number') + 1,
                'title' => $data['title'], 'position' => (float) $status->tasks()->max('position') + 1, 'created_by' => $request->user()->id]);
            $this->tracker->activity($project, $task, $request->user()->id, 'task_created', ['title' => $task->title]);
        }, 3);

        return back();
    }

    /**
     * Deep-link entry: /tracker/go/task/{id} → project board with the task
     * drawer open (used by notifications and shared links).
     */
    public function go(Request $request, Task $task): RedirectResponse
    {
        $this->requireProjectView($request->user(), $task->project);

        return redirect()->route('tracker.projects.show', ['project' => $task->project_id, 'task' => $task->id]);
    }

    public function show(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        $this->requireProjectView($request->user(), $project);

        $task->load([
            'assignees:id,full_name',
            'watchers:id,full_name',
            'labels',
            'checklists.items.assignee:id,full_name',
            'comments.author:id,full_name',
            'activities.actor:id,full_name',
            'attachments.uploader:id,full_name',
        ]);

        return response()->json([
            'id' => $task->id,
            'project_id' => $task->project_id,
            'task_number' => $task->task_number,
            'title' => $task->title,
            'description' => $task->description,
            'status_id' => $task->status_id,
            'priority' => $task->priority,
            'source' => $task->source,
            'start_date' => $task->start_date?->toIso8601String(),
            'due_date' => $task->due_date?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'assignees' => $task->assignees->map(fn ($u) => ['id' => $u->id, 'full_name' => $u->full_name])->values(),
            'watchers' => $task->watchers->map(fn ($u) => ['id' => $u->id, 'full_name' => $u->full_name])->values(),
            'is_watching' => $task->watchers->contains('id', $request->user()->id),
            'labels' => $task->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'checklists' => $task->checklists->map(fn ($cl) => [
                'id' => $cl->id,
                'title' => $cl->title,
                'items' => $cl->items->map(fn ($it) => [
                    'id' => $it->id,
                    'content' => $it->content,
                    'is_completed' => $it->is_completed,
                    'assignee' => $it->assignee ? ['id' => $it->assignee->id, 'full_name' => $it->assignee->full_name] : null,
                    'due_date' => $it->due_date?->toIso8601String(),
                ])->values(),
            ])->values(),
            'comments' => $task->comments->map(fn ($c) => [
                'id' => $c->id,
                'body' => $c->body,
                'author' => $c->author ? ['id' => $c->author->id, 'full_name' => $c->author->full_name] : null,
                'created_at' => $c->created_at?->toIso8601String(),
                'edited_at' => $c->edited_at?->toIso8601String(),
                'can_delete' => $c->user_id === $request->user()->id || $this->projectRole($request->user(), $project) === 'project_admin',
            ])->values(),
            'activities' => $task->activities->take(50)->map(fn ($a) => [
                'id' => $a->id,
                'type' => $a->type,
                'changes' => $a->changes,
                'actor' => $a->actor ? ['id' => $a->actor->id, 'full_name' => $a->actor->full_name] : null,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'attachments' => $task->attachments->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime' => $a->mime,
                'size' => $a->size,
                'url' => route('tracker.tasks.attachments.download', ['task' => $task->id, 'attachment' => $a->id]),
                'uploader' => $a->uploader ? ['id' => $a->uploader->id, 'full_name' => $a->uploader->full_name] : null,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
            // pickers
            'project_statuses' => $project->statuses()->whereNull('archived_at')->orderBy('position')
                ->get(['id', 'name', 'color', 'status_category'])
                ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color, 'status_category' => $s->status_category])->values(),
            'project_labels' => $project->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'project_members' => $project->members()->get(['users.id', 'full_name'])
                ->map(fn ($u) => ['id' => $u->id, 'full_name' => $u->full_name])->values(),
            'can_edit' => in_array($this->projectRole($request->user(), $project), ['project_admin', 'project_member'], true),
        ]);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        $this->requireProjectEdit($request->user(), $project);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'in:none,low,medium,high,urgent'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date'],
        ]);

        $changes = [];
        foreach ($data as $key => $value) {
            if ((string) $task->{$key} !== (string) $value) {
                $changes[$key] = ['from' => $task->{$key}, 'to' => $value];
            }
        }

        $task->fill($data)->save();

        if ($changes) {
            $this->tracker->activity($project, $task, $request->user()->id, 'task_updated', $changes);
        }

        return response()->json(['ok' => true]);
    }

    public function move(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        $this->requireProjectEdit($request->user(), $project);

        $data = $request->validate([
            'status_id' => ['required', 'integer'],
            'position' => ['sometimes', 'numeric'],
        ]);

        /** @var Status $target */
        $target = $project->statuses()->findOrFail($data['status_id']);
        $fromStatusId = $task->status_id;

        $task->status_id = $target->id;
        // No explicit position → append to the end of the target column.
        $task->position = $data['position'] ?? (float) $target->tasks()->max('position') + 1;

        if ($target->status_category === 'done' && ! $task->completed_at) {
            $task->completed_at = now();
        } elseif ($target->status_category !== 'done' && $task->completed_at) {
            $task->completed_at = null;
        }

        $task->save();

        if ($fromStatusId !== $target->id) {
            $this->tracker->activity($project, $task, $request->user()->id, 'task_moved', [
                'from_status' => $fromStatusId,
                'to_status' => $target->id,
            ]);
            $this->tracker->notify(
                $task->watchers()->pluck('users.id'),
                'task_moved',
                ['task_id' => $task->id, 'task_title' => $task->title, 'status' => $target->name],
                $request->user()->id
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Asana-style one-click complete. Completing also moves the task to the
     * first done-category status; un-completing returns it to the first
     * non-done status (board and completed_at stay consistent, §11).
     */
    public function toggleComplete(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        $this->requireProjectEdit($request->user(), $project);

        if ($task->completed_at) {
            $task->completed_at = null;
            if ($task->status?->status_category === 'done') {
                $target = $project->statuses()->whereNull('archived_at')
                    ->where('status_category', '!=', 'done')->orderBy('position')->first();
                if ($target) {
                    $task->status_id = $target->id;
                    $task->position = (float) $target->tasks()->max('position') + 1;
                }
            }
        } else {
            $task->completed_at = now();
            $done = $project->statuses()->whereNull('archived_at')
                ->where('status_category', 'done')->orderBy('position')->first();
            if ($done && $task->status_id !== $done->id) {
                $task->status_id = $done->id;
                $task->position = (float) $done->tasks()->max('position') + 1;
            }
        }

        $task->save();

        $this->tracker->activity($project, $task, $request->user()->id, 'task_updated', [
            'completed' => ['to' => (bool) $task->completed_at],
        ]);

        return response()->json(['completed_at' => $task->completed_at?->toIso8601String()]);
    }

    public function addAssignee(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        $this->requireProjectEdit($request->user(), $project);

        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $task->assignees()->syncWithoutDetaching([$data['user_id']]);
        $this->tracker->watch($task, $data['user_id']);
        $this->tracker->activity($project, $task, $request->user()->id, 'assignee_added', ['user_id' => $data['user_id']]);
        $this->tracker->notify(
            [$data['user_id']],
            'assigned',
            ['task_id' => $task->id, 'task_title' => $task->title],
            $request->user()->id
        );

        return response()->json(['ok' => true]);
    }

    public function removeAssignee(Request $request, Task $task, int $user): JsonResponse
    {
        $project = $task->project;
        $this->requireProjectEdit($request->user(), $project);

        $task->assignees()->detach($user);
        $this->tracker->activity($project, $task, $request->user()->id, 'assignee_removed', ['user_id' => $user]);

        return response()->json(['ok' => true]);
    }

    public function toggleWatch(Request $request, Task $task): JsonResponse
    {
        $this->requireProjectView($request->user(), $task->project);

        $uid = $request->user()->id;
        if ($task->watchers()->where('users.id', $uid)->exists()) {
            $task->watchers()->detach($uid);
            $watching = false;
        } else {
            $task->watchers()->attach($uid);
            $watching = true;
        }

        return response()->json(['watching' => $watching]);
    }

    public function addComment(Request $request, Task $task): JsonResponse
    {
        $project = $task->project;
        $this->requireProjectEdit($request->user(), $project);

        $data = $request->validate([
            'body' => ['required', 'string'],
            'mentions' => ['sometimes', 'array'],
            'mentions.*' => ['integer', 'exists:users,id'],
        ]);

        $actor = $request->user();
        $comment = $task->comments()->create([
            'user_id' => $actor->id,
            'body' => $data['body'],
        ]);

        // Author + mentioned users become watchers (§13).
        $this->tracker->watch($task, $actor->id);
        $mentions = $data['mentions'] ?? [];
        foreach ($mentions as $uid) {
            $this->tracker->watch($task, (int) $uid);
        }

        $this->tracker->activity($project, $task, $actor->id, 'comment_added', []);

        // Notify mentioned users, then the rest of the watchers.
        $this->tracker->notify($mentions, 'mention', [
            'task_id' => $task->id, 'task_title' => $task->title,
        ], $actor->id);

        $otherWatchers = $task->watchers()->pluck('users.id')->diff($mentions);
        $this->tracker->notify($otherWatchers, 'comment', [
            'task_id' => $task->id, 'task_title' => $task->title,
        ], $actor->id);

        return response()->json(['id' => $comment->id]);
    }

    public function deleteComment(Request $request, Task $task, Comment $comment): JsonResponse
    {
        abort_unless($comment->task_id === $task->id, 404);

        $isOwner = $comment->user_id === $request->user()->id;
        $isAdmin = $this->projectRole($request->user(), $task->project) === 'project_admin';
        abort_unless($isOwner || $isAdmin, 403);

        $comment->delete();

        return response()->json(['ok' => true]);
    }
}
