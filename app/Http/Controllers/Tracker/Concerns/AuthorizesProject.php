<?php

namespace App\Http\Controllers\Tracker\Concerns;

use App\Models\Tracker\Project;
use App\Models\User;

trait AuthorizesProject
{
    /**
     * Effective project role for the user (Tracker Admin → project_admin).
     */
    protected function projectRole(User $user, Project $project): ?string
    {
        if ($user->isTrackerAdmin()) {
            return 'project_admin';
        }

        $role = $project->roleFor($user);
        if ($role) {
            return $role;
        }

        // visibility=workspace: any workspace member is an observer by default.
        if ($project->visibility === 'workspace' && $project->workspace->roleFor($user)) {
            return 'project_observer';
        }

        return null;
    }

    /**
     * Require edit rights (project_admin / project_member). Observers + non-members rejected.
     */
    protected function requireProjectEdit(User $user, Project $project): void
    {
        abort_unless(
            in_array($this->projectRole($user, $project), ['project_admin', 'project_member'], true),
            403,
            'Недостаточно прав для изменения этого проекта.'
        );
    }

    /**
     * Require at least read access to the project.
     */
    protected function requireProjectView(User $user, Project $project): void
    {
        abort_if($this->projectRole($user, $project) === null, 403, 'Нет доступа к этому проекту.');
    }

    /**
     * Require project_admin rights (project structure: statuses, members) — §21.
     */
    protected function requireProjectAdmin(User $user, Project $project): void
    {
        abort_unless(
            $this->projectRole($user, $project) === 'project_admin',
            403,
            'Только администратор проекта может это сделать.'
        );
    }
}
