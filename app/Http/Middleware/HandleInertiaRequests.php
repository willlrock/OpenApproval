<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'temporaryCredential' => fn () => $request->session()->get('temporary_credential'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'full_name' => $user->full_name,
                    'phone' => $user->phone,
                    'telegram_id' => $user->telegram_id,
                    'role' => $user->role,
                    'can_view_approval_dashboard' => $user->canViewApprovalDashboard(),
                    'tracker' => $this->trackerContext($user),
                ] : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * Task Tracker context for the top bar / switcher (§4.5). Carries only the
     * user's tracker membership — never approval-domain data.
     *
     * @return array<string, mixed>
     */
    private function trackerContext(\App\Models\User $user): array
    {
        if (! config('tracker.enabled', false)) {
            return [
                'enabled' => false,
                'is_admin' => false,
                'has_access' => false,
                'workspaces' => [],
                'tree' => null,
            ];
        }

        $isAdmin = $user->isTrackerAdmin();

        return [
            'enabled' => true,
            'is_admin' => $isAdmin,
            'has_access' => $isAdmin || $user->trackerWorkspaces()->exists(),
            'workspaces' => $user->trackerWorkspaces()
                ->get(['tracker_workspaces.id', 'name'])
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])
                ->values(),
            // Full workspace→space→project tree for the tracker sidebar.
            // Loaded only on tracker pages to keep approval pages query-free.
            'tree' => request()->routeIs('tracker.*') ? $this->trackerTree($user) : null,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function trackerTree(\App\Models\User $user): array
    {
        $query = \App\Models\Tracker\Workspace::query()->with([
            'spaces' => fn ($q) => $q->whereNull('archived_at')->orderBy('position'),
            'spaces.projects' => fn ($q) => $q->whereNull('archived_at')->orderBy('position'),
        ]);

        if (! $user->isTrackerAdmin()) {
            $query->whereHas('members', fn ($q) => $q->where('users.id', $user->id));
        }

        return $query->orderBy('name')->get()->map(fn ($w) => [
            'id' => $w->id,
            'name' => $w->name,
            'spaces' => $w->spaces->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'projects' => $s->projects->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'color' => $p->color,
                ])->values(),
            ])->values(),
        ])->values()->all();
    }
}
