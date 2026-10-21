<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tracker\Concerns\AuthorizesProject;
use App\Models\Tracker\Project;
use App\Models\Tracker\Space;
use App\Models\Tracker\Workspace;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    use AuthorizesProject;

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['required', 'integer', 'exists:tracker_workspaces,id'],
            'space_id' => ['nullable', 'integer', 'exists:tracker_spaces,id'],
            'name' => ['required', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $workspace = Workspace::findOrFail($data['workspace_id']);
        $wsRole = $workspace->roleFor($user);

        // §21 / D6: admins always; workspace_member only if setting allows.
        $canCreate = $user->isTrackerAdmin()
            || $wsRole === 'workspace_admin'
            || ($wsRole === 'workspace_member' && $workspace->members_can_create_boards);

        abort_unless($canCreate, 403);

        // Resolve the target space: explicit, else the workspace's default space.
        $space = ! empty($data['space_id'])
            ? Space::where('workspace_id', $workspace->id)->findOrFail($data['space_id'])
            : $workspace->spaces()->orderBy('position')->first()
                ?? $this->createDefaultSpace($workspace, $user);

        $project = Project::create([
            'workspace_id' => $workspace->id,
            'space_id' => $space->id,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#6366f1',
            'created_by' => $user->id,
            'position' => (int) $workspace->projects()->max('position') + 1,
        ]);

        $project->members()->attach($user->id, ['role' => 'project_admin']);

        // Seed default statuses so the project is immediately usable.
        $defaults = [
            ['name' => 'К выполнению', 'status_category' => 'todo', 'color' => '#3b82f6'],
            ['name' => 'В работе', 'status_category' => 'in_progress', 'color' => '#f59e0b'],
            ['name' => 'Готово', 'status_category' => 'done', 'color' => '#22c55e'],
        ];
        foreach ($defaults as $i => $status) {
            $project->statuses()->create([
                'name' => $status['name'],
                'status_category' => $status['status_category'],
                'color' => $status['color'],
                'position' => $i,
            ]);
        }

        return redirect()->route('tracker.projects.show', $project->id);
    }

    public function show(Request $request, Project $project): Response
    {
        $canEdit = in_array($this->projectRole($request->user(), $project), ['project_admin', 'project_member'], true);

        $project->load([
            'statuses' => fn ($q) => $q->whereNull('archived_at')->orderBy('position'),
            'statuses.tasks' => fn ($q) => $q->where('is_archived', false)->orderBy('position')
                ->withCount(['comments', 'attachments']),
            'statuses.tasks.assignees:id,full_name',
            'statuses.tasks.labels:id,name,color',
        ]);

        return Inertia::render('tracker/Project', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'color' => $project->color,
                'icon' => $project->icon,
                'workspace_id' => $project->workspace_id,
                'space_id' => $project->space_id,
                'workspace_name' => $project->workspace?->name,
                'space_name' => $project->space?->name,
                'can_edit' => $canEdit,
                'statuses' => $project->statuses->map(fn ($status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'color' => $status->color,
                    'status_category' => $status->status_category,
                    'wip_limit' => $status->wip_limit,
                    'tasks' => $status->tasks->map(fn ($task) => [
                        'id' => $task->id,
                        'task_number' => $task->task_number,
                        'title' => $task->title,
                        'priority' => $task->priority,
                        'position' => (float) $task->position,
                        'due_date' => $task->due_date?->toIso8601String(),
                        'completed_at' => $task->completed_at?->toIso8601String(),
                        'source' => $task->source,
                        'labels' => $task->labels->map(fn ($l) => [
                            'id' => $l->id,
                            'name' => $l->name,
                            'color' => $l->color,
                        ])->values(),
                        'assignees' => $task->assignees->map(fn ($u) => [
                            'id' => $u->id,
                            'full_name' => $u->full_name,
                        ])->values(),
                        'comments_count' => $task->comments_count,
                        'attachments_count' => $task->attachments_count,
                    ])->values(),
                ])->values(),
            ],
        ]);
    }

    private function createDefaultSpace(Workspace $workspace, User $user): Space
    {
        $space = $workspace->spaces()->create([
            'name' => 'General',
            'color' => '#6366f1',
            'position' => 0,
        ]);
        $space->members()->attach($user->id, ['role' => 'space_admin']);

        return $space;
    }
}
