<?php

namespace App\Jobs\Deadline;

use App\Models\RequestApproval;
use App\Services\DeadlineNotifier;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Этап 1: «Согласование зависло».
 * Пингует approver-а, если запись pending дольше stuck_after_hours.
 * Эскалирует админам (фин.директора), если дольше escalate_after_hours.
 */
class NotifyStuckApprovalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue(config('deadlines.queue', 'deadlines'));
    }

    public function handle(DeadlineNotifier $notifier): void
    {
        $escalateHours = (int) config('deadlines.escalate_after_hours', 48);
        $cooldownHours = (int) config('deadlines.cooldown_hours', 12);

        $escalateThreshold = Carbon::now()->subHours($escalateHours);
        $cooldownThreshold = Carbon::now()->subHours($cooldownHours);

        $approvals = RequestApproval::query()
            ->with(['approver', 'approvalRequest'])
            ->stuck()
            ->select('request_approvals.*')
            ->selectRaw(RequestApproval::stageStartedSql().' as stage_started_at')
            ->where(function ($q) use ($cooldownThreshold) {
                $q->whereNull('notified_at')
                    ->orWhere('notified_at', '<=', $cooldownThreshold);
            })
            ->get();

        $remindCount = 0;
        $escalateCount = 0;

        foreach ($approvals as $approval) {
            $request = $approval->approvalRequest;
            $approver = $approval->approver;

            if (! $request || ! $approver) {
                continue;
            }

            $stageStartedAt = Carbon::parse($approval->stage_started_at);
            $hoursStuck = (int) $stageStartedAt->diffInHours(Carbon::now());

            // Эскалация админам
            if ($stageStartedAt->lessThanOrEqualTo($escalateThreshold)) {
                $escalateCount += $notifier->sendToRoles(
                    ['admin'],
                    'bot.dl_stuck_escalation',
                    [
                        'id' => $request->id,
                        'name' => $request->name ?? '—',
                        'approver' => $approver->full_name ?? "id={$approver->id}",
                        'hours' => $hoursStuck,
                    ]
                );
            }

            // Напоминание самому approver-у
            if (!app(\App\Services\ApprovalParticipantPolicy::class)->canDecide($request, $approver)) continue;
            $notifier->send($approver, 'bot.dl_stuck_approver', [
                'id' => $request->id,
                'name' => $request->name ?? '—',
                'hours' => $hoursStuck,
            ]);

            $approval->notified_at = Carbon::now();
            $approval->save();
            $remindCount++;
        }

        Log::info("NotifyStuckApprovalsJob done: reminded={$remindCount}, escalated={$escalateCount}");
    }
}
