<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\Transport\ResultStatus;

class CheckObservability extends Command
{
    protected $signature = 'observability:check {--send : Also send a test event to the configured Sentry project}';

    protected $description = 'Write a safe diagnostic log and optionally verify delivery to Sentry';

    public function handle(): int
    {
        Context::addIf('correlation_id', (string) Str::uuid());
        Log::warning('observability.check');
        $this->info('Local log written. Correlation ID: '.Context::get('correlation_id'));

        if (! $this->option('send')) {
            $this->line('Sentry delivery was not requested. Use --send to test the configured project.');

            return self::SUCCESS;
        }
        if (! config('sentry.dsn')) {
            $this->error('SENTRY_LARAVEL_DSN is not configured. No event sent.');

            return self::FAILURE;
        }
        $hub = app(HubInterface::class);
        $eventId = $hub->captureMessage('observability.check', Severity::error());
        $result = $hub->getClient()?->flush(5);
        if ($eventId === null || $result?->getStatus() !== ResultStatus::success()) {
            $this->error('Sentry did not confirm transport completion. Check connectivity and project settings.');

            return self::FAILURE;
        }
        $this->info('Sentry transport completed. Event ID: '.$eventId);
        $this->line('Verify the event and email alert in Sentry. Transport completion does not verify email delivery.');

        return self::SUCCESS;
    }
}
