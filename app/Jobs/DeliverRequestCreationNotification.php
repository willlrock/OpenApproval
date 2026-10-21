<?php

namespace App\Jobs;

use App\Services\RequestCreationNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class DeliverRequestCreationNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $requestId) {}

    public function uniqueId(): string
    {
        return (string) $this->requestId;
    }

    public function handle(RequestCreationNotifier $notifier): void
    {
        // A failed delivery remains pending in the database for the scheduler.
        $notifier->deliver($this->requestId);
    }
}
