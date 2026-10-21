<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Policies\ApprovalRequestPolicy;

class RequestActionPermissions
{
    public function forRequest(ApprovalRequest $request, User $actor): array
    {
        $user = $actor->fresh();
        $access = app(ApprovalRequestAccess::class);
        $canManagePayments = $user->isApproved()
            && $user->hasRole(['admin', 'superadmin', 'accountant', 'treasurer'])
            && $access->financialQuery(ApprovalRequest::query(), $user)->whereKey($request->id)->exists();

        return [
            'can_recover_route' => app(ApprovalRouteRecovery::class)->canRecover($request, $user),
            'route_unavailable' => $request->route_unavailable ?? [],
            'can_manage_payments' => $canManagePayments,
            'can_manage_supply' => $request->type === 'supply' && $request->status === 'pending_supply'
                && app(ApprovalRequestPolicy::class)->manageSupply($user, $request),
            'can_attach_supplier' => $user->isApproved() && $user->hasRole(['admin', 'superadmin', 'supplier'])
                && in_array($request->status, ['pending_supply', 'rejected'], true)
                && $access->scopeForParticipation(ApprovalRequest::query(), $user)->whereKey($request->id)->exists(),
        ];
    }
}
