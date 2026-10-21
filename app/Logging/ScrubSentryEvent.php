<?php

namespace App\Logging;

use Illuminate\Support\Facades\Context;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Sentry\Frame;
use Sentry\Stacktrace;

class ScrubSentryEvent
{
    public static function beforeSend(Event $event): ?Event
    {
        return app(self::class)($event);
    }

    public function __invoke(Event $event): ?Event
    {
        if (Context::getHidden('observability_excluded', false)
            || (app()->bound('request') && request()->is('tracker', 'tracker/*'))
            || str_contains((string) Context::get('job_class', ''), '\\Tracker\\')) {
            return null;
        }

        $redactor = app(Redactor::class);
        // Build an allowlisted envelope. SDK defaults may otherwise include URLs,
        // SQL, request data, cookies, breadcrumbs, local variables or user details.
        $safe = Event::createEvent($event->getId());
        $safe->setTimestamp($event->getTimestamp());
        $safe->setLevel($event->getLevel());
        $safe->setRelease($event->getRelease());
        $safe->setEnvironment($event->getEnvironment());
        $safe->setSdkIdentifier($event->getSdkIdentifier());
        $safe->setSdkVersion($event->getSdkVersion());
        $safe->setLogger('teg-approval');
        $safe->setTransaction(Context::get('route') ?? Context::get('job_class') ?? Context::get('command'));
        if ($event->getMessage() !== null) {
            $safe->setMessage($redactor->text($event->getMessage()));
        }
        $tags = array_intersect_key(Context::all(), array_flip([
            'correlation_id', 'source', 'route', 'http_method', 'http_status',
            'job_id', 'job_class', 'queue', 'queue_connection', 'attempt', 'command',
        ]));
        $safe->setTags(array_map(fn ($value) => $redactor->text((string) $value), array_filter($tags, fn ($value) => is_scalar($value))));
        $safe->setExceptions(array_map(function (ExceptionDataBag $exception) use ($redactor): ExceptionDataBag {
            $clean = new ExceptionDataBag(new \RuntimeException($redactor->text($exception->getValue())));
            $clean->setType($exception->getType());
            if ($mechanism = $exception->getMechanism()) {
                $clean->setMechanism(new ExceptionMechanism($mechanism->getType(), $mechanism->isHandled()));
            }
            if ($trace = $exception->getStacktrace()) {
                $frames = array_map(fn (Frame $frame) => new Frame(
                    $frame->getFunctionName(), $redactor->text($frame->getFile()),
                    $frame->getLine(), null, null, [], $frame->isInApp(),
                ), $trace->getFrames());
                if ($frames !== []) {
                    $clean->setStacktrace(new Stacktrace($frames));
                }
            }

            return $clean;
        }, $event->getExceptions()));

        return $safe;
    }
}
