<?php

use App\Jobs\Deadline\CheckDeadlinesJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Дважды в день: утренняя и вечерняя проверка дедлайнов.
Schedule::job(new CheckDeadlinesJob)
    ->dailyAt('09:00')
    ->timezone('Asia/Tashkent')
    ->name('deadlines:morning')
    ->withoutOverlapping();

Schedule::job(new CheckDeadlinesJob)
    ->dailyAt('17:00')
    ->timezone('Asia/Tashkent')
    ->name('deadlines:evening')
    ->withoutOverlapping();

// Durable first-approver notifications: failures never roll back a submitted request.
Artisan::command('requests:notify-created', function () {
    \Illuminate\Support\Facades\DB::table('request_creation_notifications')->whereNull('delivered_at')
        ->orderByRaw('COALESCE(last_attempt_at, created_at)')->orderBy('approval_request_id')->limit(100)->pluck('approval_request_id')
        ->each(fn ($id) => \App\Jobs\DeliverRequestCreationNotification::dispatch((int) $id));
})->purpose('Retry pending first-approver notifications');
Schedule::command('requests:notify-created')->everyMinute()->withoutOverlapping();

Artisan::command('requests:deliver-effects', function () {
    \Illuminate\Support\Facades\DB::table('request_effects')->whereNull('completed_at')
        ->orderByRaw('COALESCE(last_attempt_at, created_at)')->orderBy('id')->limit(100)->pluck('id')
        ->each(fn ($id) => \App\Jobs\DeliverRequestEffect::dispatch((int) $id));
})->purpose('Deliver committed request events with retries');
Schedule::command('requests:deliver-effects')->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('operational_heartbeats')->updateOrInsert(['component' => 'scheduler'], ['last_seen_at' => now()]);
    \App\Jobs\RecordQueueHeartbeat::dispatch();
})->everyMinute()->name('operations:heartbeat')->withoutOverlapping();
