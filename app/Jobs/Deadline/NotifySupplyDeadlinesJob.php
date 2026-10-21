<?php

namespace App\Jobs\Deadline;

use App\Models\ApprovalRequest;
use App\Services\DeadlineNotifier;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Этап 3: «Дедлайн поставки приближается».
 * Шлёт снабжению (роль `supplier`) и заявителю за supply_warn_days
 * до deadline_from_supplier, если поставка ещё не зафиксирована.
 */
class NotifySupplyDeadlinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue(config('deadlines.queue', 'deadlines'));
    }

    public function handle(DeadlineNotifier $notifier): void
    {
        $warnDays = (int) config('deadlines.supply_warn_days', 2);
        $now = Carbon::now();
        $until = (clone $now)->addDays($warnDays);

        $requests = ApprovalRequest::query()
            ->with('applicant')
            ->awaitingDelivery()
            ->whereDate('deadline_from_supplier', '>=', today())
            ->whereDate('deadline_from_supplier', '<=', $until)
            ->get();

        $sent = 0;

        foreach ($requests as $req) {
            $params = [
                'id' => $req->id,
                'name' => $req->name ?? '—',
                'date' => $req->deadline_from_supplier->format('d.m.Y'),
                'days' => max(1, (int) $now->diffInDays($req->deadline_from_supplier, false)),
            ];

            // Снабжению
            $sent += $notifier->sendToRoles(
                ['supplier'],
                'bot.dl_supply_warn_supplier',
                $params
            );

            // Заявителю
            if ($req->applicant) {
                if ($notifier->send($req->applicant, 'bot.dl_supply_warn_applicant', $params)) {
                    $sent++;
                }
            }
        }

        Log::info("NotifySupplyDeadlinesJob done: messages_sent={$sent}");
    }
}
