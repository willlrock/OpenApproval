<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ApprovalRequestAccess
{
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->canViewAllRequests()) {
            return $query;
        }

        return $this->scopeForParticipation($query, $user);
    }

    public function financialQuery(Builder $query, User $actor): Builder
    {
        $user = $actor->fresh();
        abort_unless($user?->isApproved() && $user->hasRole(['admin', 'superadmin', 'accountant', 'treasurer']), 403);
        $this->financialCriteria($query, $user);
        if (! $user->hasRole(['admin', 'superadmin'])) {
            $query->whereIn('project_id', $user->projects()->pluck('projects.id'));
        }

        return $query;
    }

    private function financialCriteria(Builder $query, User $user): Builder
    {
        $query->whereIn('status', ['approved', 'paid'])->where('type', '!=', 'management_document');
        if ($user->role === 'accountant') {
            $query->where('transaction_type', 'bank');
        }

        return $query;
    }

    /** Existing project/participant boundaries for operations that change a request. */
    public function scopeForParticipation(Builder $query, User $user): Builder
    {
        if (! $user->isApproved()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->hasRole(['admin', 'superadmin'])) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user) {
            $visible->where('applicant_id', $user->id)->orWhere('supply_applicant_id', $user->id)
                ->orWhere(function (Builder $project) use ($user) {
                    $project->whereIn('project_id', $user->projects()->pluck('projects.id'));
                    if ($user->role === 'approver') {
                        $project->whereHas('approvals', fn ($q) => $q->where('approver_id', $user->id));
                    } elseif ($user->hasRole(['accountant', 'treasurer'])) {
                        $this->financialCriteria($project, $user);
                    } elseif ($user->role === 'supplier') {
                        $project->where(fn ($q) => $q->where('type', 'supply')->orWhereNotNull('supply_applicant_id'));
                    } else {
                        $project->whereRaw('1 = 0');
                    }
                });
        });
    }

    public function canView(ApprovalRequest $request, User $user): bool
    {
        return $this->scope(ApprovalRequest::query(), $user->fresh())->whereKey($request->id)->exists();
    }

    public function financialRecipients(ApprovalRequest $request)
    {
        return User::where('account_status', 'approved')->where('is_active', true)
            ->whereHas('projects', fn ($q) => $q->where('projects.id', $request->project_id))
            ->whereHas('roleRelation', fn ($q) => $q->whereIn('name',
                $request->transaction_type === 'bank' ? ['accountant', 'treasurer'] : ['treasurer']))
            ->whereNotNull('telegram_id')->get();
    }
}
