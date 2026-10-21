<?php

namespace App\Http\Middleware\Tracker;

use App\Models\Tracker\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceAccess
{
    /**
     * Authorize workspace-scoped routes against tracker_workspace_user.
     * Tracker Admin (superadmin/admin) overrides.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $workspace = $request->route('workspace');

        if (! $workspace instanceof Workspace) {
            $workspace = Workspace::findOrFail($workspace);
        }

        if (! $user->isTrackerAdmin() && ! $workspace->roleFor($user)) {
            abort(403, 'Нет доступа к этому рабочему пространству.');
        }

        return $next($request);
    }
}
