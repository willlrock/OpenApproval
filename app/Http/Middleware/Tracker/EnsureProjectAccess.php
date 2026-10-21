<?php

namespace App\Http\Middleware\Tracker;

use App\Models\Tracker\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProjectAccess
{
    /**
     * Authorize project-scoped routes (§5.4). Order:
     *  - Tracker Admin overrides.
     *  - project member by tracker_project_user.
     *  - visibility=workspace → any member of the project's workspace.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $project = $request->route('project');

        if (! $project instanceof Project) {
            $project = Project::findOrFail($project);
        }

        if ($user->isTrackerAdmin()) {
            return $next($request);
        }

        if ($project->roleFor($user)) {
            return $next($request);
        }

        if ($project->visibility === 'workspace' && $project->workspace->roleFor($user)) {
            return $next($request);
        }

        abort(403, 'Нет доступа к этому проекту.');
    }
}
