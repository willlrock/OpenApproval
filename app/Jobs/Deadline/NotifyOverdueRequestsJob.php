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
 * Этап 4: «Просрочка».
 * Помечает заявки is_overdue=true и пингует ответственных + админов.
 * Покрывает: оплату (deadline_date / tranche.due_date), поставку
 * (deadline_from_supplier).
 */
class NotifyOverdueRequestsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue(config('deadlines.queue', 'deadlines'));
    }

    public function handle(DeadlineNotifier $notifier): void
    {
        $now = Carbon::now();
        $sent = 0;
        $flagged = 0;

        // 1) Просрочка оплаты по заявке
        $payment = ApprovalRequest::query()
            ->unscheduledPayment()->overduePayment()
            ->get();

        foreach ($payment as $req) {
            $params = [
                'id' => $req->id,
                'name' => $req->name ?? '—',
                'date' => $req->deadline_date->format('d.m.Y'),
            ];
            $role = $req->transaction_type === 'cash' ? 'treasurer' : 'accountant';

            $sent += $notifier->sendToRoles([$role], 'bot.dl_overdue_payment', $params, $req->project_id);
            $sent += $notifier->sendToRoles(['admin'], 'bot.dl_overdue_admin_summary', [
                ...$params,
                'type' => 'Оплата',
            ]);

            if (! $req->is_overdue) {
                $req->is_overdue = true;
                $req->save();
                $flagged++;
            }
        }

        // 2) Просрочка поставки
        $supply = ApprovalRequest::query()
            ->with('applicant')
            ->overdueSupply()
            ->get();

        foreach ($supply as $req) {
            $params = [
                'id' => $req->id,
                'name' => $req->name ?? '—',
                'date' => $req->deadline_from_supplier->format('d.m.Y'),
            ];

            $sent += $notifier->sendToRoles(['supplier'], 'bot.dl_overdue_supply', $params);

            if ($req->applicant) {
                if ($notifier->send($req->applicant, 'bot.dl_overdue_supply', $params)) {
                    $sent++;
                }
            }

            $sent += $notifier->sendToRoles(['admin'], 'bot.dl_overdue_admin_summary', [
                ...$params,
                'type' => 'Поставка',
            ]);

            if (! $req->is_overdue) {
                $req->is_overdue = true;
                $req->save();
                $flagged++;
            }
        }

        // 3) Просрочка отдельных траншей
        $tranches = RequestTranche::query()
            ->with('approvalRequest')
            ->overdue()
            ->get();

        foreach ($tranches as $tranche) {
            $req = $tranche->approvalRequest;
            if (! $req || $req->status !== 'approved') {
                continue;
            }

            $role = $req->transaction_type === 'cash' ? 'treasurer' : 'accountant';
            $params = [
                'id' => $req->id,
                'amount' => number_format((float) $tranche->amount, 2, '.', ' '),
                'currency' => $req->currency ?? '',
                'date' => ($tranche->due_date ?? $req->deadline_date)->format('d.m.Y'),
            ];

            $sent += $notifier->sendToRoles([$role], 'bot.dl_overdue_tranche', $params, $req->project_id);

            if (! $req->is_overdue) {
                $req->is_overdue = true;
                $req->save();
                $flagged++;
            }
        }

        Log::info("NotifyOverdueRequestsJob done: messages_sent={$sent}, flagged_overdue={$flagged}");
    }
}
