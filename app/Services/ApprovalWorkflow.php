<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalWorkflow
{
    public function addApprover(int $id, User $actor, int $newApproverId, int $revision = 1): array
    {
        return DB::transaction(function () use ($id, $actor, $newApproverId, $revision) {
            app(ApprovalParticipantPolicy::class)->lockWorkflow();
            app(ApprovalParticipantPolicy::class)->lockParticipants([$actor->id, $newApproverId]);
            $request = ApprovalRequest::with('approvals')->lockForUpdate()->findOrFail($id);
            app(ApprovalParticipantPolicy::class)->assertCanDecide($request, $actor);
            $level = $request->expected_approval_level;
            $current = $request->approvals->where('level', $level)->where('approver_id', $actor->id)->where('status', 'pending')->first();
            if ($request->revision !== $revision || ! $actor->fresh()->isApproved() || $request->isManagementDocument() || $request->status !== 'pending_approval' || ! $current
                || $request->approvals->where('level', '>', $level)->isNotEmpty()
                || $request->approvals->where('approver_id', $newApproverId)->isNotEmpty()) {
                throw ValidationException::withMessages(['error' => 'Маршрут уже изменился или добавление согласующего недопустимо.']);
            }
            app(ApprovalParticipantPolicy::class)->assertChain($request->project_id, [$newApproverId], [$request->applicant_id, $request->supply_applicant_id]);
            $new = User::findOrFail($newApproverId);
            $added = $request->approvals()->create(['approver_id' => $new->id, 'level' => $level + 1, 'status' => 'pending']);
            app(RequestEffects::class)->record($request, 'approver-added:'.$added->id, [
                'approver_id' => $new->id, 'added_by_id' => $actor->id, 'level' => $level + 1,
            ]);

            return [$request, $new, $level + 1];
        });
    }

    public function approve(int $id, User $user, ?string $comment, int $revision = 1): array
    {
        return DB::transaction(function () use ($id, $user, $comment, $revision) {
            app(ApprovalParticipantPolicy::class)->lockWorkflow();
            $request = ApprovalRequest::query()->lockForUpdate()->findOrFail($id);
            if ($request->revision !== $revision || ! $user->isApproved() || $request->status !== 'pending_approval') {
                throw ValidationException::withMessages(['error' => 'Заявка уже обработана или доступ отозван.']);
            }
            app(ApprovalParticipantPolicy::class)->assertCanDecide($request, $user);
            $approval = $request->approvals()->where('level', $request->expected_approval_level)
                ->where('approver_id', $user->id)->where('status', 'pending')->lockForUpdate()->first();
            if (! $approval) {
                throw ValidationException::withMessages(['error' => 'У вас нет прав для согласования этой заявки.']);
            }

            if (! $request->applicant_signature_snapshot_path && $request->applicant?->signature_path) {
                app(ManagementDocumentService::class)->captureApplicantSignature($request);
            }

            if ($request->isManagementDocument() || $user->signature_path) {
                app(ManagementDocumentService::class)->captureSignature($approval);
            }
            $approval->update(['status' => 'approved', 'action_date' => now(), 'comment' => $comment]);
            $next = $request->approvals()->where('level', $approval->level + 1)->first();
            if ($next) {
                $request->update(['expected_approval_level' => $next->level]);
            } elseif ($request->isManagementDocument()) {
                app(ManagementDocumentService::class)->finalize($request->fresh()->load(['project', 'applicant', 'approvals.approver']));
            } else {
                $request->update(['status' => $request->type === 'supply' ? 'pending_supply' : 'approved']);
                if ($request->type !== 'supply' && $request->tranches()->doesntExist() && $request->total_price > 0) {
                    $request->tranches()->create([
                        'amount' => $request->total_price, 'percent' => 100,
                        'due_date' => $request->deadline_date, 'comment' => 'Единовременный платёж', 'status' => 'pending',
                    ]);
                }
            }

            app(RequestEffects::class)->record($request->fresh(), 'approved:'.$approval->id, [
                'decision' => ['name' => $user->full_name, 'title' => $user->title, 'level' => $approval->level, 'comment' => $comment],
                'approval_count' => $request->approvals()->count(),
                'next_approver' => $next ? ['name' => $next->approver?->full_name, 'title' => $next->approver?->title, 'level' => $next->level] : null,
            ]);

            return [$request->fresh()->load(['approvals.approver', 'applicant', 'project', 'attachments', 'tranches']), $next, $approval];
        });
    }

    public function reject(int $id, User $actor, string $reason, int $revision = 1, bool $changes = false): array
    {
        \Illuminate\Support\Facades\Validator::make(['reason' => $reason], ['reason' => 'required|string|max:2000'])->validate();

        return DB::transaction(function () use ($id, $actor, $reason, $revision, $changes) {
            app(ApprovalParticipantPolicy::class)->lockWorkflow();
            $request = ApprovalRequest::lockForUpdate()->findOrFail($id);
            abort_unless($request->status === 'pending_approval' && $request->revision === $revision, 409, 'Заявка уже обработана или изменилась.');
            abort_if($changes && ($request->isManagementDocument() || $request->supply_applicant_id), 409);
            if ($changes) {
                abort_unless(app(ApprovalParticipantPolicy::class)->canDecide($request, $actor), 403);
            }
            app(ApprovalParticipantPolicy::class)->assertCanDecide($request, $actor);
            $approval = $request->approvals()->where('level', $request->expected_approval_level)->where('approver_id', $actor->id)->where('status', 'pending')->lockForUpdate()->firstOrFail();
            $approval->update(['status' => 'rejected', 'action_date' => now(), 'comment' => ($changes ? 'На доработку: ' : '').$reason]);
            $request->update(['status' => $changes ? 'revision_requested' : 'rejected']);
            app(RequestEffects::class)->record($request, $request->status, ['reason' => $reason]);

            return [$request->load('applicant'), $approval];
        });
    }
}
