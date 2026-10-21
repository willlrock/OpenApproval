<?php

namespace App\Jobs\Tracker;

use App\Models\User;
use App\Services\DeadlineNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Mirrors a tracker in-app notification to Telegram (§20, D7). Runs on the
 * queue so it never blocks the request; reuses the platform's Nutgram bot via
 * DeadlineNotifier (errors per-recipient are swallowed there).
 */
class SendTrackerTelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Tracker notification type → bot translation key. Types without a key
     * (no Telegram template) are skipped silently.
     */
    private const KEYS = [
        'assigned' => 'bot.tracker_assigned',
        'mention' => 'bot.tracker_mention',
        'comment' => 'bot.tracker_comment',
        'task_moved' => 'bot.tracker_moved',
    ];

    /**
     * @param  array<int>  $userIds  recipients (actor already excluded upstream)
     * @param  array<string,mixed>  $data  notification payload (task_title, status, …)
     */
    public function __construct(
        private array $userIds,
        private string $type,
        private array $data,
    ) {}

    public function handle(DeadlineNotifier $notifier): void
    {
        if (! config('tracker.enabled', false)) {
            return;
        }

        $key = self::KEYS[$this->type] ?? null;
        if ($key === null || empty($this->userIds)) {
            return;
        }

        $params = [
            'title' => $this->data['task_title'] ?? '—',
            'list' => $this->data['status'] ?? '',
        ];

        User::query()
            ->whereIn('id', $this->userIds)
            ->whereNotNull('telegram_id')
            ->get()
            ->each(fn (User $user) => $notifier->send($user, $key, $params));
    }
}
