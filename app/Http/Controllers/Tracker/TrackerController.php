<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Models\Tracker\Task;
use App\Models\Tracker\Workspace;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrackerController extends Controller
{
    public function home(Request $request): Response
    {
        $user = $request->user();

        $query = Workspace::query()->with([
            'spaces' => fn ($q) => $q->whereNull('archived_at')->orderBy('position'),
            'spaces.projects' => fn ($q) => $q->whereNull('archived_at')->orderBy('position')
                ->withCount(['tasks' => fn ($t) => $t->where('is_archived', false)]),
        ]);

        // Tracker Admin sees every workspace; others see only their memberships.
        if (! $user->isTrackerAdmin()) {
            $query->whereHas('members', fn ($q) => $q->where('users.id', $user->id));
        }

        $workspaces = $query->orderBy('name')->get()->map(fn (Workspace $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'can_manage' => $user->isTrackerAdmin() || $w->roleFor($user) === 'workspace_admin',
            'spaces' => $w->spaces->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'icon' => $s->icon,
                'projects' => $s->projects->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'color' => $p->color,
                    'icon' => $p->icon,
                    'tasks_count' => $p->tasks_count,
                ])->values(),
            ])->values(),
        ])->values();

        // "My Work" widget (§5): tasks assigned to the current user, split into
        // Upcoming / Overdue / Completed buckets.
        $myTasks = $this->myTasks($user);

        // Invitable users — pick from the list by name instead of typing a phone.
        $invitableUsers = User::query()
            ->where('account_status', 'approved')->where('is_active', true)
            ->whereNotNull('full_name')
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'phone'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'full_name' => $u->full_name,
                'phone' => $u->phone,
            ])->values();

        return Inertia::render('tracker/Home', [
            'workspaces' => $workspaces,
            'myTasks' => $myTasks,
            'isTrackerAdmin' => $user->isTrackerAdmin(),
            'invitableUsers' => $invitableUsers,
        ]);
    }

    public function myTasksPage(Request $request): Response
    {
        return Inertia::render('tracker/MyTasks', [
            'myTasks' => $this->myTasks($request->user()),
        ]);
    }

    /**
     * @return array{upcoming: array, overdue: array, completed: array, completed_count: int}
     */
    private function myTasks(User $user): array
    {
        $base = Task::query()
            ->where('is_archived', false)
            ->whereHas('assignees', fn ($q) => $q->where('users.id', $user->id))
            ->with(['project:id,name,color', 'status:id,name,color,status_category']);

        $map = fn (Task $t) => [
            'id' => $t->id,
            'title' => $t->title,
            'task_number' => $t->task_number,
            'priority' => $t->priority,
            'due_date' => $t->due_date?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
            'project' => $t->project ? [
                'id' => $t->project->id,
                'name' => $t->project->name,
                'color' => $t->project->color,
            ] : null,
            'status' => $t->status ? [
                'name' => $t->status->name,
                'color' => $t->status->color,
            ] : null,
        ];

        $today = now()->startOfDay();

        $overdue = (clone $base)->whereNull('completed_at')
            ->whereNotNull('due_date')->where('due_date', '<', $today)
            ->orderBy('due_date')->limit(50)->get()->map($map)->values();

        $upcoming = (clone $base)->whereNull('completed_at')
            ->where(fn ($q) => $q->whereNull('due_date')->orWhere('due_date', '>=', $today))
            ->orderByRaw('due_date is null, due_date asc')->limit(50)->get()->map($map)->values();

        $completed = (clone $base)->whereNotNull('completed_at')
            ->orderByDesc('completed_at')->limit(50)->get()->map($map)->values();

        return [
            'upcoming' => $upcoming,
            'overdue' => $overdue,
            'completed' => $completed,
            'completed_count' => $completed->count(),
        ];
    }
}
