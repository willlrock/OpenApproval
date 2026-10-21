<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Carbon\Carbon;

class RequestEventMessage
{
    public function text(ApprovalRequest $request, string $event, array $payload, string $lang): string
    {
        $t = fn (string $key, array $params = []) => __('notifications.'.$key, $params, $lang);
        if (str_starts_with($event, 'route-')) {
            $lines = [$t($event === 'route-resubmitted' ? 'route_resubmitted' : 'approval_paused').' #'.$request->id, $request->name];
            foreach ($payload['unavailable'] ?? [] as $person) {
                $lines[] = $t('approver_unavailable', ['name' => $person['name']]);
            }
            if ($event !== 'route-resubmitted') {
                $lines[] = $t('route_recovery_required');
                if (! app(ApprovalRouteRecovery::class)->canRecover($request, $request->applicant)) {
                    $lines[] = $t('route_owner_unavailable');
                }
            }

            return implode("\n", $lines);
        }
        $field = fn (string $key, $value) => $t($key).': '.$value;
        $person = fn (array $data) => (($data['name'] ?? null) ?: $t('unknown_person')).(! empty($data['title']) ? ' ('.$data['title'].')' : '');
        $isDecision = str_starts_with($event, 'approved:');
        $isAdded = str_starts_with($event, 'approver-added:');
        $status = $payload['status'] ?? $request->status;
        $finished = in_array($status, ['approved', 'pending_supply'], true);
        $label = match (true) {
            $isDecision => $finished ? 'approval_finished' : 'approval_step',
            $isAdded => 'approver_added',
            str_starts_with($event, 'payment-rejected:') => 'payment_rejected',
            in_array($event, ['paid', 'rejected', 'cancelled', 'converted', 'revision_requested'], true) => $event,
            default => 'payment_updated',
        };
        $lines = [$t($label).' #'.$request->id, '',
            $field('request', $request->name), $field('project', $request->project?->name ?? '—')];
        if ($request->currency) {
            $lines[] = $field('amount', $this->money($request->total_price).' '.$request->currency);
        }
        if ($event === 'converted' && $request->supplier_name) {
            $lines[] = $field('supplier', $request->supplier_name);
        }
        if ($isDecision) {
            $approval = $request->approvals->firstWhere('id', (int) substr($event, strlen('approved:')));
            $decision = $payload['decision'] ?? [
                'name' => $approval?->approver?->full_name, 'title' => $approval?->approver?->title,
                'level' => $approval?->level, 'comment' => $approval?->comment,
            ];
            $lines[] = '';
            $lines[] = $field('approved_by', $person($decision));
            if (isset($decision['level'])) {
                $lines[] = $field('stage', $t('stage_value', ['level' => $decision['level'], 'count' => $payload['approval_count'] ?? $request->approvals->count()]));
            }
            if (! empty($decision['comment'])) {
                $lines[] = $field('comment', $decision['comment']);
            }
            $lines[] = '';
            if ($finished) {
                $lines[] = $t($status === 'pending_supply' ? 'supply_summary' : ($request->isManagementDocument() ? 'document_summary' : 'approved_summary'));
            } else {
                $next = $request->approvals->firstWhere('level', (int) ($payload['level'] ?? 0));
                $nextPerson = $payload['next_approver'] ?? ($next ? ['name' => $next->approver?->full_name, 'title' => $next->approver?->title, 'level' => $next->level] : null);
                if ($nextPerson) {
                    $lines[] = $t('next_stage', ['level' => $nextPerson['level']]);
                    $lines[] = $field('next_approver', $person($nextPerson));
                }
            }
        }
        if ($isAdded) {
            $lines[] = '';
            $lines[] = $field('added_by', User::find($payload['added_by_id'] ?? 0)?->full_name ?? '—');
            $lines[] = $t('added_summary', ['level' => $payload['level'] ?? '—']);
        }
        if (! empty($payload['tranche_id'])) {
            $tranche = $request->tranches->firstWhere('id', (int) $payload['tranche_id']);
            $lines[] = '';
            $lines[] = $field('tranche', '#'.$payload['tranche_id']);
            if (isset($payload['tranche_amount']) || $tranche) {
                $lines[] = $field('tranche_amount', $this->money($payload['tranche_amount'] ?? $tranche->amount).' '.$request->currency);
            }
            $trancheStatus = $payload['tranche_status'] ?? 'pending';
            $lines[] = $field('tranche_status', $t('tranche_'.(in_array($trancheStatus, ['paid', 'pending', 'failed'], true) ? $trancheStatus : 'pending')));
            if (! empty($payload['promised_date'])) {
                $lines[] = $field('promised_date', Carbon::parse($payload['promised_date'])->format('d.m.Y'));
            }
            if (! empty($payload['proof'])) {
                $lines[] = $field('proof', route('requests.proof', [$request->id, $payload['tranche_id']]));
            }
        }
        if (! empty($payload['reason'])) {
            $lines[] = '';
            $lines[] = $field('reason', $payload['reason']);
        }
        if ($event === 'paid') {
            $lines[] = '';
            $lines[] = $t('paid_summary');
        }

        return implode("\n", $lines);
    }

    public function protocolCaption(ApprovalRequest $request, bool $paid, string $lang): string
    {
        return __('notifications.protocol', ['id' => $request->id], $lang)."\n".__('notifications.'.($paid ? 'protocol_paid' : 'protocol_approved'), [], $lang);
    }

    private function money($value): string
    {
        return number_format((float) $value, 2, '.', ' ');
    }
}
