<?php

namespace App\Jobs;

use App\Models\TelegramBroadcast;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use Throwable;

class SendTelegramBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;

    public int $timeout = 70;

    public int $backoff = 30;

    public CarbonInterface $deadline;

    public function __construct(public int $broadcastId, ?CarbonInterface $deadline = null)
    {
        $this->deadline = $deadline ?? now()->addDay();
    }

    public function retryUntil(): CarbonInterface
    {
        return $this->deadline;
    }

    public function middleware(): array
    {
        // All broadcasts share a lock to keep multiple workers/admins from
        // multiplying the sending rate. Short batches fit the queue timeout.
        return [(new WithoutOverlapping('telegram-broadcast'))->shared()->releaseAfter(5)->expireAfter(80)];
    }

    public function handle(Nutgram $bot): void
    {
        $broadcast = TelegramBroadcast::find($this->broadcastId);
        if (! $broadcast || ! in_array($broadcast->status, ['queued', 'sending', 'completed'])) {
            return;
        }

        if ($broadcast->status === 'completed') {
            $this->report($bot, $broadcast);

            return;
        }

        $broadcast->update(['status' => 'sending']);
        $end = min($broadcast->cursor + 5, count($broadcast->recipients));
        while ($broadcast->cursor < $end) {
            $chatId = (int) $broadcast->recipients[$broadcast->cursor];
            $eligible = User::where('telegram_id', $chatId)->where('account_status', 'approved')->where('is_active', true)->exists();
            $sent = false;
            if ($eligible) {
                try {
                    $message = $bot->sendMessage(app(\App\Services\TelegramNotificationMessage::class)->single($broadcast->text), chat_id: $chatId, parse_mode: 'HTML');
                    if ($message === null) {
                        throw new \RuntimeException('Telegram returned no message for broadcast delivery.');
                    }
                    $sent = true;
                } catch (TelegramException $e) {
                    if ($e->getCode() === 429) {
                        $this->release($this->retryDelay($e));

                        return;
                    }
                    if (! in_array($e->getCode(), [400, 403])) {
                        throw $e;
                    }
                    Log::notice('Telegram broadcast recipient unavailable', [
                        'broadcast_id' => $broadcast->id, 'chat_id' => $chatId, 'code' => $e->getCode(),
                    ]);
                }
            }

            $broadcast->cursor++;
            $sent ? $broadcast->sent_count++ : $broadcast->failed_count++;
            $broadcast->save();
            usleep(100_000);
        }

        if ($broadcast->cursor < count($broadcast->recipients)) {
            // Release the same job, preserving its deadline and durable cursor.
            $this->release(1);

            return;
        }

        $broadcast->update(['status' => 'completed']);
        $this->report($bot, $broadcast);
    }

    private function report(Nutgram $bot, TelegramBroadcast $broadcast): void
    {
        if ($broadcast->report_sent_at) {
            return;
        }

        try {
            $message = $bot->sendMessage(
                app(\App\Services\TelegramNotificationMessage::class)->single(__('bot.adm_broadcast_'.$broadcast->status, [
                    'sent' => $broadcast->sent_count,
                    'failed' => $broadcast->failed_count,
                    'remaining' => count($broadcast->recipients) - $broadcast->cursor,
                ], $broadcast->lang)),
                chat_id: $broadcast->admin_chat_id, parse_mode: 'HTML'
            );
            if ($message === null) {
                throw new \RuntimeException('Telegram returned no broadcast report message.');
            }
        } catch (TelegramException $e) {
            if ($e->getCode() === 429) {
                $this->release($this->retryDelay($e));

                return;
            }
            if (! in_array($e->getCode(), [400, 403])) {
                throw $e;
            }
            Log::notice('Telegram broadcast report unavailable', ['broadcast_id' => $broadcast->id]);
        }

        $broadcast->update(['report_sent_at' => now()]);
    }

    private function retryDelay(TelegramException $e): int
    {
        // Nutgram's mapped exceptions may keep response parameters on the
        // original exception rather than the wrapper.
        $original = $e->getPrevious();

        return max(1, (int) $e->getParameter('retry_after',
            $original instanceof TelegramException ? $original->getParameter('retry_after', 30) : 30
        ));
    }

    public function failed(?Throwable $exception): void
    {
        $broadcast = TelegramBroadcast::find($this->broadcastId);
        if (! $broadcast || ! in_array($broadcast->status, ['queued', 'sending'])) {
            return;
        }

        $broadcast->update(['status' => 'failed']);
        Log::error('Telegram broadcast stopped', ['broadcast_id' => $broadcast->id]);
        try {
            $this->report(app(Nutgram::class), $broadcast);
        } catch (Throwable) {
            Log::warning('Could not send Telegram broadcast failure report', ['broadcast_id' => $broadcast->id]);
        }
    }
}
