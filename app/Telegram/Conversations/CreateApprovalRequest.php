<?php

namespace App\Telegram\Conversations;

use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\RequestApproval;
use App\Models\RequestAttachment;
use App\Models\RequestItem;
use App\Models\RequestTranche;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PdfImport\PaymentRequestPdfImportService;
use App\Concerns\ConversationHelpers;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class CreateApprovalRequest extends Conversation
{
    use ConversationHelpers;

    // Общие поля заявки
    public ?int    $projectId        = null;
    public ?string $currency         = null;
    public ?string $transaction_type = null;
    public ?string $supplier_name    = null;
    public ?string $supplier_inn     = null;
    public ?int    $supplier_id      = null;
    public ?string $deadlineDate     = null;
    public array   $approvers        = [];
    public array   $attachmentFileIds = [];
    public ?int    $editRequestId    = null;
    public int $editRevision = 1;
    public bool    $preserveImportedAttachments = false;

    // Транши и предоплата
    public bool    $isPrepayment      = false;
    public int     $totalTranches     = 0;
    public array   $tranches          = [];
    public ?float  $currentTrancheVal = null;
    public ?float  $currentTranchePercent = null;
    public ?string $currentTrancheDesc= null;
    public ?string $currentTrancheDate= null;

    // Текущий добавляемый товар
    public ?string $currentItemName  = null;
    public ?string $currentItemDesc  = null;
    public ?float  $currentItemPrice = null;
    public ?float  $currentItemQty   = null;

    // Список добавленных товаров: [['name','description','price_per_unit','quantity','total_price']]
    public array $items = [];

    /** A failed Telegram spinner acknowledgement must not abort the user's action. */
    protected function acknowledgeCallback(Nutgram $bot, mixed ...$options): void
    {
        try {
            $bot->answerCallbackQuery(...$options);
        } catch (ConnectException $exception) {
            // Do not log the request/exception: its URL can contain the bot token.
            Log::warning('telegram.callback_ack_failed', [
                'operation' => 'answerCallbackQuery',
                'curl_errno' => $exception->getHandlerContext()['errno'] ?? null,
            ]);
        }
    }

    // ── Старт ────────────────────────────────────────────────────────────────

    public function start(Nutgram $bot, ?string $mode = null)
    {
        if ($mode === 'pdf') {
            $this->askPdfUpload($bot);
            return;
        }

        if ($this->editRequestId) {
            $this->askProject($bot);
            return;
        }

        $this->askProject($bot);
    }

    protected function askPdfUpload(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $bot->sendMessage(
            __('bot.app_pdf_prompt', [], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handlePdfUpload');
    }

    public function handlePdfUpload(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;

        $doc = $bot->message()?->document;
        $mime = $doc?->mime_type ?? '';
        $fileName = $doc?->file_name ?? '';

        if (!$doc || (!str_contains($mime, 'pdf') && !str_ends_with(mb_strtolower($fileName), '.pdf'))) {
            $bot->sendMessage(__('bot.app_pdf_only', [], $lang), parse_mode: 'HTML');
            return;
        }

        $user = User::where('telegram_id', $bot->userId())->first();
        if (!$user) { $this->end(); return; }

        $projects = $this->availableProjectsForUser($user);
        if ($projects->isEmpty()) {
            $bot->sendMessage(__('bot.no_projects_access', [], $lang));
            $this->end();
            return;
        }

        $bot->sendMessage(__('bot.app_pdf_reading', [], $lang), parse_mode: 'HTML');
        $tempPath = null;

        try {
            $tempPath = $this->downloadTelegramDocument($bot, $doc);
            $analysis = app(PaymentRequestPdfImportService::class)->analyze($tempPath, $projects);

            $importIssue = $this->applyPdfAnalysis($analysis, $doc->file_id);
            if ($importIssue !== null) {
                $bot->sendMessage(__($importIssue, [], $lang), parse_mode: 'HTML');
                $this->askProject($bot);
                return;
            }

            foreach ($this->attachmentFileIds as &$attachment) {
                if ($attachment['file_id'] === $doc->file_id) {
                    $attachment['original_name'] = mb_substr($doc->file_name ?? '', 0, 255) ?: null;
                    $attachment['file_size'] = $doc->file_size ?? null;
                }
            }
            unset($attachment);
            $bot->sendMessage($this->pdfImportPreviewMessage($analysis, $lang), parse_mode: 'HTML');
            $this->sendApproversKeyboard($bot);
        } catch (\Throwable $e) {
            Log::warning('PDF import failed', ['user_id' => $user->id, 'exception_type' => get_class($e)]);
            $bot->sendMessage(__('bot.app_pdf_parse_failed', [], $lang), parse_mode: 'HTML');
            $this->askProject($bot);
        } finally {
            if ($tempPath && is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    protected function availableProjectsForUser(User $user): Collection
    {
        return app(\App\Services\ApprovalRequestCreation::class)->projects($user);
    }

    protected function downloadTelegramDocument(Nutgram $bot, object $doc): string
    {
        return app(\App\Services\TelegramFileCache::class)->temporaryCopy($doc->file_id);
    }

    protected function applyPdfAnalysis(array $analysis, string $telegramFileId): ?string
    {
        /** @var \App\Services\PdfImport\ParsedPaymentRequest $parsed */
        $parsed = $analysis['parsed'];
        $match = $analysis['match'];
        $project = $match['project'] ?? null;
        $supplierParty = $match['supplier_party'] ?? null;
        $amount = $parsed->suggestedPaymentAmount ?? $parsed->totalAmount;

        if (!$project) {
            return 'bot.app_pdf_project_not_found';
        }
        if (!$supplierParty) {
            return 'bot.app_pdf_supplier_not_found';
        }
        if (!$amount) {
            return 'bot.app_pdf_amount_not_found';
        }

        $supplier = $this->resolvePdfSupplier($supplierParty);
        $this->projectId = $project->id;
        $this->currency = $parsed->currency ?: 'UZS';
        $this->transaction_type = 'bank';
        $this->supplier_name = $supplier->name;
        $this->supplier_inn = $supplier->inn;
        $this->supplier_id = $supplier->id;
        $this->deadlineDate = null;
        $this->isPrepayment = false;
        $this->tranches = [];
        $this->items = [[
            'name' => $this->pdfItemName($parsed),
            'description' => $this->pdfItemDescription($parsed, $supplierParty),
            'price_per_unit' => $amount,
            'quantity' => 1,
            'total_price' => $amount,
        ]];
        $this->attachmentFileIds = [[
            'file_id' => $telegramFileId,
            'file_type' => 'pdf',
        ]];
        $this->preserveImportedAttachments = true;

        return null;
    }

    protected function resolvePdfSupplier(array $supplierParty): Supplier
    {
        $inn = isset($supplierParty['inn']) ? preg_replace('/\D+/', '', $supplierParty['inn']) : null;
        $name = trim((string) ($supplierParty['name'] ?? ''));
        $name = $name !== '' ? mb_substr($name, 0, 255) : 'Supplier from PDF';

        $supplier = $inn ? Supplier::where('inn', $inn)->first() : null;
        if (!$supplier) {
            $supplier = Supplier::where('name', $name)->first();
        }

        if ($supplier) {
            if (!$supplier->inn && $inn) {
                $supplier->update(['inn' => $inn]);
            }

            return $supplier;
        }

        return Supplier::create([
            'name' => $name,
            'inn' => $inn,
            'is_active' => true,
        ]);
    }

    protected function pdfItemName(\App\Services\PdfImport\ParsedPaymentRequest $parsed): string
    {
        $type = $parsed->documentType ?: 'PDF';
        $number = $parsed->documentNumber ? " №{$parsed->documentNumber}" : '';

        return mb_substr("Оплата по {$type}{$number}", 0, 255);
    }

    protected function pdfItemDescription(\App\Services\PdfImport\ParsedPaymentRequest $parsed, array $supplierParty): string
    {
        $parts = [];

        if ($parsed->documentDate) {
            $parts[] = "Дата документа: {$parsed->documentDate}";
        }
        if (!empty($supplierParty['name'])) {
            $parts[] = "Поставщик: {$supplierParty['name']}";
        }
        if (!empty($supplierParty['inn'])) {
            $parts[] = "ИНН поставщика: {$supplierParty['inn']}";
        }
        if ($parsed->totalAmount) {
            $parts[] = 'Сумма документа: ' . $this->formatPdfMoney($parsed->totalAmount, $parsed->currency);
        }
        if ($parsed->prepaymentPercent) {
            $parts[] = "Найдена предоплата: {$parsed->prepaymentPercent}%";
        }

        return implode("\n", $parts);
    }

    protected function pdfImportPreviewMessage(array $analysis, string $lang): string
    {
        /** @var \App\Services\PdfImport\ParsedPaymentRequest $parsed */
        $parsed = $analysis['parsed'];
        $match = $analysis['match'];
        $project = $match['project'];
        $supplier = $match['supplier_party'];
        $amount = $parsed->suggestedPaymentAmount ?? $parsed->totalAmount;

        $message = __('bot.app_pdf_ready_header', [], $lang) . "\n\n";
        $message .= __('bot.notify_project', ['name' => htmlspecialchars($project->name)], $lang) . "\n";
        $message .= __('bot.notify_supplier', ['name' => htmlspecialchars($supplier['name'] ?? 'N/A')], $lang) . "\n";
        if (!empty($supplier['inn'])) {
            $message .= '🧾 <b>ИНН поставщика:</b> ' . htmlspecialchars($supplier['inn']) . "\n";
        }
        if ($parsed->documentNumber) {
            $message .= '📄 <b>Документ:</b> ' . htmlspecialchars(($parsed->documentType ?: 'PDF') . ' №' . $parsed->documentNumber) . "\n";
        }
        if ($parsed->documentDate) {
            $message .= '📅 <b>Дата:</b> ' . htmlspecialchars($parsed->documentDate) . "\n";
        }
        if ($parsed->totalAmount) {
            $message .= '💰 <b>Сумма документа:</b> ' . $this->formatPdfMoney($parsed->totalAmount, $parsed->currency) . "\n";
        }
        if ($parsed->prepaymentPercent && $amount) {
            $message .= '💳 <b>К оплате по предоплате:</b> ' . $this->formatPdfMoney($amount, $parsed->currency) . " ({$parsed->prepaymentPercent}%)\n";
        } elseif ($amount) {
            $message .= '💳 <b>К оплате:</b> ' . $this->formatPdfMoney($amount, $parsed->currency) . "\n";
        }
        $message .= __('bot.app_pdf_deadline_empty', [], $lang) . "\n";

        return $message . "\n" . __('bot.app_pdf_ready_footer', [], $lang);
    }

    protected function formatPdfMoney(float $amount, ?string $currency): string
    {
        return number_format($amount, 2, '.', ' ') . ' ' . ($currency ?: 'UZS');
    }

    protected function askProject(Nutgram $bot)
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

        $bot->sendMessage(__('bot.app_title', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleProject');
    }

    public function handleProject(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (!$bot->isCallbackQuery()) { if ($this->isCancelled($bot)) return; $bot->sendMessage(__('bot.select_project_list', [], $lang)); return; }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') return;
        if (!str_starts_with($data, 'proj:')) return;

        $user = User::where('telegram_id', $bot->userId())->first();
        $projectId = (int) explode(':', $data)[1];
        if (!$user || !$this->availableProjectsForUser($user)->contains('id', $projectId)) {
            $this->acknowledgeCallback($bot, text: __('bot.no_projects_access', [], $lang), show_alert: true);
            return;
        }

        $this->projectId = $projectId;
        $this->acknowledgeCallback($bot);
        $project = Project::find($this->projectId);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🇺🇸 USD', callback_data: 'cur:USD'),
                InlineKeyboardButton::make('🇷🇺 RUB', callback_data: 'cur:RUB')
            )
            ->addRow(
                InlineKeyboardButton::make('🇪🇺 EUR', callback_data: 'cur:EUR'),
                InlineKeyboardButton::make('🇺🇿 UZS', callback_data: 'cur:UZS')
            );
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(
            __('bot.app_select_currency', ['project' => htmlspecialchars($project->name)], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleCurrency');
    }

    public function handleCurrency(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (!$bot->isCallbackQuery()) { if ($this->isCancelled($bot)) return; $bot->sendMessage(__('bot.select_currency_btns', [], $lang)); return; }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') return;
        if (!str_starts_with($data, 'cur:')) return;

        $this->currency = explode(':', $data)[1];
        $this->acknowledgeCallback($bot);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.app_cash_btn', [], $lang), callback_data: 'tx:cash'))
            ->addRow(InlineKeyboardButton::make(__('bot.app_bank_btn', [], $lang), callback_data: 'tx:bank'));
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(
            __('bot.app_select_payment', ['currency' => $this->currency], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleTransactionType');
    }

    public function handleTransactionType(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (!$bot->isCallbackQuery()) { if ($this->isCancelled($bot)) return; $bot->sendMessage(__('bot.select_from_btns', [], $lang)); return; }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') return;
        if (!str_starts_with($data, 'tx:')) return;

        $this->transaction_type = explode(':', $data)[1];
        $this->acknowledgeCallback($bot);

        $methodLabel = $this->transaction_type === 'cash'
            ? __('bot.app_method_cash', [], $lang)
            : __('bot.app_method_bank', [], $lang);

        $bot->sendMessage(
            __('bot.app_enter_supplier', ['method' => $methodLabel], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleSupplier');
    }

    public function handleSupplier(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data;

            // "Keep as new" — create supplier from previously entered name
            if ($data === 'sup:new') {
                $this->acknowledgeCallback($bot);
                $supplierRecord = Supplier::firstOrCreate(['name' => $this->supplier_name]);
                $this->supplier_inn = $supplierRecord->inn;
                $this->supplier_id  = $supplierRecord->id;
                $this->askDeadline($bot);
                return;
            }

            // Select existing supplier by ID
            if (str_starts_with($data, 'sup:')) {
                $id = (int) explode(':', $data)[1];
                $supplier = Supplier::find($id);
                if ($supplier) {
                    $this->supplier_name = $supplier->name;
                    $this->supplier_inn  = $supplier->inn;
                    $this->supplier_id   = $supplier->id;
                    $this->acknowledgeCallback($bot);
                    $bot->sendMessage(
                        __('bot.app_supplier_from_db', ['name' => htmlspecialchars($this->supplier_name)], $lang),
                        parse_mode: 'HTML'
                    );
                    $this->askDeadline($bot);
                    return;
                }
            }

            // Unknown callback — ignore
            return;
        }

        if ($this->isCancelled($bot)) return;
        $text = $bot->message()?->text;

        if (!$text) {
            $bot->sendMessage(__('bot.app_enter_supplier_txt', [], $lang));
            return;
        }

        // Поиск похожих в базе
        $suppliers = Supplier::where('name', 'LIKE', '%' . trim($text) . '%')->limit(5)->get();

        if ($suppliers->isNotEmpty()) {
            $keyboard = InlineKeyboardMarkup::make();
            foreach ($suppliers as $s) {
                $keyboard->addRow(InlineKeyboardButton::make("🏢 {$s->name}", callback_data: "sup:{$s->id}"));
            }
            $keyboard->addRow(InlineKeyboardButton::make(
                __('bot.app_supplier_keep_btn', ['text' => $text], $lang),
                callback_data: "sup:new"
            ));
            $this->withCancelButton($keyboard, $bot);

            $bot->sendMessage(
                __('bot.app_supplier_search', ['text' => $text], $lang),
                reply_markup: $keyboard
            );
            $this->supplier_name = trim($text);
            return;
        }

        // No matches — create directly
        $this->supplier_name = trim($text);
        $supplierRecord = Supplier::firstOrCreate(['name' => $this->supplier_name]);
        $this->supplier_inn = $supplierRecord->inn;
        $this->supplier_id  = $supplierRecord->id;

        $this->askDeadline($bot);
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
            __('bot.app_deadline_prompt', [], $lang),
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

            $this->acknowledgeCallback($bot);
            $this->deadlineDate = $date->format('Y-m-d H:i:s');
            $bot->sendMessage(
                __('bot.app_deadline_set', ['date' => $date->format('d.m.Y')], $lang),
                parse_mode: 'HTML'
            );
            $this->askNextItem($bot);
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

        $this->askNextItem($bot);
    }

    // ── Добавление товаров/услуг ──────────────────────────────────────────────

    protected function askNextItem(Nutgram $bot)
    {
        $lang    = BotLang::getLang($bot);
        $count   = count($this->items);
        $itemNum = $count + 1;

        $msg = $count === 0
            ? __('bot.app_items_first', ['num' => $itemNum], $lang)
            : __('bot.app_items_more', ['count' => $count, 'num' => $itemNum], $lang);

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
                $this->acknowledgeCallback($bot);
                $this->askSupplierAfterItems($bot);
                return;
            }
            if ($data === 'item:excel') {
                $this->acknowledgeCallback($bot);
                $bot->sendMessage(
                    __('bot.app_excel_prompt', [], $lang),
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
            __('bot.app_item_desc_prompt', ['name' => htmlspecialchars($this->currentItemName)], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleItemDesc');
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
                    'name'           => (string) $row['name'],
                    'description'    => (string) ($row['description'] ?? ''),
                    'quantity'       => (float) ($row['quantity'] ?? 1),
                    'price_per_unit' => (float) ($row['price'] ?? 0),
                    'total_price'    => (float) (($row['quantity'] ?? 1) * ($row['price'] ?? 0)),
                ];
            }

            $bot->sendMessage(
                __('bot.app_excel_success', ['count' => count($this->items)], $lang),
                parse_mode: 'HTML'
            );
            $this->askSupplierAfterItems($bot);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Excel import failed', ['exception_type' => get_class($e)]);
            $bot->sendMessage(__('bot.app_excel_error', [], $lang));
            $this->askNextItem($bot);
        } finally {
            if ($tempPath && is_file($tempPath)) unlink($tempPath);
        }
    }

    public function handleItemDesc(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;
        $text = trim($bot->message()?->text ?? '');
        $this->currentItemDesc = $text === '-' ? null : $text;

        $bot->sendMessage(
            __('bot.app_price_prompt', ['currency' => $this->currency], $lang),
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleItemPrice');
    }

    public function handleItemPrice(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;
        $value = $this->validatePositiveNumber($bot, $bot->message()?->text, __('bot.field_price', [], $lang));
        if ($value === null) return;

        if ($value > 1000000000 || round((float) $value, 2) !== (float) $value) {
            $bot->sendMessage(__('bot.invalid_number_value', ['value' => $value], $lang));
            return;
        }
        $this->currentItemPrice = $value;
        $bot->sendMessage(__('bot.app_qty_prompt', [], $lang), reply_markup: $this->cancelButton($bot));
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
        $total = round($this->currentItemPrice * $this->currentItemQty, 2);

        $this->items[] = [
            'name'          => $this->currentItemName,
            'description'   => $this->currentItemDesc,
            'price_per_unit'=> $this->currentItemPrice,
            'quantity'      => $this->currentItemQty,
            'total_price'   => $total,
        ];

        $bot->sendMessage(
            __('bot.app_item_added', [
                'name'     => htmlspecialchars($this->currentItemName),
                'price'    => number_format($this->currentItemPrice, 2, '.', ' '),
                'qty'      => $this->currentItemQty,
                'total'    => number_format($total, 2, '.', ' '),
                'currency' => $this->currency,
            ], $lang),
            parse_mode: 'HTML'
        );

        $this->currentItemName = $this->currentItemDesc = null;
        $this->currentItemPrice = null;
        $this->currentItemQty = null;

        $this->askNextItem($bot);
    }

    protected function askSupplierAfterItems(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.app_prepay_yes_btn', [], $lang), callback_data: 'prepay:yes'),
                InlineKeyboardButton::make(__('bot.app_prepay_no_btn', [], $lang),  callback_data: 'prepay:no')
            );
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(__('bot.app_prepay_prompt', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handlePrepayment');
    }

    public function handlePrepayment(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (!$bot->isCallbackQuery()) { if ($this->isCancelled($bot)) return; $bot->sendMessage(__('bot.select_from_btns', [], $lang)); return; }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') return;

        $this->acknowledgeCallback($bot);

        if ($data === 'prepay:no') {
            $this->isPrepayment = false;
            $this->sendApproversKeyboard($bot);
            return;
        }

        $this->isPrepayment = true;
        $bot->sendMessage(__('bot.app_tranches_count', [], $lang));
        $this->next('handleTranchesCount');
    }

    public function handleTranchesCount(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;
        $count = $this->validatePositiveNumber($bot, $bot->message()?->text, __('bot.field_tranches_count', [], $lang), integerOnly: true);
        if ($count === null) return;

        if ($count < 2) {
            $bot->sendMessage(__('bot.app_tranches_min', [], $lang));
            return;
        }

        $this->totalTranches = $count;
        $this->askNextTranche($bot);
    }

    protected function askNextTranche(Nutgram $bot)
    {
        $lang    = BotLang::getLang($bot);
        $current = count($this->tranches) + 1;

        $bot->sendMessage(
            __('bot.app_tranche_prompt', [
                'current'  => $current,
                'total'    => $this->totalTranches,
                'currency' => $this->currency,
            ], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleTrancheAmount');
    }

    public function handleTrancheAmount(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;
        $text = trim($bot->message()?->text ?? '');

        $percent = null;
        $amount  = null;

        if (str_ends_with($text, '%')) {
            $val = (float) rtrim($text, '%');
            if ($val <= 0 || $val > 100) {
                $bot->sendMessage(__('bot.app_tranche_pct_error', [], $lang));
                return;
            }
            $percent    = $val;
            $grandTotal = array_sum(array_column($this->items, 'total_price'));
            $amount     = round(($grandTotal * $percent) / 100, 2);
        } else {
            $val = $this->validatePositiveNumber($bot, $text, __('bot.field_tranche_amount', [], $lang));
            if ($val === null) return;
            if (round((float) $val, 2) !== (float) $val) {
                $bot->sendMessage(__('bot.invalid_number_value', ['value' => $val], $lang));
                return;
            }
            $amount = $val;
        }

        $this->currentTrancheVal     = $amount;
        $this->currentTranchePercent = $percent;

        $bot->sendMessage(
            __('bot.app_tranche_date', [], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleTrancheDate');
    }

    public function handleTrancheDate(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;
        $text = trim($bot->message()?->text ?? '');

        try {
            $date = \Carbon\Carbon::createFromFormat('d.m.Y', $text)->startOfDay();
            if ($date->isPast() && !$date->isToday()) {
                $bot->sendMessage(__('bot.date_past_error', [], $lang));
                return;
            }
            $this->currentTrancheDate = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $bot->sendMessage(__('bot.date_format_error', [], $lang));
            return;
        }

        $bot->sendMessage(
            __('bot.app_tranche_comment', [], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleTrancheComment');
    }

    public function handleTrancheComment(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($this->isCancelled($bot)) return;
        $text = $this->validateText($bot, $bot->message()?->text, __('bot.field_comment', [], $lang), 255);
        if ($text === null) return;

        $this->tranches[] = [
            'amount'  => $this->currentTrancheVal,
            'percent' => $this->currentTranchePercent ?? null,
            'due_date'=> $this->currentTrancheDate,
            'comment' => $text,
        ];

        if (count($this->tranches) < $this->totalTranches) {
            $this->askNextTranche($bot);
        } else {
            $grandTotal    = array_sum(array_column($this->items, 'total_price'));
            $tranchesTotal = array_sum(array_column($this->tranches, 'amount'));

            if (abs($grandTotal - $tranchesTotal) > 0.01) {
                $bot->sendMessage(
                    __('bot.app_tranches_mismatch', [
                        'tranches' => number_format($tranchesTotal, 2),
                        'total'    => number_format($grandTotal, 2),
                    ], $lang),
                    parse_mode: 'HTML'
                );
                $this->tranches = [];
                $this->askNextTranche($bot);
                return;
            }

            $bot->sendMessage(__('bot.app_tranches_done', [], $lang));
            $this->sendApproversKeyboard($bot);
        }
    }

    // ── Согласующие ───────────────────────────────────────────────────────────

    protected function sendApproversKeyboard(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $author = User::where('telegram_id', $bot->userId())->firstOrFail();
        $approvers = app(\App\Services\ApprovalRequestCreation::class)->approvers($this->projectId, $author->id)->whereNotIn('id', $this->approvers);


        if ($approvers->isEmpty() && empty($this->approvers)) {
            $bot->sendMessage(__('bot.app_no_approvers_at_all', [], $lang), parse_mode: 'HTML');
            $this->sendMenu($bot);
            $this->end();
            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($approvers as $user) {
            $label = htmlspecialchars($user->full_name ?? '');
            if ($user->title) $label .= " (" . htmlspecialchars($user->title) . ")";
            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: "apr:{$user->id}"));
        }

        if (!empty($this->approvers)) {
            $keyboard->addRow(InlineKeyboardButton::make(
                __('bot.app_approver_done_btn', [], $lang),
                callback_data: 'apr:done'
            ));
        }
        $this->withCancelButton($keyboard, $bot);

        $selected = '';
        if (!empty($this->approvers)) {
            $selected = "\n\n" . __('bot.app_approver_queue', [], $lang) . "\n";
            foreach ($this->approvers as $i => $approverId) {
                $u = User::find($approverId);
                $selected .= ($i + 1) . ". " . htmlspecialchars($u->full_name ?? '') .
                    ($u->title ? " (" . htmlspecialchars($u->title) . ")" : '') . "\n";
            }
        }

        $level = count($this->approvers) + 1;
        $bot->sendMessage(
            __('bot.app_approver_select', ['level' => $level], $lang) . $selected,
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleApprover');
    }

    public function handleApprover(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (!$bot->isCallbackQuery()) { if ($this->isCancelled($bot)) return; $bot->sendMessage(__('bot.select_from_btns', [], $lang)); return; }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') return;
        if (!str_starts_with($data, 'apr:')) return;

        $value = explode(':', $data)[1];
        $this->acknowledgeCallback($bot);

        if ($value === 'done') {
            if (empty($this->approvers)) {
                $bot->sendMessage(__('bot.app_approver_need_one', [], $lang));
                $this->sendApproversKeyboard($bot);
                return;
            }
            $this->askAttachments($bot);
            return;
        }

        $approverId = (int) $value;
        if (in_array($approverId, $this->approvers, true) || !$this->allowedApproverIds()->contains($approverId)) {
            $bot->sendMessage(__('bot.app_approver_already', [], $lang));
            $this->sendApproversKeyboard($bot);
            return;
        }

        $this->approvers[] = $approverId;
        $approver = User::find($approverId);
        $bot->sendMessage(__('bot.app_approver_added', [
            'level' => count($this->approvers),
            'name'  => htmlspecialchars($approver->full_name ?? ''),
        ], $lang));
        $this->sendApproversKeyboard($bot);
    }

    // ── Вложения ──────────────────────────────────────────────────────────────

    protected function askAttachments(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        // Reset cache-based attachment accumulator to avoid race conditions with media groups
        $initialAttachments = $this->preserveImportedAttachments ? $this->attachmentFileIds : [];
        Cache::put("attach:{$bot->userId()}", $initialAttachments, 3600);
        $this->attachmentFileIds = $initialAttachments;
        $this->preserveImportedAttachments = false;

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.app_skip_btn', [], $lang), callback_data: 'attach:skip'));
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(__('bot.app_attach_prompt', [], $lang), reply_markup: $keyboard);
        $this->next('handleAttachment');
    }

    public function handleAttachment(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data;
            if ($data === 'attach:skip') {
                $this->acknowledgeCallback($bot);
                // Sync from cache before proceeding (source of truth)
                $this->attachmentFileIds = Cache::get("attach:{$bot->userId()}", $this->attachmentFileIds);
                $this->showSummary($bot);
                return;
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
            $count    = count($this->attachmentFileIds);
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    __('bot.app_finish_btn', ['num' => $count], $lang),
                    callback_data: 'attach:skip'
                ));
            $this->withCancelButton($keyboard, $bot);
            $bot->sendMessage(__('bot.app_file_accepted', ['num' => $count], $lang), reply_markup: $keyboard);
            $this->next('handleAttachment');
        } else {
            $bot->sendMessage(__('bot.app_send_file_photo', [], $lang));
        }
    }

    // ── Итоговый просмотр ─────────────────────────────────────────────────────

    protected function showSummary(Nutgram $bot)
    {
        $lang    = BotLang::getLang($bot);
        $project    = Project::find($this->projectId);
        $methodLabel = $this->transaction_type === 'cash'
            ? __('bot.app_method_cash', [], $lang)
            : __('bot.app_method_bank', [], $lang);
        $grandTotal = array_sum(array_column($this->items, 'total_price'));

        $summary  = __('bot.app_summary_header', [], $lang) . "\n\n";
        $summary .= __('bot.notify_project',   ['name' => htmlspecialchars($project->name)], $lang) . "\n";
        $summary .= __('bot.app_summary_currency', ['currency' => $this->currency], $lang) . "\n";
        $summary .= __('bot.app_summary_method',   ['method' => $methodLabel], $lang) . "\n";
        $summary .= __('bot.notify_supplier',  ['name' => htmlspecialchars($this->supplier_name)], $lang) . "\n";
        if ($this->deadlineDate) {
            $summary .= __('bot.notify_deadline',  ['date' => \Carbon\Carbon::parse($this->deadlineDate)->format('d.m.Y')], $lang) . "\n";
        }
        $summary .= "\n";
        $summary .= __('bot.app_summary_items', ['count' => count($this->items)], $lang) . "\n";

        foreach ($this->items as $i => $item) {
            $summary .= ($i + 1) . ". <b>" . htmlspecialchars($item['name']) . "</b>";
            if (!empty($item['description'])) {
                $summary .= " — " . htmlspecialchars($item['description']);
            }
            $summary .= "\n   " . number_format($item['price_per_unit'], 2, '.', ' ') . " × {$item['quantity']} = " .
                "<b>" . number_format($item['total_price'], 2, '.', ' ') . " {$this->currency}</b>\n";
        }

        $summary .= "\n" . __('bot.notify_total', [
            'total'    => number_format($grandTotal, 2, '.', ' '),
            'currency' => $this->currency,
        ], $lang) . "\n";
        $summary .= __('bot.app_summary_files', ['count' => count($this->attachmentFileIds)], $lang) . "\n\n";
        $summary .= __('bot.app_summary_route', [], $lang) . "\n";

        foreach ($this->approvers as $i => $approverId) {
            $u = User::find($approverId);
            $summary .= ($i + 1) . ". " . htmlspecialchars($u->full_name ?? '') .
                ($u->title ? " (" . htmlspecialchars($u->title) . ")" : '') . "\n";
        }

        if ($this->isPrepayment && !empty($this->tranches)) {
            $summary .= "\n" . __('bot.app_summary_tranches', [], $lang) . "\n";
            foreach ($this->tranches as $i => $t) {
                $date = \Carbon\Carbon::parse($t['due_date'])->format('d.m.Y');
                $summary .= ($i + 1) . ". {$date} — <b>" . number_format($t['amount'], 2, '.', ' ') . " {$this->currency}</b>";
                if ($t['percent']) $summary .= " ({$t['percent']}%)";
                $summary .= "\n   💬 <i>" . htmlspecialchars($t['comment']) . "</i>\n";
            }
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.app_confirm_btn', [], $lang), callback_data: 'confirm:yes'),
                InlineKeyboardButton::make(__('bot.app_cancel_btn', [], $lang),  callback_data: 'global:cancel')
            );

        $bot->sendMessage($summary, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleConfirm');
    }

    // ── Сохранение ────────────────────────────────────────────────────────────

    public function handleConfirm(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (!$bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) return;
            $bot->sendMessage(__('bot.press_any_button', [], $lang));
            return;
        }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') return;
        if ($data !== 'confirm:yes') return;

        $this->acknowledgeCallback($bot);

        // A double-tap or retried webhook must not create the request twice.
        if (!$this->claimCallbackOnce($bot, 'app_confirm')) return;
        try { $bot->editMessageReplyMarkup(reply_markup: null); } catch (\Exception) {}

        $applicant = User::where('telegram_id', $bot->userId())->first();
        if (!$applicant) {
            $bot->sendMessage(__('bot.app_user_not_found', [], $lang));
            $this->end();
            return;
        }
        if (!$this->availableProjectsForUser($applicant)->contains('id', $this->projectId)
            || collect($this->approvers)->diff($this->allowedApproverIds())->isNotEmpty()) {
            $this->releaseCallbackClaim($bot, 'app_confirm');
            $bot->sendMessage(__('bot.app_save_failed', [], $lang));
            return;
        }

        $grandTotal = array_sum(array_column($this->items, 'total_price'));
        $firstName  = $this->items[0]['name'] ?? 'Request';

        try {
            $request = app(\App\Services\ApprovalRequestCreation::class)->create($applicant, [
                'type' => 'approval', 'project_id' => $this->projectId, 'currency' => $this->currency,
                'transaction_type' => $this->transaction_type, 'supplier_id' => $this->supplier_id,
                'supplier_name' => $this->supplier_name, 'supplier_inn' => $this->supplier_inn,
                'deadline_date' => $this->deadlineDate ? substr($this->deadlineDate, 0, 10) : null, 'items' => $this->items,
                'approvers' => $this->approvers, 'tranches' => $this->isPrepayment ? array_map(fn ($t) => array_merge($t, ['due_date' => substr($t['due_date'], 0, 10)]), $this->tranches) : [],
            ], array_map(fn ($a) => ['file_type' => $a['file_type'], 'telegram_file_id' => $a['file_id'], 'original_name' => $a['original_name'] ?? null, 'file_size' => $a['file_size'] ?? null], $this->attachmentFileIds), $this->editRequestId, $this->editRevision);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Approval request save failed: " . $e->getMessage());
            $this->releaseCallbackClaim($bot, 'app_confirm');
            $bot->sendMessage(__('bot.app_save_failed', [], $lang), parse_mode: 'HTML');
            return;
        }

        $grandTotal = (float) $request->total_price;
        $bot->sendMessage(
            __('bot.app_created', [
                'id'       => $request->id,
                'total'    => number_format($grandTotal, 2, '.', ' '),
                'currency' => $this->currency,
            ], $lang),
            parse_mode: 'HTML',
            reply_markup: \App\Telegram\Menu::main($applicant)
        );

        $this->end();
    }

    // ── Уведомление первого согласующего ─────────────────────────────────────

    protected function eligibleApproversQuery()
    {
        return User::query()
            ->where('account_status', 'approved')->where('is_active', true)
            ->whereHas('roleRelation', fn($q) => $q->whereIn('name', ['admin', 'approver', 'supplier', 'superadmin']));
    }

    protected function allowedApproverIds(): \Illuminate\Support\Collection
    {
        return app(\App\Services\ApprovalRequestCreation::class)->approvers($this->projectId)->pluck('id');
    }
}
