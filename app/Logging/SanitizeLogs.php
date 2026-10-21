<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\LogRecord;

class SanitizeLogs
{
    public function __construct(private Redactor $redactor) {}

    public function __invoke(Logger $logger): void
    {
        // Sanitize placeholder values before Monolog interpolates them into text.
        $logger->getLogger()->pushProcessor(fn (LogRecord $record) => $record->with(
            message: $this->redactor->text($record->message),
            context: $this->redactor->value($record->context),
            extra: $this->redactor->value($record->extra),
        ));
        // Handler processors run after Laravel adds Context and interpolates messages.
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof ProcessableHandlerInterface) {
                $handler->pushProcessor(fn (LogRecord $record) => $record->with(
                    message: $this->redactor->text($record->message),
                    context: $this->redactor->value($record->context),
                    extra: $this->redactor->value(array_merge($record->extra, [
                        'service' => 'teg-approval',
                        'environment' => app()->environment(),
                        'release' => config('observability.release'),
                    ])),
                ));
            }
        }
    }
}
