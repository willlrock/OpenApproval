<?php

namespace App\Policies;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Services\ApprovalParticipantPolicy;
use App\Services\ApprovalRequestAccess;

class ApprovalRequestPolicy
{
    public function view(User $user, ApprovalRequest $request): bool
    {
        return app(ApprovalRequestAccess::class)->canView($request, $user);
    }

    public function manageSupply(User $user, ApprovalRequest $request): bool
    {
        $user = $user->fresh();

        return $user && $user->isApproved() && $user->isSupplyDirector()
            && ($user->isSuperAdmin() || $user->projects()->where('projects.id', $request->project_id)->where('is_active', true)->exists());
    }

    public function revise(User $user, ApprovalRequest $request): bool
    {
        return $user->fresh()->isApproved() && $request->applicant_id === $user->id
            && in_array($request->status, ['rejected', 'revision_requested'], true)
            && in_array($request->type, ['approval', 'supply'], true)
            && ! $request->supply_applicant_id && ! $request->tranches()->where('status', 'paid')->exists();
    }

    public function approve(User $user, ApprovalRequest $request): bool
    {
        return $request->status === 'pending_approval'
            && app(ApprovalParticipantPolicy::class)->canDecide($request, $user)
            && $request->approvals()->where('level', $request->expected_approval_level)
                ->where('approver_id', $user->id)->where('status', 'pending')->exists();
    }
}
