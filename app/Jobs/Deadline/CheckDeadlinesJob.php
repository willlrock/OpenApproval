<?php

namespace App\Jobs\Deadline;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Оркестратор: запускает 4 deadline-job'а в строгом порядке.
 * Порядок важен: сначала пингуем активную работу (зависшие согласования),
 * затем приближающиеся дедлайны, в конце — фиксируем просрочки.
 */
class CheckDeadlinesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue(config('deadlines.queue', 'deadlines'));
    }

    public function handle(): void
    {
        Log::info('CheckDeadlinesJob: starting deadline sweep');

        Bus::chain([
            new NotifyStuckApprovalsJob(),
            new NotifyPaymentDeadlinesJob(),
            new NotifySupplyDeadlinesJob(),
            new NotifyOverdueRequestsJob(),
        ])->onQueue(config('deadlines.queue', 'deadlines'))->dispatch();
    }
}
