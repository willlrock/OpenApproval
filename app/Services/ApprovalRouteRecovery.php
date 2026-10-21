<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ApprovalRouteRecovery
{
    /** Caller holds the workflow lock and changed user's lock, in the access transaction. */
    public function accessChanged(User $user, User $actor): void
    {
        $requests = ApprovalRequest::whereIn('status', ['pending_approval', 'approval_paused'])
            ->where(fn ($q) => $q->whereHas('approvals', fn ($a) => $a->where('approver_id', $user->id)
                ->where('status', 'pending')->whereColumn('level', '>=', 'approval_requests.expected_approval_level'))
                ->orWhere(fn ($r) => $r->where('status', 'approval_paused')->where('applicant_id', $user->id)))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($requests as $request) {
            $pending = $request->approvals()->where('approver_id', $user->id)->where('status', 'pending')
                ->where('level', '>=', $request->expected_approval_level)->exists();
            if ($pending) {
                $this->pauseIfUnavailable($request, $user, $actor);
            }
            if ($request->status === 'approval_paused' && $request->applicant_id === $user->id && ! $this->responsibleAvailable($request)) {
                app(RequestEffects::class)->record($request, 'route-owner-unavailable:'.$user->id, ['unavailable' => $request->route_unavailable ?? []]);
            }
        }
    }

    /** Caller holds the workflow lock and project lock in the project update transaction. */
    public function projectAccessChanged(Project $project, User $actor): void
    {
        if ($project->is_active) {
            return;
        }

        $requests = ApprovalRequest::where('project_id', $project->id)
            ->whereIn('status', ['pending_approval', 'approval_paused'])
            ->with(['approvals' => fn ($q) => $q->orderBy('level'), 'approvals.approver'])
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($requests as $request) {
            $sequence = $request->route_recovery_sequence;
            foreach ($request->approvals->where('status', 'pending')->where('level', '>=', $request->expected_approval_level) as $approval) {
                $this->pauseIfUnavailable($request, $approval->approver, $actor);
            }
            // Previously delivered pause events may have reached only the owner.
            // If there is no new participant event, notify the administrators
            // that the inactive project also made that owner unavailable.
            if ($request->status === 'approval_paused' && $request->route_recovery_sequence === $sequence) {
                app(RequestEffects::class)->record($request, 'route-owner-unavailable:'.$request->applicant_id,
                    ['unavailable' => $request->route_unavailable ?? []]);
            }
        }
    }

    private function pauseIfUnavailable(ApprovalRequest $request, User $user, User $actor): void
    {
        $unavailable = $request->route_unavailable ?? [];
        if (app(ApprovalParticipantPolicy::class)->canDecide($request, $user) || collect($unavailable)->contains('id', $user->id)) {
            return;
        }
        $unavailable[] = ['id' => $user->id, 'name' => $user->full_name, 'changed_by' => $actor->id, 'at' => now()->toIso8601String()];
        $request->update(['status' => 'approval_paused', 'route_unavailable' => $unavailable,
            'approval_paused_at' => $request->approval_paused_at ?? now(),
            'route_recovery_sequence' => $request->route_recovery_sequence + 1, 'route_draft' => null]);
        app(RequestEffects::class)->record($request, 'route-paused:'.$user->id, ['unavailable' => $unavailable]);
    }

    private function responsibleAvailable(ApprovalRequest $request): bool
    {
        $owner = $request->applicant?->fresh();
        if (! $owner?->isApproved() || ! \App\Models\Project::whereKey($request->project_id)->where('is_active', true)->exists()
            || (! $owner->hasRole(['admin', 'superadmin']) && ! $owner->projects()->where('projects.id', $request->project_id)->exists())) {
            return false;
        }

        return ! $request->supply_applicant_id || $owner->isSupplyDirector();
    }

    public function canRecover(ApprovalRequest $request, User $actor): bool
    {
        $actor = $actor->fresh();
        if ($request->status !== 'approval_paused' || ! $actor?->isApproved()) {
            return false;
        }
        if ($actor->hasRole(['admin', 'superadmin'])) {
            // Preserve administrative hierarchy when an administrator takes over.
            return $actor->isSuperAdmin() || $request->applicant_id === $actor->id
                || ! $request->applicant?->hasRole(['admin', 'superadmin']);
        }

        return $request->applicant_id === $actor->id && $this->responsibleAvailable($request);
    }

    public function recipients(ApprovalRequest $request)
    {
        if ($this->responsibleAvailable($request)) {
            return collect([$request->applicant->fresh()]);
        }

        return User::where('account_status', 'approved')->where('is_active', true)
            ->whereHas('roleRelation', fn ($q) => $q->whereIn('name', ['admin', 'superadmin']))->get()
            ->filter(fn ($u) => $this->canRecover($request, $u));
    }

    public function editor(int $id, User $actor): array
    {
        $request = ApprovalRequest::with('approvals.approver')->findOrFail($id);
        abort_unless($this->canRecover($request, $actor), 403);

        return ['id' => $request->id, 'name' => $request->name, 'revision' => $request->revision,
            'sequence' => $request->route_recovery_sequence, 'unavailable' => $request->route_unavailable ?? [],
            'route' => $request->approvals->sortBy('level')->map(fn ($a) => ['id' => $a->approver_id,
                'name' => $a->approver?->full_name, 'status' => $a->status,
                'unavailable' => collect($request->route_unavailable ?? [])->contains('id', $a->approver_id)])->values(),
            'draft' => $request->route_draft,
            'eligible' => app(ApprovalParticipantPolicy::class)->eligible($request->project_id,
                [$request->applicant_id, $request->supply_applicant_id], $request->isManagementDocument())
                ->orderBy('full_name')->get(['users.id', 'users.full_name']),
        ];
    }

    public function save(int $id, User $actor, array $input): void
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'sequence' => 'required|integer|min:0',
            'approvers' => 'required|array|min:1|max:30', 'approvers.*' => 'required|integer|distinct'])->validate();
        DB::transaction(function () use ($id, $actor, $data) {
            $request = $this->locked($id, $actor, $data);
            $this->assertChain($request, $data['approvers']);
            $request->update(['route_draft' => ['approvers' => array_map('intval', $data['approvers']), 'saved_by' => $actor->id,
                'saved_at' => now()->toIso8601String()], 'route_recovery_sequence' => $request->route_recovery_sequence + 1]);
        });
    }

    public function submit(int $id, User $actor, array $input): ApprovalRequest
    {
        $data = Validator::make($input, ['revision' => 'required|integer|min:1', 'sequence' => 'required|integer|min:0'])->validate();

        return DB::transaction(function () use ($id, $actor, $data) {
            $request = $this->locked($id, $actor, $data);
            $ids = $request->route_draft['approvers'] ?? [];
            $this->assertChain($request, $ids);
            app(RequestHistory::class)->archive($request);
            $request->approvals()->delete();
            foreach ($ids as $index => $approver) {
                $request->approvals()->create(['approver_id' => $approver, 'level' => $index + 1, 'status' => 'pending']);
            }
            $request->update(['revision' => $request->revision + 1, 'status' => 'pending_approval', 'expected_approval_level' => 1,
                'document_version' => $request->isManagementDocument() ? $request->document_version + 1 : $request->document_version,
                'route_unavailable' => null, 'route_draft' => null, 'approval_paused_at' => null,
                'route_recovery_sequence' => $request->route_recovery_sequence + 1]);
            app(RequestEffects::class)->record($request, 'route-resubmitted', ['submitted_by' => $actor->id]);
            app(RequestCreationNotifier::class)->schedule($request);

            return $request->fresh();
        });
    }

    private function locked(int $id, User $actor, array $data): ApprovalRequest
    {
        app(ApprovalParticipantPolicy::class)->lockWorkflow();
        $request = ApprovalRequest::lockForUpdate()->findOrFail($id);
        abort_unless($this->canRecover($request, $actor), 403);
        abort_unless($request->revision === (int) $data['revision'] && $request->route_recovery_sequence === (int) $data['sequence'],
            409, 'Маршрут изменился. Откройте заявку заново.');

        return $request;
    }

    private function assertChain(ApprovalRequest $request, array $ids): void
    {
        app(ApprovalParticipantPolicy::class)->assertChain($request->project_id, $ids,
            [$request->applicant_id, $request->supply_applicant_id], $request->isManagementDocument());
        if ($request->isManagementDocument()) {
            foreach (User::whereKey($ids)->get() as $signer) {
                abort_unless(Storage::disk('local')->exists($signer->signature_path), 422, 'Файл подписи согласующего отсутствует.');
            }
        }
    }
}
