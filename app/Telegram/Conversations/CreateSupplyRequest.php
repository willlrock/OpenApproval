<?php

namespace App\Telegram\Conversations;

use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\RequestItem;
use App\Models\User;
use App\Concerns\ConversationHelpers;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class CreateSupplyRequest extends Conversation
{
    use ConversationHelpers;

    public ?int   $projectId        = null;
    public ?string $currentItemName = null;
    public ?string $currentItemDesc = null;
    public ?float $currentItemQty   = null;
    public array  $items            = [];
    public ?string $deadlineDate     = null;
    public array  $attachmentFileIds = [];

    // ── Старт ────────────────────────────────────────────────────────────────

    public function start(Nutgram $bot)
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if (!$user) { $this->end(); return; }

        $lang = $user->lang ?? 'ru';

        if ($user->role === 'admin' || $user->role === 'superadmin') {
            $projects = Project::all();
        } else {
            $projects = $user->projects;
        }

        if ($projects->isEmpty()) {
            $bot->sendMessage(__('bot.no_projects_access', [], $lang));
            $this->end();
            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($projects as $project) {
            $keyboard->addRow(InlineKeyboardButton::make($project->name, callback_data: "proj:{$project->id}"));
        }
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(__('bot.sup_title', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleProject');
    }

    public function handleProject(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (!$bot->isCallbackQuery()) { $bot->sendMessage(__('bot.select_project_list', [], $lang)); return; }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') return;
        if (!str_starts_with($data, 'proj:')) return;

        $user = User::where('telegram_id', $bot->userId())->first();
        $projectId = (int) explode(':', $data)[1];
        $hasProjectAccess = $user && (
            in_array($user->role, ['admin', 'superadmin'], true)
                ? Project::whereKey($projectId)->where('is_active', true)->exists()
                : $user->projects()->whereKey($projectId)->where('projects.is_active', true)->exists()
        );
        if (!$hasProjectAccess) {
            $bot->answerCallbackQuery(text: __('bot.no_projects_access', [], $lang), show_alert: true);
            return;
        }

        $this->projectId = $projectId;
        $bot->answerCallbackQuery();

        $this->askNextItem($bot);
    }

    // ── Добавление позиций ───────────────────────────────────────────────────

    protected function askNextItem(Nutgram $bot)
    {
        $lang    = BotLang::getLang($bot);
        $count   = count($this->items);
        $itemNum = $count + 1;
        $project = Project::find($this->projectId);

        $msg = $count === 0
            ? __('bot.sup_first_item', ['project' => $project->name, 'num' => $itemNum], $lang)
            : __('bot.sup_more_items', ['count' => $count, 'num' => $itemNum], $lang);

        $keyboard = InlineKeyboardMarkup::make();
        if ($count > 0) {
            $keyboard->addRow(InlineKeyboardButton::make(
                __('bot.app_items_done_btn', ['count' => $count], $lang),
                callback_data: 'item:done'
            ));
        } else {
            $keyboard->addRow(InlineKeyboardButton::make(
                __('bot.app_excel_btn', [], $lang),
                callback_data: 'item:excel'
            ));
        }
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleItemName');
    }

    public function handleItemName(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data;
            if ($data === 'item:done' && count($this->items) > 0) {
                $bot->answerCallbackQuery();
                $this->askDeadline($bot);
                return;
            }
            if ($data === 'item:excel') {
                $bot->answerCallbackQuery();
                $bot->sendMessage(
                    __('bot.sup_excel_prompt', [], $lang),
                    parse_mode: 'HTML',
                    reply_markup: $this->cancelButton($bot)
                );
                $this->next('handleExcelUpload');
                return;
            }
            return;
        }

        if ($this->isCancelled($bot)) return;
        $validated = $this->validateText($bot, $bot->message()?->text, __('bot.field_item_name', [], $lang), 255);
        if ($validated === null) return;

        $this->currentItemName = $validated;
        $bot->sendMessage(
            __('bot.sup_item_desc_prompt', ['name' => htmlspecialchars($this->currentItemName)], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleItemDesc');
    }

    public function handleItemDesc(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;
        $text = trim($bot->message()?->text ?? '');
        $this->currentItemDesc = $text === '-' ? null : $text;

        $bot->sendMessage(__('bot.sup_qty_prompt', [], $lang), reply_markup: $this->cancelButton($bot));
        $this->next('handleItemQty');
    }

    public function handleItemQty(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;
        $value = $this->validatePositiveNumber($bot, $bot->message()?->text, __('bot.field_qty', [], $lang));
        if ($value === null) return;

        if ($value > 100000000 || round((float) $value, 3) !== (float) $value) {
            $bot->sendMessage(__('bot.invalid_number_value', ['value' => $value], $lang));
            return;
        }
        $this->currentItemQty = $value;

        $this->items[] = [
            'name'        => $this->currentItemName,
            'description' => $this->currentItemDesc,
            'quantity'    => $this->currentItemQty,
        ];

        $bot->sendMessage(
            __('bot.sup_item_added', ['name' => htmlspecialchars($this->currentItemName), 'qty' => $this->currentItemQty], $lang),
            parse_mode: 'HTML'
        );

        $this->currentItemName = $this->currentItemDesc = null;
        $this->currentItemQty  = null;

        $this->askNextItem($bot);
    }

    public function handleExcelUpload(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;

        $doc = $bot->message()?->document;
        if (!$doc) {
            $bot->sendMessage(__('bot.app_send_as_doc', [], $lang));
            return;
        }

        $tempPath = null;
        try {
            $reader = \App\Imports\ApprovalItemsImport::readerType($doc->file_name);
            $tempPath = app(\App\Services\TelegramFileCache::class)->temporaryCopy($doc->file_id);

            $import = new \App\Imports\ApprovalItemsImport();
            $data = \Maatwebsite\Excel\Facades\Excel::toArray($import, $tempPath, null, $reader);

            if (empty($data[0])) {
                $bot->sendMessage(__('bot.app_excel_empty', [], $lang));
                $this->askNextItem($bot);
                return;
            }

            foreach ($data[0] as $row) {
                if (empty($row['name'])) continue;

                $this->items[] = [
                    'name'        => (string) $row['name'],
                    'description' => (string) ($row['description'] ?? ''),
                    'quantity'    => (float) ($row['quantity'] ?? 1),
                ];
            }

            $bot->sendMessage(
                __('bot.app_excel_success', ['count' => count($this->items)], $lang),
                parse_mode: 'HTML'
            );
            $this->askDeadline($bot);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Excel supply import failed', ['exception_type' => get_class($e)]);
            $bot->sendMessage(__('bot.app_excel_error', [], $lang));
            $this->askNextItem($bot);
        } finally {
            if ($tempPath && is_file($tempPath)) unlink($tempPath);
        }
    }

    protected function askDeadline(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.app_deadline_today',    [], $lang), callback_data: 'dl_today'),
                InlineKeyboardButton::make(__('bot.app_deadline_tomorrow', [], $lang), callback_data: 'dl_tomorrow')
            )
            ->addRow(
                InlineKeyboardButton::make(__('bot.app_deadline_15d', [], $lang), callback_data: 'dl_15d'),
                InlineKeyboardButton::make(__('bot.app_deadline_1m',  [], $lang), callback_data: 'dl_1m')
            );
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(
            __('bot.sup_deadline_prompt', [], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleDeadline');
    }

    public function handleDeadline(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data;
            if ($data === 'global:cancel') return;

            $date = match ($data) {
                'dl_today'    => \Carbon\Carbon::today(),
                'dl_tomorrow' => \Carbon\Carbon::tomorrow(),
                'dl_15d'      => \Carbon\Carbon::today()->addDays(15),
                'dl_1m'       => \Carbon\Carbon::today()->addMonth(),
                default       => null,
            };
            if ($date === null) return;

            $bot->answerCallbackQuery();
            $this->deadlineDate = $date->format('Y-m-d H:i:s');
            $bot->sendMessage(
                __('bot.app_deadline_set', ['date' => $date->format('d.m.Y')], $lang),
                parse_mode: 'HTML'
            );
            $this->showSummary($bot);
            return;
        }

        if ($this->isCancelled($bot)) return;
        $text = trim($bot->message()?->text ?? '');

        try {
            $date = \Carbon\Carbon::createFromFormat('d.m.Y', $text)->startOfDay();
            if ($date->isPast() && !$date->isToday()) {
                $bot->sendMessage(__('bot.date_past_error', [], $lang));
                return;
            }
            $this->deadlineDate = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $bot->sendMessage(__('bot.date_format_error', [], $lang));
            return;
        }

        $this->showSummary($bot);
    }

    protected function showSummary(Nutgram $bot)
    {
        $lang    = BotLang::getLang($bot);
        $project = Project::find($this->projectId);

        $summary  = __('bot.sup_summary_header', [], $lang) . "\n\n";
        $summary .= __('bot.notify_project', ['name' => htmlspecialchars($project->name)], $lang) . "\n";
        $summary .= __('bot.notify_deadline', ['date' => \Carbon\Carbon::parse($this->deadlineDate)->format('d.m.Y')], $lang) . "\n\n";
        $summary .= __('bot.app_summary_items', ['count' => count($this->items)], $lang) . "\n";

        foreach ($this->items as $i => $item) {
            $summary .= ($i + 1) . ". <b>" . htmlspecialchars($item['name']) . "</b> × {$item['quantity']}";
            if (!empty($item['description'])) {
                $summary .= "\n   💬 " . htmlspecialchars($item['description']);
            }
            $summary .= "\n";
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.sup_attach_btn', [], $lang), callback_data: 'supply:attach'))
            ->addRow(
                InlineKeyboardButton::make(__('bot.app_confirm_btn', [], $lang), callback_data: 'confirm:yes'),
                InlineKeyboardButton::make(__('bot.app_cancel_btn', [], $lang),  callback_data: 'global:cancel')
            );

        $bot->sendMessage($summary, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleSummaryAction');
    }

    public function handleSummaryAction(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (!$bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) return;
            $bot->sendMessage(__('bot.press_any_button', [], $lang));
            return;
        }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') return;

        $bot->answerCallbackQuery();

        if ($data === 'supply:attach') {
            $this->askAttachments($bot);
            return;
        }

        if ($data === 'confirm:yes') {
            // A double-tap or retried webhook must not create the request twice.
            if (!$this->claimCallbackOnce($bot, 'sup_confirm')) return;
            try { $bot->editMessageReplyMarkup(reply_markup: null); } catch (\Exception) {}
            $this->saveRequest($bot);
        }
    }

    protected function askAttachments(Nutgram $bot)
    {
        $lang  = BotLang::getLang($bot);

        // Sync from cache (source of truth for concurrent media group messages)
        $cacheKey = "attach:{$bot->userId()}";
        $this->attachmentFileIds = Cache::get($cacheKey, $this->attachmentFileIds);
        $count = count($this->attachmentFileIds);

        if ($count === 0) {
            // Initialize cache on first call
            Cache::put($cacheKey, [], 3600);
        }

        $keyboard = InlineKeyboardMarkup::make();
        if ($count > 0) {
            $keyboard->addRow(InlineKeyboardButton::make(
                __('bot.sup_attach_done_btn', ['count' => $count], $lang),
                callback_data: 'attach:done'
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.sup_skip_btn', [], $lang), callback_data: 'attach:done'));
        $this->withCancelButton($keyboard, $bot);

        $msg = $count === 0
            ? __('bot.sup_attach_first', [], $lang)
            : __('bot.sup_attach_more', ['count' => $count], $lang);

        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleAttachment');
    }

    public function handleAttachment(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data;
            if ($data === 'attach:done') {
                $bot->answerCallbackQuery();
                // Sync from cache before proceeding (source of truth)
                $this->attachmentFileIds = Cache::get("attach:{$bot->userId()}", $this->attachmentFileIds);
                $this->showSummary($bot);
            }
            return;
        }

        if ($this->isCancelled($bot)) return;

        $message  = $bot->message();
        $fileId   = null;
        $fileType = 'unknown';
        $fileName = null;
        $fileSize = null;

        if ($message?->document) {
            $fileId   = $message->document->file_id;
            $fileName = mb_substr($message->document->file_name ?? '', 0, 255) ?: null;
            $fileSize = $message->document->file_size ?? null;
            $mime     = $message->document->mime_type ?? '';
            $fileType = str_contains($mime, 'pdf') ? 'pdf' : 'document';
        } elseif ($message?->photo) {
            $photos   = $message->photo;
            $photo = end($photos);
            $fileId = $photo->file_id;
            $fileSize = $photo->file_size ?? null;
            $fileType = 'jpg';
        }

        if ($fileId) {
            // Use cache with lock to atomically append — prevents race condition with media groups
            $cacheKey = "attach:{$bot->userId()}";
            Cache::lock("{$cacheKey}:lock", 5)->block(3, function () use ($cacheKey, $fileId, $fileType, $fileName, $fileSize) {
                $attachments = Cache::get($cacheKey, []);
                $attachments[] = ['file_id' => $fileId, 'file_type' => $fileType, 'original_name' => $fileName, 'file_size' => $fileSize];
                Cache::put($cacheKey, $attachments, 3600);
            });

            $this->attachmentFileIds = Cache::get($cacheKey, []);
            $this->askAttachments($bot);
        } else {
            $bot->sendMessage(__('bot.app_send_file_photo', [], $lang));
        }
    }

    public function handleConfirm(Nutgram $bot)
    {
        $this->handleSummaryAction($bot);
    }

    protected function saveRequest(Nutgram $bot)
    {
        $lang      = BotLang::getLang($bot);
        $applicant = User::where('telegram_id', $bot->userId())->first();
        if (!$applicant) {
            $bot->sendMessage(__('bot.app_user_not_found', [], $lang));
            $this->end();
            return;
        }
        $hasProjectAccess = in_array($applicant->role, ['admin', 'superadmin'], true)
            ? Project::whereKey($this->projectId)->where('is_active', true)->exists()
            : $applicant->projects()->whereKey($this->projectId)->where('projects.is_active', true)->exists();
        if (!$hasProjectAccess) {
            $this->releaseCallbackClaim($bot, 'sup_confirm');
            $bot->sendMessage(__('bot.no_projects_access', [], $lang));
            $this->end();
            return;
        }

        $firstName = $this->items[0]['name'] ?? 'Supply Request';

        try {
            $request = app(\App\Services\ApprovalRequestCreation::class)->create($applicant, [
                'type' => 'supply', 'project_id' => $this->projectId, 'deadline_date' => $this->deadlineDate ? substr($this->deadlineDate, 0, 10) : null,
                'items' => $this->items,
            ], array_map(fn ($a) => ['file_type' => $a['file_type'], 'telegram_file_id' => $a['file_id'], 'original_name' => $a['original_name'] ?? null, 'file_size' => $a['file_size'] ?? null], $this->attachmentFileIds));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Supply request save failed: " . $e->getMessage());
            $this->releaseCallbackClaim($bot, 'sup_confirm');
            $bot->sendMessage(__('bot.app_save_failed', [], $lang), parse_mode: 'HTML');
            return;
        }

        $attachCount  = count($this->attachmentFileIds);
        $attachLine   = $attachCount > 0 ? __('bot.sup_attach_line', ['count' => $attachCount], $lang) : '';

        $bot->sendMessage(
            __('bot.sup_created', [
                'id'          => $request->id,
                'items'       => count($this->items),
                'attach_line' => $attachLine,
            ], $lang),
            parse_mode: 'HTML',
            reply_markup: \App\Telegram\Menu::main($applicant)
        );

        $this->end();
    }

    // ── Уведомление директора снабжения ──────────────────────────────────────

}
