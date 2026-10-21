<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserAdminController extends Controller
{
    public function users(Request $request)
    {
        $query = User::query();

        if (in_array($request->query('active'), ['1', '0'], true)) {
            $query->where('is_active', $request->query('active') === '1');
        }

        if ($request->filled('role')) {
            $query->whereHas('roleRelation', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $users = $query->join('roles', 'users.role_id', '=', 'roles.id')
            ->select('users.*', 'roles.name as role_name')
            ->with(['projects:id,name', 'departments:id,name,project_id'])
            ->withCount(['approvalRequests as requests_count'])
            ->withCount(['requestApprovals as approvals_count' => fn ($q) => $q->whereNotNull('action_date')])
            ->orderBy('roles.name')
            ->orderBy('users.full_name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id,
                'is_active' => $u->is_active,
                'can_manage_activation' => $request->user()->id !== $u->id
                    && ($request->user()->isSuperAdmin() || ! $u->hasRole(['admin', 'superadmin'])),
                'telegram_id' => $u->telegram_id,
                'full_name' => $u->full_name,
                'username' => $u->username,
                'phone' => $u->phone,
                'role' => $u->role,
                'title' => $u->title,
                'signature_url' => $u->signature_path && (! $u->isSuperAdmin() || $request->user()->isSuperAdmin()) ? route('users.signature.show', $u->id) : null,
                'created_at' => $u->created_at?->format('d.m.Y H:i'),
                'requests_count' => $u->requests_count,
                'approvals_count' => $u->approvals_count,
                'project_ids' => $u->projects->pluck('id')->toArray(),
                'department_ids' => $u->departments->pluck('id')->toArray(),
            ]);

        $roles = \App\Models\Role::orderBy('name')->get(['id', 'name']);
        $projects = Project::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $departments = Department::active()->with('project:id,name')->orderBy('project_id')->get(['id', 'project_id', 'name']);

        return Inertia::render('Dashboard/Users', [
            'users' => $users,
            'filters' => $request->only(['role', 'search', 'active']),
            'roles' => $roles,
            'projects' => $projects,
            'departments' => $departments,
        ]);
    }

    public function storeUser(Request $request)
    {
        $request->validate([
            'full_name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'max:30', new \App\Rules\UniquePhone],
            'role' => 'required|string|exists:roles,name',
            'title' => 'nullable|string|max:255',
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'exists:projects,id',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'exists:departments,id',
        ]);

        // Only superadmin can create admin/superadmin users
        $actingUser = auth()->user();
        if (in_array($request->role, ['admin', 'superadmin']) && ! $actingUser->isSuperAdmin()) {
            return back()->withErrors(['role' => 'Только суперадмин может создавать администраторов.']);
        }

        $role = \App\Models\Role::where('name', $request->role)->firstOrFail();

        $temporaryPassword = app(\App\Services\PasswordChangeService::class)->temporaryPassword();
        $user = app(\App\Services\PhoneIdentity::class)->persist(function () use ($request, $role, $temporaryPassword) {
            app(\App\Services\UserAdministration::class)->lockAdministrators();
            $actor = $request->user()->fresh();
            abort_unless($actor->isApproved() && $actor->hasRole(['admin', 'superadmin']), 403);
            abort_if(in_array($role->name, ['admin', 'superadmin']) && ! $actor->isSuperAdmin(), 403);
            $user = User::create([
                'full_name' => $request->full_name,
                'phone' => $request->phone,
                'title' => $request->title,
                'role_id' => $role->id,
                'account_status' => 'approved',
                'password' => $temporaryPassword,
                'password_change_required' => true,
            ]);

            if ($request->filled('project_ids')) {
                $user->projects()->sync($request->project_ids);
            }
            if ($request->filled('department_ids')) {
                $user->departments()->sync($request->department_ids);
            }

            return $user;
        });

        return back()->with('success', "Пользователь {$user->full_name} создан")
            ->with('temporary_credential', ['name' => $user->full_name, 'password' => $temporaryPassword]);
    }

    public function updateUser(Request $request, $id)
    {
        $request->validate([
            'phone' => ['sometimes', 'required', 'string', 'max:30', new \App\Rules\UniquePhone((int) $id)],
            'role' => 'nullable|string',
            'title' => 'nullable|string|max:255',
            'project_ids' => 'nullable|array',
            'project_ids.*' => 'exists:projects,id',
            'department_ids' => 'nullable|array',
            'department_ids.*' => 'exists:departments,id',
        ]);

        $user = User::findOrFail($id);

        if ($request->has('phone')) {
            $user->phone = $request->phone;
        }

        if ($request->has('role')) {
            $role = \App\Models\Role::where('name', $request->role)->first();
            if ($role) {
                // Only superadmin can assign admin/superadmin roles
                $actingUser = auth()->user();
                if (in_array($role->name, ['admin', 'superadmin']) && ! $actingUser->isSuperAdmin()) {
                    return back()->withErrors(['role' => 'Только суперадмин может назначать роль администратора.']);
                }
                $user->role_id = $role->id;
            }
        }

        if ($request->has('title')) {
            $user->title = $request->title;
        }

        // An existing account may still have no password, regardless of its role.
        $temporaryPassword = null;
        if (empty($user->password)) {
            $temporaryPassword = app(\App\Services\PasswordChangeService::class)->temporaryPassword();
            $user->password = $temporaryPassword;
            $user->password_change_required = true;
        }

        app(\App\Services\PhoneIdentity::class)->persist(fn () => app(\App\Services\UserAdministration::class)->update($request->user(), $user->id, $user->getDirty(),
            $request->has('project_ids') ? $request->project_ids : null,
            $request->has('department_ids') ? $request->department_ids : null));

        return back()->with('success', 'Пользователь обновлён')
            ->with('temporary_credential', $temporaryPassword ? ['name' => $user->full_name, 'password' => $temporaryPassword] : null);
    }

    public function updateActivation(Request $request, $id)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        $active = (bool) $data['is_active'];
        app(\App\Services\AccountActivation::class)->update($request->user(), (int) $id, $active);

        return back()->with('success', $active ? 'Аккаунт активирован' : 'Аккаунт деактивирован. История сохранена.');
    }
}
