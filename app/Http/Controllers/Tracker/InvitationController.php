<?php

namespace App\Http\Controllers\Tracker;

use App\Http\Controllers\Controller;
use App\Models\Tracker\Invitation;
use App\Models\Tracker\Workspace;
use App\Models\User;
use App\Services\Tracker\TrackerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationController extends Controller
{
    public function __construct(private TrackerService $tracker)
    {
    }

    /**
     * Admin invites an existing platform user into a workspace (§4.3).
     * MVP: membership is granted immediately (no separate accept step); the
     * invitee is an existing user, so there is no password flow.
     */
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor->isTrackerAdmin() || $workspace->roleFor($actor) === 'workspace_admin',
            403
        );

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'phone' => ['nullable', 'string'],
            'role' => ['required', 'in:workspace_admin,workspace_member,workspace_guest'],
        ]);

        $user = null;
        if (! empty($data['user_id'])) {
            $user = User::find($data['user_id']);
        } elseif (! empty($data['phone'])) {
            $user = User::where('phone', $data['phone'])->first();
        }

        if (! $user) {
            throw ValidationException::withMessages([
                'phone' => 'Пользователь не найден. Приглашать можно только существующих пользователей платформы.',
            ]);
        }

        $workspace->members()->syncWithoutDetaching([$user->id => ['role' => $data['role']]]);

        Invitation::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'lookup_phone' => $data['phone'] ?? $user->phone,
            'role' => $data['role'],
            'invited_by' => $actor->id,
            'token' => Str::random(40),
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        $this->tracker->notify([$user->id], 'workspace_invite', [
            'workspace_id' => $workspace->id,
            'workspace_name' => $workspace->name,
            'role' => $data['role'],
        ], $actor->id);

        return back();
    }

    public function destroyMember(Request $request, Workspace $workspace, User $user): RedirectResponse
    {
        $actor = $request->user();
        abort_unless(
            $actor->isTrackerAdmin() || $workspace->roleFor($actor) === 'workspace_admin',
            403
        );

        $workspace->members()->detach($user->id);

        return back();
    }
}
