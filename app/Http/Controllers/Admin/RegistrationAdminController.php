<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use SergiX44\Nutgram\Nutgram;

class RegistrationAdminController extends Controller
{
    public function registrations(Request $request)
    {
        $query = User::query();

        if ($request->filled('status')) {
            $query->where('account_status', $request->status);
        } else {
            $query->where('account_status', 'pending');
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        $deptLabels = \App\Models\Department::activeList();

        $users = $query->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($u) => [
                'id' => $u->id,
                'telegram_id' => $u->telegram_id,
                'full_name' => $u->full_name,
                'username' => $u->username,
                'phone' => $u->phone,
                'department' => $u->department,
                'department_label' => $u->department ? ($deptLabels[$u->department] ?? $u->department) : null,
                'account_status' => $u->account_status,
                'signature_url' => $u->signature_path && (! $u->isSuperAdmin() || $request->user()->isSuperAdmin()) ? route('users.signature.show', $u->id) : null,
                'created_at' => $u->created_at?->format('d.m.Y H:i'),
            ]);

        $pendingCount = User::where('account_status', 'pending')->count();
        $approvedCount = User::where('account_status', 'approved')->count();
        $rejectedCount = User::where('account_status', 'rejected')->count();
        // Users who tapped /start but never finished the registration wizard.
        $newCount = User::where('account_status', 'new')->count();

        $roles = \App\Models\Role::orderBy('name')->get();
        $projects = \App\Models\Project::where('is_active', true)->orderBy('name')->get();
        $departments = \App\Models\Department::active()
            ->with('project')
            ->orderBy('project_id')
            ->get();

        return Inertia::render('Dashboard/Registrations', [
            'users' => $users,
            'filters' => $request->only(['status', 'search']),
            'counts' => compact('pendingCount', 'approvedCount', 'rejectedCount', 'newCount'),
            'roles' => $roles,
            'projects' => $projects,
            'departments' => $departments,
        ]);
    }

    public function approveRegistration(Request $request, $id)
    {
        $request->validate([
            'role_id' => 'required|exists:roles,id',
            'title' => 'nullable|string|max:100',
            'project_ids' => 'required|array',
            'project_ids.*' => 'exists:projects,id',
            'department_ids' => 'required|array',
            'department_ids.*' => 'exists:departments,id',
        ]);

        // Role hierarchy: only superadmin can assign admin/superadmin roles
        $actingUser = auth()->user();
        $assignedRole = \App\Models\Role::findOrFail($request->role_id);
        if (in_array($assignedRole->name, ['admin', 'superadmin']) && ! $actingUser->isSuperAdmin()) {
            return back()->withErrors(['role_id' => 'Только суперадмин может назначать роль администратора.']);
        }

        $user = User::findOrFail($id);

        $updateData = [
            'account_status' => 'approved',
            'role_id' => $request->role_id,
            'title' => $request->title,
        ];

        // Every approved role needs a way to sign in to the web workspace.
        $temporaryPassword = null;
        if (empty($user->password)) {
            $temporaryPassword = app(\App\Services\PasswordChangeService::class)->temporaryPassword();
            $updateData['password'] = $temporaryPassword;
            $updateData['password_change_required'] = true;
        }

        app(\App\Services\UserAdministration::class)->update($request->user(), $user->id, $updateData,
            $request->project_ids, $request->department_ids);

        // Notify via Telegram
        try {
            $bot = app(Nutgram::class);
            $userLang = $user->lang ?? 'ru';
            $channelInvite = config('telegram.channel_invite_url');
            $groupKeyboard = $channelInvite ? \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    text: $userLang === 'ru' ? '👥 Вступить в канал' : '👥 Join Channel',
                    url: $channelInvite
                )
            ) : null;
            app(\App\Services\TelegramNotificationMessage::class)->sendTranslated(
                $bot, 'notifications.registration_approved', [
                    'login' => route('login'),
                    'recovery' => route('password.request'),
                ], $userLang, $user->telegram_id, $groupKeyboard
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Could not notify user {$user->id}: ".$e->getMessage());
        }

        return back()->with('success', "Пользователь {$user->full_name} одобрен")
            ->with('temporary_credential', $temporaryPassword ? ['name' => $user->full_name, 'password' => $temporaryPassword] : null);
    }

    public function rejectRegistration(Request $request, $id)
    {
        $user = User::findOrFail($id);
        app(\App\Services\UserAdministration::class)->rejectRegistration($request->user(), $user->id);

        // Notify via Telegram
        try {
            $bot = app(Nutgram::class);
            app(\App\Services\TelegramNotificationMessage::class)->sendTranslated($bot, 'notifications.registration_rejected', [], $user->lang ?? 'ru', $user->telegram_id);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Could not notify user {$user->id}: ".$e->getMessage());
        }

        return back()->with('success', "Пользователь {$user->full_name} отклонён");
    }
}
