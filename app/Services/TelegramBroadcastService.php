<?php

namespace App\Services;

use App\Jobs\SendTelegramBroadcastJob;
use App\Models\TelegramBroadcast;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TelegramBroadcastService
{
    public function createDraft(User $actor, string $text): TelegramBroadcast
    {
        $this->authorize($actor);
        $text = trim($text);
        if ($text === '' || strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')) / 2 > 4096) {
            throw new InvalidArgumentException('Broadcast text must contain 1–4096 characters.');
        }

        return TelegramBroadcast::create([
            'created_by' => $actor->id,
            'admin_chat_id' => $actor->telegram_id,
            'lang' => $actor->lang ?? 'ru',
            'text' => $text,
            'recipients' => User::where('account_status', 'approved')->where('is_active', true)
                ->where('telegram_id', '>', 0)->orderBy('id')->pluck('telegram_id')->all(),
        ])->refresh();
    }

    public function queue(User $actor, int $broadcastId): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $broadcastId) {
            // Persist the confirmation claim and database job together. Replayed
            // webhooks and old preview buttons cannot enqueue a second send.
            $claimed = TelegramBroadcast::whereKey($broadcastId)
                ->where('created_by', $actor->id)->where('status', 'draft')
                ->update(['status' => 'queued']);
            if (! $claimed) {
                return false;
            }

            SendTelegramBroadcastJob::dispatch($broadcastId)->onConnection('database');

            return true;
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isApproved() || ! $actor->hasRole(['admin', 'superadmin']) || ! $actor->telegram_id) {
            throw new AuthorizationException;
        }
    }
}
