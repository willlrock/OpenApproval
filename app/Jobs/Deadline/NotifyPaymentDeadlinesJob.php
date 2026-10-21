<?php

namespace App\Jobs\Deadline;

use App\Models\ApprovalRequest;
use App\Models\RequestTranche;
use App\Services\DeadlineNotifier;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Этап 2: «Дедлайн оплаты приближается».
 * Шлёт бухгалтеру (bank) или казначею (cash) за payment_warn_days до
 * deadline_date одобренной заявки и due_date траншей.
 */
class NotifyPaymentDeadlinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue(config('deadlines.queue', 'deadlines'));
    }

    public function handle(DeadlineNotifier $notifier): void
    {
        $warnDays = (int) config('deadlines.payment_warn_days', 2);
        $now = Carbon::now();
        $until = (clone $now)->addDays($warnDays);

        $sent = 0;

        // 1) Заявки целиком — без траншей или с пустым графиком
        $requests = ApprovalRequest::query()
            ->unscheduledPayment()
            ->whereDate('deadline_date', '>=', today())
            ->whereDate('deadline_date', '<=', $until)
            ->get();

        foreach ($requests as $req) {
            $key = $req->transaction_type === 'cash'
                ? 'bot.dl_payment_warn_treasurer'
                : 'bot.dl_payment_warn_accountant';
            $role = $req->transaction_type === 'cash' ? 'treasurer' : 'accountant';

            $sent += $notifier->sendToRoles(
                [$role],
                $key,
                [
                    'id' => $req->id,
                    'name' => $req->name ?? '—',
                    'date' => $req->deadline_date->format('d.m.Y'),
                    'days' => max(1, (int) $now->diffInDays($req->deadline_date, false)),
                    'amount' => number_format((float) $req->total_price, 2, '.', ' '),
                    'currency' => $req->currency ?? '',
                ],
                $req->project_id
            );
        }

        // 2) Транши
        $tranches = RequestTranche::query()
            ->with('approvalRequest')
            ->unpaid()
            ->whereRaw('DATE('.RequestTranche::effectiveDeadlineSql().') BETWEEN ? AND ?', [today()->toDateString(), $until->toDateString()])
            ->get();

        foreach ($tranches as $tranche) {
            $req = $tranche->approvalRequest;
            if (! $req || $req->status !== 'approved') {
                continue;
            }

            $role = $req->transaction_type === 'cash' ? 'treasurer' : 'accountant';

            $sent += $notifier->sendToRoles(
                [$role],
                'bot.dl_tranche_warn',
                [
                    'id' => $req->id,
                    'amount' => number_format((float) $tranche->amount, 2, '.', ' '),
                    'currency' => $req->currency ?? '',
                    'date' => ($tranche->due_date ?? $req->deadline_date)->format('d.m.Y'),
                    'days' => max(1, (int) $now->diffInDays($tranche->due_date ?? $req->deadline_date, false)),
                ],
                $req->project_id
            );
        }

        Log::info("NotifyPaymentDeadlinesJob done: messages_sent={$sent}");
    }
}
