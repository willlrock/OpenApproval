<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class RequestEffects
{
    /** Persist with the business change; the scheduler dispatches after commit. */
    public function record(ApprovalRequest $request, string $event, array $payload = []): void
    {
        DB::table('request_effects')->insertOrIgnore([
            'approval_request_id' => $request->id, 'revision' => $request->revision, 'event' => $event,
            'payload' => json_encode($payload + ['status' => $request->status, 'level' => $request->expected_approval_level], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function deliver(int $id): void
    {
        $effect = DB::table('request_effects')->find($id);
        if (! $effect || $effect->completed_at) {
            return;
        }
        // Events for the same request share the PDF path. Serialize their delivery.
        Cache::lock('request-effects:'.$effect->approval_request_id, 600)->block(1, function () use ($id) {
            $effect = DB::table('request_effects')->find($id);
            if (! $effect || $effect->completed_at) {
                return;
            }
            DB::table('request_effects')->where('id', $id)->update(['last_attempt_at' => now()]);
            $request = ApprovalRequest::with(['applicant', 'supplyApplicant', 'approvals.approver', 'project', 'tranches', 'attachments'])->find($effect->approval_request_id);
            $payload = json_decode($effect->payload, true);
            if (! $request || $request->revision !== (int) $effect->revision) {
                DB::table('request_effects')->where('id', $id)->update(['completed_at' => now()]);

                return;
            }
            // Approval queued before payment must not resend the final paid protocol.
            if (str_starts_with($effect->event, 'approved:') && ($payload['status'] ?? null) === 'approved' && $request->status === 'paid') {
                DB::table('request_effects')->where('id', $id)->update(['completed_at' => now()]);

                return;
            }
            $steps = json_decode($effect->completed_steps ?? '[]', true);
            $failed = [];
            $step = function (string $key, callable $action) use ($id, $effect, &$steps, &$failed): bool {
                if (in_array($key, $steps, true)) {
                    return true;
                }
                try {
                    $action();
                } catch (\Throwable $error) {
                    // Transport messages can contain bot tokens, credentials or response bodies.
                    $failed[$key] = get_class($error).' (code '.$error->getCode().')';
                    Log::warning('Request effect delivery step failed', [
                        'effect_id' => $id, 'request_id' => $effect->approval_request_id,
                        'event' => $effect->event, 'step' => $key,
                        'error_type' => get_class($error), 'error_code' => $error->getCode(),
                        'error_file' => basename($error->getFile()), 'error_line' => $error->getLine(),
                    ]);

                    return false;
                }
                $steps[] = $key;
                DB::table('request_effects')->where('id', $id)->update(['completed_steps' => json_encode($steps), 'updated_at' => now()]);

                return true;
            };
            if (str_starts_with($effect->event, 'route-')) {
                $recovery = app(ApprovalRouteRecovery::class);
                $paused = $effect->event !== 'route-resubmitted';
                if ($paused && $request->status !== 'approval_paused') {
                    DB::table('request_effects')->where('id', $id)->update(['completed_at' => now()]);

                    return;
                }
                $recipients = $paused ? $recovery->recipients($request) : collect([$request->applicant])->filter();
                foreach ($recipients as $recipient) {
                    if (! $recipient->telegram_id || ! $recipient->isApproved() || ! app(ApprovalRequestAccess::class)->canView($request, $recipient)) {
                        continue;
                    }
                    $lang = $recipient->lang ?? 'ru';
                    $text = app(RequestEventMessage::class)->text($request, $effect->event, $payload, $lang);
                    $keyboard = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                        __('notifications.'.($paused ? 'edit_approvers' : 'open_request'), [], $lang),
                        url: route($paused ? 'requests.approval-route.edit' : 'requests.detail', $request->id)));
                    if ($paused) {
                        $keyboard->addRow(InlineKeyboardButton::make(__('notifications.edit_approvers_bot', [], $lang),
                            callback_data: "recover_route:{$request->id}_{$request->revision}"));
                    }
                    foreach (app(TelegramNotificationMessage::class)->pages($text) as $index => $page) {
                        if (! $step('route-message:'.$recipient->id.':'.$index, fn () => app(Nutgram::class)->sendMessage(
                            $page, chat_id: $recipient->telegram_id, parse_mode: 'HTML', reply_markup: $keyboard))) {
                            break;
                        }
                    }
                }
                if ($failed) {
                    throw $this->deliveryException($id, $failed);
                }
                DB::table('request_effects')->where('id', $id)->update(['completed_at' => now()]);

                return;
            }
            if (str_starts_with($effect->event, 'approver-added:')) {
                $recipient = User::find($payload['approver_id'] ?? 0);
                $approval = $request->approvals->firstWhere('id', (int) substr($effect->event, strlen('approver-added:')));
                if ($request->status === 'pending_approval' && $approval?->status === 'pending'
                    && $recipient?->telegram_id && app(ApprovalParticipantPolicy::class)->canDecide($request, $recipient)) {
                    $lang = $recipient->lang ?? 'ru';
                    $text = app(RequestEventMessage::class)->text($request, $effect->event, $payload, $lang);
                    $pages = app(TelegramNotificationMessage::class)->pages($text);
                    $keyboard = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(__('notifications.open_request', [], $lang), url: route('requests.detail', $request->id)));
                    foreach ($pages as $index => $page) {
                        $last = $index === array_key_last($pages);
                        $key = $last ? 'added:'.$recipient->id : 'added-page:'.$recipient->id.':'.$index;
                        if (! $step($key, fn () => app(Nutgram::class)->sendMessage($page, chat_id: $recipient->telegram_id, parse_mode: 'HTML', reply_markup: $last ? $keyboard : null))) {
                            break;
                        }
                    }
                }
                if ($failed) {
                    throw $this->deliveryException($id, $failed);
                }
                DB::table('request_effects')->where('id', $id)->update(['completed_at' => now()]);

                return;
            }
            $isDecision = str_starts_with($effect->event, 'approved:');
            $isPaid = $effect->event === 'paid';
            $terminal = $isPaid || ($isDecision && $payload['status'] === 'approved');
            if ($terminal && in_array($request->status, ['approved', 'paid'], true) && ! $request->isManagementDocument()) {
                $step('pdf', function () use ($request) {
                    DB::transaction(function () use ($request) {
                        $locked = ApprovalRequest::lockForUpdate()->findOrFail($request->id);
                        abort_unless(in_array($locked->status, ['approved', 'paid'], true), 409);
                        app(PdfGeneratorService::class)->generateProtocol($locked);
                    });
                });
                $request->refresh();
            }
            if ($terminal || $effect->event === 'rejected') {
                $step('sheets', fn () => GoogleSheetsService::append($request));
            }
            $recipients = collect([$request->applicant, $request->supplyApplicant])->filter();
            if ($terminal) {
                $recipients = $recipients->merge($request->approvals->pluck('approver'))->merge(app(ApprovalRequestAccess::class)->financialRecipients($request));
            }
            if ($isDecision && $request->status === 'pending_supply') {
                $recipients = $recipients->merge(User::where('account_status', 'approved')->where('is_active', true)->whereHas('roleRelation', fn ($q) => $q->whereIn('name', ['supplier', 'superadmin']))->get());
            }
            foreach ($recipients->filter()->unique('id') as $recipient) {
                if (! $recipient->telegram_id || ! app(ApprovalRequestAccess::class)->canView($request, $recipient)) {
                    continue;
                }
                $lang = $recipient->lang ?? 'ru';
                $text = app(RequestEventMessage::class)->text($request, $effect->event, $payload, $lang);
                $keyboard = InlineKeyboardMarkup::make();
                if ($isDecision && $request->status === 'pending_supply' && app(\App\Policies\ApprovalRequestPolicy::class)->manageSupply($recipient, $request)) {
                    $keyboard->addRow(InlineKeyboardButton::make(__('notifications.manage_supply', [], $lang), callback_data: 'convert_supply:'.$request->id));
                }
                $keyboard->addRow(InlineKeyboardButton::make(__('notifications.open_request', [], $lang), url: route('requests.detail', $request->id)));
                if (! in_array('message:'.$recipient->id, $steps, true)) {
                    $pages = app(TelegramNotificationMessage::class)->pages($text);
                    foreach ($pages as $index => $page) {
                        $last = $index === array_key_last($pages);
                        $key = $last ? 'message:'.$recipient->id : 'message-page:'.$recipient->id.':'.$index;
                        if (! $step($key, fn () => app(Nutgram::class)->sendMessage($page, chat_id: $recipient->telegram_id, parse_mode: 'HTML', reply_markup: $last ? $keyboard : null))) {
                            break;
                        }
                    }
                }
                if ($terminal && ($request->isManagementDocument() || in_array('pdf', $steps, true)) && $request->pdf_url && Storage::disk('local')->exists($request->pdf_url)) {
                    $step('pdf:'.$recipient->id, function () use ($request, $recipient, $isPaid, $lang) {
                        $stream = fopen(Storage::disk('local')->path($request->pdf_url), 'r');
                        try {
                            app(Nutgram::class)->sendDocument(InputFile::make($stream), chat_id: $recipient->telegram_id, caption: app(RequestEventMessage::class)->protocolCaption($request, $isPaid, $lang));
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                    });
                }
            }
            if ($isDecision && $request->status === 'pending_approval' && $request->expected_approval_level === $payload['level']) {
                $approval = $request->approvals->firstWhere('level', $payload['level']);
                $next = $approval?->approver;
                if ($next?->telegram_id && app(ApprovalParticipantPolicy::class)->canDecide($request, $next)) {
                    $lang = $next->lang ?? 'ru';
                    $pages = app(ApprovalNotificationMessage::class)->pages($request, $lang);
                    foreach ($pages as $pageIndex => $page) {
                        $isLast = $pageIndex === array_key_last($pages);
                        $key = $isLast ? 'next:'.$next->id : 'next-page:'.$next->id.':'.$pageIndex;
                        $delivered = $step($key, function () use ($request, $next, $approval, $lang, $page, $isLast) {
                            $keyboard = InlineKeyboardMarkup::make()->addRow(
                                InlineKeyboardButton::make(__('bot.notify_approve_btn', [], $lang), callback_data: "approve:{$request->id}_{$request->revision}"),
                                InlineKeyboardButton::make(__('bot.notify_reject_btn', [], $lang), callback_data: "reject:{$request->id}_{$request->revision}")
                            )->addRow(InlineKeyboardButton::make(__('notifications.open_request', [], $lang), url: route('requests.detail', $request->id)));
                            app(Nutgram::class)->sendMessage(text: $page, chat_id: $next->telegram_id, parse_mode: 'HTML', reply_markup: $isLast ? $keyboard : null);
                            if ($isLast) {
                                $approval->update(['notified_at' => now()]);
                            }
                        });
                        if (! $delivered) {
                            break;
                        }
                    }
                    foreach ($request->attachments as $attachment) {
                        $step('file:'.$next->id.':'.$attachment->id, function () use ($request, $next, $attachment) {
                            $copy = clone $request;
                            $copy->setRelation('attachments', collect([$attachment]));
                            app(RequestCreationNotifier::class)->forwardAttachments($copy, $next, true);
                        });
                    }
                }
            }
            if ($failed) {
                throw $this->deliveryException($id, $failed);
            }
            DB::table('request_effects')->where('id', $id)->update(['completed_at' => now()]);
        });
    }

    private function deliveryException(int $id, array $failed): \RuntimeException
    {
        $details = [];
        foreach ($failed as $key => $error) {
            $details[] = $key.': '.$error;
        }

        return new \RuntimeException('Request effect '.$id.' has unfinished delivery steps. Failed steps: '.implode('; ', $details));
    }
}
