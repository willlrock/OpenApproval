<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class RequestCreationNotifier
{
    // Called by the workflow owner inside the transaction that creates the revision.
    public function schedule(ApprovalRequest $request): void
    {
        DB::table('request_creation_notifications')->updateOrInsert(['approval_request_id' => $request->id], [
            'revision' => $request->revision, 'created_at' => now(), 'delivered_at' => null,
            'last_attempt_at' => null, 'completed_steps' => '[]',
        ]);
    }

    public function deliver(int $id): void
    {
        try {
            Cache::lock('request-creation:'.$id, 600)->block(1, function () use ($id) {
                $notice = DB::table('request_creation_notifications')->where('approval_request_id', $id)->first();
                if (! $notice || $notice->delivered_at) {
                    return;
                }
                // Every write must compare the generation on this row itself: a worker
                // can return from Telegram after the workflow has scheduled a new route.
                $delivery = DB::table('request_creation_notifications')->where('approval_request_id', $id)->where('revision', $notice->revision);
                $delivery->update(['last_attempt_at' => now()]);
                $request = ApprovalRequest::with(['approvals.approver', 'applicant', 'project', 'items', 'attachments'])->find($id);
                if ($request && $request->revision !== (int) $notice->revision) {
                    return;
                }
                if (! $request || $request->status !== 'pending_approval' || $request->expected_approval_level !== 1) {
                    $delivery->update(['delivered_at' => now()]);

                    return;
                }
                $approval = $request->approvals->firstWhere('level', 1);
                $approver = $approval?->approver;
                if (! $approver?->telegram_id || ! app(ApprovalParticipantPolicy::class)->canDecide($request, $approver)) {
                    return;
                }
                $lang = $approver->lang ?? 'ru';
                $steps = json_decode($notice->completed_steps ?? '[]', true);
                $checkpoint = function (string $key, callable $action) use ($delivery, &$steps): void {
                    if (in_array($key, $steps, true)) {
                        return;
                    }
                    $action();
                    $steps[] = $key;
                    if (! $delivery->update(['completed_steps' => json_encode($steps)])) {
                        throw new \RuntimeException('Notification revision changed during delivery.');
                    }
                };
                $bot = app(Nutgram::class);
                $pages = app(ApprovalNotificationMessage::class)->pages($request, $lang, true);
                foreach ($pages as $index => $page) {
                    $last = $index === array_key_last($pages);
                    $checkpoint('revision:'.$request->revision.':page:'.$index, function () use ($bot, $approver, $page, $last, $lang, $id, $request, $approval): void {
                        $keyboard = $last ? InlineKeyboardMarkup::make()->addRow(
                            InlineKeyboardButton::make(__('bot.notify_approve_btn', [], $lang), callback_data: "approve:{$id}_{$request->revision}"),
                            InlineKeyboardButton::make(__('bot.notify_reject_btn', [], $lang), callback_data: "reject:{$id}_{$request->revision}")
                        )->addRow(InlineKeyboardButton::make(__('notifications.open_request', [], $lang), url: route('requests.detail', $id))) : null;
                        $bot->sendMessage($page, chat_id: $approver->telegram_id, parse_mode: 'HTML', reply_markup: $keyboard);
                        if ($last) {
                            $approval->update(['notified_at' => now()]);
                        }
                    });
                }
                foreach ($request->attachments as $attachment) {
                    $checkpoint('revision:'.$request->revision.':file:'.$attachment->id, function () use ($request, $approver, $attachment): void {
                        $copy = clone $request;
                        $copy->setRelation('attachments', collect([$attachment]));
                        $this->forwardAttachments($copy, $approver, true);
                    });
                }
                $delivery->update(['delivered_at' => now()]);
            });
        } catch (\Throwable $error) {
            Log::warning('telegram.request_notification_pending_retry', [
                'request_id' => $id, 'error_type' => $error::class, 'error_code' => $error->getCode(),
                'error_file' => basename($error->getFile()), 'error_line' => $error->getLine(),
            ]);
        }
    }

    public function forwardAttachments(ApprovalRequest $request, \App\Models\User $approver, bool $strict = false): void
    {
        if (! $approver->telegram_id || ! app(ApprovalParticipantPolicy::class)->canDecide($request, $approver)) {
            return;
        }
        $bot = app(Nutgram::class);
        foreach ($request->attachments as $attachment) {
            $stream = null;
            $caption = __('notifications.attachment', ['id' => $request->id], $approver->lang ?? 'ru');
            try {
                $file = $attachment->telegram_file_id;
                if (! $file && $attachment->file_path && Storage::disk('local')->exists($attachment->file_path)) {
                    $stream = fopen(Storage::disk('local')->path($attachment->file_path), 'r');
                    $file = InputFile::make($stream);
                }
                if (! $file) {
                    throw new \RuntimeException('Request attachment source is unavailable.');
                }
                if ($attachment->telegram_file_id && in_array($attachment->file_type, ['jpg', 'jpeg', 'png'])) {
                    try {
                        $bot->sendPhoto($file, chat_id: $approver->telegram_id, caption: $caption);
                    } catch (TelegramException $error) {
                        // Legacy image documents were stored as jpg, but Telegram cannot
                        // resend a document file_id through sendPhoto.
                        if ($error->getCode() !== 400) {
                            throw $error;
                        }
                        $metadata = $bot->getFile($file);
                        if (! str_starts_with($metadata?->file_path ?? '', 'documents/')) {
                            throw $error;
                        }
                        $bot->sendDocument($file, chat_id: $approver->telegram_id, caption: $caption);
                    }
                } else {
                    $bot->sendDocument($file, chat_id: $approver->telegram_id, caption: $caption);
                }
            } catch (\Throwable $error) {
                if ($strict) {
                    throw $error;
                }
                Log::warning('telegram.request_attachment_failed', [
                    'request_id' => $request->id, 'attachment_id' => $attachment->id,
                    'error_type' => $error::class, 'error_code' => $error->getCode(),
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
