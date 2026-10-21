<?php

namespace App\Console\Commands;

use App\Jobs\Deadline\NotifyOverdueRequestsJob;
use App\Jobs\Deadline\NotifyPaymentDeadlinesJob;
use App\Jobs\Deadline\NotifyStuckApprovalsJob;
use App\Jobs\Deadline\NotifySupplyDeadlinesJob;
use App\Services\DeadlineNotifier;
use Illuminate\Console\Command;

class CheckDeadlines extends Command
{
    protected $signature = 'deadlines:check
        {--sync : Run jobs synchronously in this process (skip the queue)}
        {--stage=all : Which stage to run: all|stuck|payment|supply|overdue}';

    protected $description = 'Trigger the deadline notification pipeline (stuck approvals → payment → supply → overdue).';

    public function handle(): int
    {
        $stage = $this->option('stage');
        $sync  = (bool) $this->option('sync');

        $jobs = $this->resolveJobs($stage);
        if (empty($jobs)) {
            $this->error("Unknown stage: {$stage}");
            return self::FAILURE;
        }

        if ($sync) {
            $notifier = app(DeadlineNotifier::class);
            foreach ($jobs as $jobClass) {
                $this->info("→ {$jobClass}");
                (new $jobClass())->handle($notifier);
            }
            $this->info('Done (sync).');
            return self::SUCCESS;
        }

        foreach ($jobs as $jobClass) {
            $this->info("→ dispatched {$jobClass}");
            dispatch(new $jobClass());
        }
        $this->info('Done (queued). Run `php artisan queue:work --queue=' . config('deadlines.queue', 'deadlines') . '` to process.');
        return self::SUCCESS;
    }

    private function resolveJobs(string $stage): array
    {
        return match ($stage) {
            'all'     => [
                NotifyStuckApprovalsJob::class,
                NotifyPaymentDeadlinesJob::class,
                NotifySupplyDeadlinesJob::class,
                NotifyOverdueRequestsJob::class,
            ],
            'stuck'   => [NotifyStuckApprovalsJob::class],
            'payment' => [NotifyPaymentDeadlinesJob::class],
            'supply'  => [NotifySupplyDeadlinesJob::class],
            'overdue' => [NotifyOverdueRequestsJob::class],
            default   => [],
        };
    }
}
