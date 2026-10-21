<?php

namespace App\Providers;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class ObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Authenticated::class, function (Authenticated $event): void {
            if (Context::has('correlation_id') && ! request()->is('tracker', 'tracker/*')) {
                Context::add('actor_id', $event->user->getAuthIdentifier());
            }
        });
        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            if ($event->request->attributes->has('correlation_id')) {
                Context::add(['route' => $event->route->getName(), 'route_template' => $event->route->uri()]);
            }
        });

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            Context::add(['correlation_id' => (string) Str::uuid(), 'source' => 'console', 'command' => $event->command]);
        });

        // Laravel hydrates the dispatching request's Context before this listener runs.
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if (str_contains($event->job->resolveName(), '\\Tracker\\')) {
                Context::addHidden('observability_excluded', true);

                return;
            }
            Context::addIf('correlation_id', (string) Str::uuid());
            Context::add([
                'source' => 'queue', 'job_id' => $event->job->getJobId(),
                'job_class' => $event->job->resolveName(), 'queue' => $event->job->getQueue(),
                'queue_connection' => $event->connectionName, 'attempt' => $event->job->attempts(),
            ]);
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            if (! str_contains($event->job->resolveName(), '\\Tracker\\')) {
                Log::error('queue.job_failed', [
                    'job_id' => $event->job->getJobId(), 'job_class' => $event->job->resolveName(),
                    'queue' => $event->job->getQueue(), 'attempt' => $event->job->attempts(),
                    'exception' => $event->exception,
                ]);
            }
        });

        Event::listen(JobAttempted::class, function (JobAttempted $event): void {
            // Inline jobs must not leave their queue metadata on the parent request.
            if (config('queue.connections.'.$event->connectionName.'.driver') === 'sync') {
                Context::hydrate($event->job->payload()['illuminate:log:context'] ?? null);
            }
        });
    }
}
