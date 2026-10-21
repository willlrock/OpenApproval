<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAdministration
{
    public function lockAdministrators(): void
    {
        $role = Role::firstOrCreate(['name' => 'superadmin']);
        Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
    }

    public function assertCanManage(User $actor, User $target): void
    {
        abort_unless($actor->isApproved() && $actor->hasRole(['admin', 'superadmin']), 403);
        abort_if($target->isSuperAdmin() && ! $actor->isSuperAdmin(), 403, 'Только суперадмин может изменять аккаунт суперадмина.');
    }

    public function protectLastAdministrator(User $user, ?int $nextRoleId, ?string $nextStatus, ?bool $nextActive = null): void
    {
        if ($user->isSuperAdmin() && $user->isApproved()
            && ($nextRoleId !== $user->role_id || $nextStatus !== 'approved' || ! ($nextActive ?? $user->is_active))
            && ! User::where('role_id', $user->role_id)->where('account_status', 'approved')->where('is_active', true)->whereKeyNot($user->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Нельзя удалить, отключить или понизить последнего одобренного суперадмина.']);
        }
    }

    public function assertCanChangeAccess(User $actor, User $target): void
    {
        $actor = $actor->fresh();
        $this->assertCanManage($actor, $target);
        abort_if($target->hasRole(['admin', 'superadmin']) && ! $actor->isSuperAdmin(), 403,
            'Только суперадмин может изменять доступ административного аккаунта.');
    }

    public function rejectRegistration(User $actor, int $id): User
    {
        return $this->transaction($actor, $id, function (User $user) use ($actor) {
            $this->assertCanChangeAccess($actor, $user);
            if (! in_array($user->account_status, ['new', 'pending'], true)) {
                throw ValidationException::withMessages(['error' => 'Регистрация уже обработана. Для отключения аккаунта используйте деактивацию.']);
            }
            $this->protectLastAdministrator($user, $user->role_id, 'rejected');
            $user->update(['account_status' => 'rejected']);

            return $user;
        });
    }

    public function transaction(User $actor, int $id, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $id, $operation) {
            $this->lockAdministrators();
            $actor = User::findOrFail($actor->id);
            $target = User::lockForUpdate()->findOrFail($id);
            $this->assertCanManage($actor, $target);

            return $operation($target);
        });
    }

    public function update(User $actor, int $id, array $attributes, ?array $projects = null, ?array $departments = null): User
    {
        return $this->transaction($actor, $id, function (User $user) use ($actor, $attributes, $projects, $departments) {
            if (array_intersect(['role_id', 'account_status', 'is_active', 'phone', 'phone_normalized'], array_keys($attributes)) || $projects !== null || $departments !== null) {
                $this->assertCanChangeAccess($actor, $user);
            }
            if (isset($attributes['role_id'])) {
                $role = Role::findOrFail($attributes['role_id']);
                abort_if(in_array($role->name, ['admin', 'superadmin'], true) && ! $actor->fresh()->isSuperAdmin(), 403);
            }
            $this->protectLastAdministrator($user, $attributes['role_id'] ?? $user->role_id,
                $attributes['account_status'] ?? $user->account_status, $attributes['is_active'] ?? $user->is_active);
            $user->update($attributes);
            if ($projects !== null) {
                $user->projects()->sync($projects);
            }
            if ($departments !== null) {
                $user->departments()->sync($departments);
            }
            app(ApprovalRouteRecovery::class)->accessChanged($user->fresh(), $actor);

            return $user;
        });
    }

    public function toggleProject(User $actor, int $id, int $projectId): void
    {
        $this->transaction($actor, $id, function (User $user) use ($actor, $projectId) {
            $this->assertCanChangeAccess($actor, $user);
            $user->projects()->toggle($projectId);
            app(ApprovalRouteRecovery::class)->accessChanged($user->fresh(), $actor);
        });
    }
}
