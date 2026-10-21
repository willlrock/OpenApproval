<?php

namespace App\Jobs;

use App\Services\RequestEffects;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class DeliverRequestEffect implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(public int $effectId) {}

    public function uniqueId(): string
    {
        return (string) $this->effectId;
    }

    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(RequestEffects $effects): void
    {
        $effects->deliver($this->effectId);
    }
}
