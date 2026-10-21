<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\RequestItem;
use App\Models\Supplier;
use App\Models\User;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ConvertSupplyRequest extends Conversation
{
    use ConversationHelpers;

    public ?int $requestId = null;

    public ?int $revision = null;

    public array $itemQuantities = [];

    public ?int $projectId = null;

    public ?string $currency = null;

    public ?string $supplierName = null;

    public ?string $supplierInn = null;

    public ?int $supplierId = null;

    public ?string $deadlineDate = null;

    public ?string $factualDeliveryDate = null;

    public ?string $transactionType = null;

    public ?int $editingItemId = null;

    public array $approvers = [];

    public array $itemPrices = [];

    public ?int $pricingItemId = null;

    public bool $isPrepayment = false;

    public int $totalTranches = 0;

    public array $tranches = [];

    public ?float $currentTrancheVal = null;

    public ?float $currentTranchePercent = null;

    public ?string $currentTrancheDate = null;

    // ── Start ─────────────────────────────────────────────────────────────────

    public function start(Nutgram $bot, int $requestId = 0)
    {
        try {
            $actor = User::where('telegram_id', $bot->userId())->firstOrFail();
            $request = app(\App\Services\SupplyWorkflow::class)->pending($requestId, $actor);
            $this->requestId = $request->id;
            $this->projectId = $request->project_id;
            $this->revision = $request->revision;
            $this->showItemsForEdit($bot, $request->load(['items', 'applicant', 'project']));
        } catch (\Throwable) {
            $bot->sendMessage('Заявка недоступна или уже обработана.');
            $this->end();
        }
    }

    // ── Step 1: Edit quantities ───────────────────────────────────────────────

    protected function showItemsForEdit(Nutgram $bot, ApprovalRequest $request)
    {
        $lang = BotLang::getLang($bot);

        $summary = __('bot.conv_header', ['id' => $request->id], $lang)."\n\n";
        $summary .= __('bot.notify_project', ['name' => htmlspecialchars($request->project?->name ?? 'N/A')], $lang)."\n";
        $summary .= __('bot.conv_initiator', ['name' => htmlspecialchars($request->applicant->full_name ?? '')], $lang)."\n";
        $summary .= __('bot.conv_applicant_phone', ['phone' => $request->applicant->phone ?? __('bot.cb_phone_not_set', [], $lang)], $lang)."\n\n";
        $summary .= __('bot.conv_items_header', [], $lang)."\n";

        foreach ($request->items as $i => $item) {
            $summary .= ($i + 1).'. <b>'.htmlspecialchars($item->name)."</b> — {$item->quantity}";
            if ($item->description) {
                $summary .= "\n   💬 ".htmlspecialchars($item->description);
            }
            $summary .= "\n";
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($request->items as $item) {
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    '✏️ '.mb_strimwidth($item->name, 0, 22, '…')." ({$item->quantity})",
                    callback_data: "edit_qty:{$item->id}"
                )
            );
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.conv_continue_btn', [], $lang), callback_data: 'edit_qty:done'));
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage($summary, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleEditChoice');
    }

    public function handleEditChoice(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.conv_use_btns', [], $lang));

            return;
        }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') {
            return;
        }
        $bot->answerCallbackQuery();

        if ($data === 'edit_qty:done') {
            $this->askPrices($bot);

            return;
        }

        if (str_starts_with($data, 'edit_qty:')) {
            $itemId = (int) explode(':', $data)[1];
            $item = RequestItem::find($itemId);
            if (! $item || $item->approval_request_id !== $this->requestId) {
                $bot->sendMessage(__('bot.conv_item_not_found', [], $lang));

                return;
            }
            $this->editingItemId = $itemId;
            $bot->sendMessage(
                __('bot.conv_item_edit', [
                    'name' => htmlspecialchars($item->name),
                    'qty' => $item->quantity,
                ], $lang),
                parse_mode: 'HTML',
                reply_markup: $this->cancelButton($bot)
            );
            $this->next('handleNewQty');
        }
    }

    public function handleNewQty(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $request = $this->pendingRequest($bot);
        if (! $request) {
            return;
        }
        $value = $this->validatePositiveNumber($bot, $bot->message()?->text, 'Количество');
        if ($value === null) {
            return;
        }
        $item = $request->items->firstWhere('id', $this->editingItemId);
        if (! $item) {
            return;
        }
        $this->itemQuantities[$item->id] = $value;
        $item->quantity = $value;
        $this->editingItemId = null;
        $this->showItemsForEdit($bot, $request);
    }

    // ── Step 2: Unit prices ───────────────────────────────────────────────────

    protected function askPrices(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $request = $this->pendingRequest($bot);
        if (! $request) {
            return;
        }

        foreach ($request->items as $item) {
            if (! isset($this->itemPrices[$item->id])) {
                $this->pricingItemId = $item->id;

                $filled = count($this->itemPrices);
                $total = $request->items->count();

                $bot->sendMessage(
                    __('bot.conv_price_prompt', [
                        'filled' => $filled,
                        'total' => $total,
                        'name' => htmlspecialchars($item->name),
                        'qty' => $item->quantity,
                    ], $lang),
                    parse_mode: 'HTML',
                    reply_markup: $this->cancelButton($bot)
                );
                $this->next('handleItemPrice');

                return;
            }
        }

        $this->askCurrency($bot);
    }

    public function handleItemPrice(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $request = $this->pendingRequest($bot);
        if (! $request) {
            return;
        }
        $value = $this->validatePositiveNumber($bot, $bot->message()?->text, 'Цена');
        if ($value === null || ! $request->items->contains('id', $this->pricingItemId)) {
            return;
        }
        $this->itemPrices[$this->pricingItemId] = $value;
        $this->pricingItemId = null;
        $this->askPrices($bot);
    }

    // ── Step 3: Currency ──────────────────────────────────────────────────────

    protected function askCurrency(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
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

        $bot->sendMessage(__('bot.conv_select_currency', [], $lang), reply_markup: $keyboard);
        $this->next('handleCurrency');
    }

    public function handleCurrency(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.conv_select_btns', [], $lang));

            return;
        }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') {
            return;
        }
        if (! str_starts_with($data, 'cur:')) {
            return;
        }

        $this->currency = explode(':', $data)[1];
        $bot->answerCallbackQuery();
        $this->askSupplier($bot);
    }

    // ── Step 4: Supplier ──────────────────────────────────────────────────────

    protected function askSupplier(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $bot->sendMessage(
            __('bot.conv_enter_supplier', [], $lang),
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleSupplier');
    }

    public function handleSupplier(Nutgram $bot)
    {
        if (! $this->pendingRequest($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);

        if ($bot->isCallbackQuery()) {
            $data = $bot->callbackQuery()->data;

            // Persist a new supplier only when the complete conversion commits.
            if ($data === 'sup:new') {
                $bot->answerCallbackQuery();
                $this->supplierId = null;
                $this->supplierInn = null;
                $this->askDeadline($bot);

                return;
            }

            // Select existing supplier by ID
            if (str_starts_with($data, 'sup:')) {
                $id = (int) explode(':', $data)[1];
                $supplier = Supplier::find($id);
                if ($supplier) {
                    $this->supplierName = $supplier->name;
                    $this->supplierInn = $supplier->inn;
                    $this->supplierId = $supplier->id;
                    $bot->answerCallbackQuery();
                    $bot->sendMessage(
                        __('bot.conv_supplier_from_db', ['name' => htmlspecialchars($this->supplierName)], $lang),
                        parse_mode: 'HTML'
                    );
                    $this->askDeadline($bot);

                    return;
                }
            }

            // Unknown callback — ignore
            return;
        }

        if ($this->isCancelled($bot)) {
            return;
        }
        $text = $bot->message()?->text;

        if (! $text) {
            $bot->sendMessage(__('bot.conv_enter_sup_text', [], $lang));

            return;
        }

        $suppliers = Supplier::where('name', 'LIKE', '%'.trim($text).'%')->limit(5)->get();

        if ($suppliers->isNotEmpty()) {
            $keyboard = InlineKeyboardMarkup::make();
            foreach ($suppliers as $s) {
                $keyboard->addRow(InlineKeyboardButton::make("🏢 {$s->name}", callback_data: "sup:{$s->id}"));
            }
            $keyboard->addRow(InlineKeyboardButton::make(
                __('bot.conv_supplier_keep', ['text' => $text], $lang),
                callback_data: 'sup:new'
            ));
            $this->withCancelButton($keyboard, $bot);

            $bot->sendMessage(
                __('bot.conv_supplier_search', ['text' => $text], $lang),
                reply_markup: $keyboard
            );
            $this->supplierName = trim($text);

            return;
        }

        // No matches — create directly
        $this->supplierName = trim($text);
        $supplierRecord = Supplier::firstOrCreate(['name' => $this->supplierName]);
        $this->supplierInn = $supplierRecord->inn;
        $this->supplierId = $supplierRecord->id;

        $this->askDeadline($bot);
    }

    protected function askDeadline(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $bot->sendMessage(
            __('bot.conv_deadline_prompt', [], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleDeadline');
    }

    public function handleDeadline(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $text = trim($bot->message()?->text ?? '');

        try {
            $date = \Carbon\Carbon::createFromFormat('d.m.Y', $text)->startOfDay();
            if ($date->isPast() && ! $date->isToday()) {
                $bot->sendMessage(__('bot.date_past_error', [], $lang));

                return;
            }
            $this->deadlineDate = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $bot->sendMessage(__('bot.date_format_error', [], $lang));

            return;
        }

        $this->askFactualDelivery($bot);
    }

    protected function askFactualDelivery(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $bot->sendMessage(
            __('bot.conv_factual_prompt', [], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleFactualDelivery');
    }

    public function handleFactualDelivery(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $text = trim($bot->message()?->text ?? '');

        try {
            $date = \Carbon\Carbon::createFromFormat('d.m.Y', $text)->startOfDay();
            if ($date->isPast() && ! $date->isToday()) {
                $bot->sendMessage(__('bot.date_past_error', [], $lang));

                return;
            }
            $this->factualDeliveryDate = $date->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $bot->sendMessage(__('bot.date_format_error', [], $lang));

            return;
        }

        $this->askTransactionType($bot);
    }

    // ── Step 5: Payment method ────────────────────────────────────────────────

    protected function askTransactionType(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.app_cash_btn', [], $lang), callback_data: 'tx:cash'))
            ->addRow(InlineKeyboardButton::make(__('bot.app_bank_btn', [], $lang), callback_data: 'tx:bank'));
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(__('bot.conv_payment_method', [], $lang), reply_markup: $keyboard);
        $this->next('handleTransactionType');
    }

    public function handleTransactionType(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.conv_select_btns', [], $lang));

            return;
        }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') {
            return;
        }
        if (! str_starts_with($data, 'tx:')) {
            return;
        }

        $this->transactionType = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        $this->askPrepayment($bot);
    }

    protected function askPrepayment(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.app_prepay_yes_btn', [], $lang), callback_data: 'prepay:yes'),
                InlineKeyboardButton::make(__('bot.app_prepay_no_btn', [], $lang), callback_data: 'prepay:no')
            );
        $this->withCancelButton($keyboard, $bot);

        $bot->sendMessage(__('bot.conv_prepay_prompt', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handlePrepayment');
    }

    public function handlePrepayment(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.select_from_btns', [], $lang));

            return;
        }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') {
            return;
        }

        $bot->answerCallbackQuery();

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
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $count = $this->validatePositiveNumber($bot, $bot->message()?->text, __('bot.field_tranches_count', [], $lang), integerOnly: true);
        if ($count === null) {
            return;
        }

        if ($count < 2) {
            $bot->sendMessage(__('bot.app_tranches_min', [], $lang));

            return;
        }

        $this->totalTranches = $count;
        $this->askNextTranche($bot);
    }

    protected function askNextTranche(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $current = count($this->tranches) + 1;
        $bot->sendMessage(
            __('bot.app_tranche_prompt', [
                'current' => $current,
                'total' => $this->totalTranches,
                'currency' => $this->currency,
            ], $lang),
            parse_mode: 'HTML',
            reply_markup: $this->cancelButton($bot)
        );
        $this->next('handleTrancheAmount');
    }

    public function handleTrancheAmount(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $text = trim($bot->message()?->text ?? '');

        $request = $this->pendingRequest($bot);
        if (! $request) {
            return;
        }
        $grandTotal = $request->items->sum('total_price');

        $percent = null;
        $amount = null;

        if (str_ends_with($text, '%')) {
            $val = (float) rtrim($text, '%');
            if ($val <= 0 || $val > 100) {
                $bot->sendMessage(__('bot.app_tranche_pct_error', [], $lang));

                return;
            }
            $percent = $val;
            $amount = round(($grandTotal * $percent) / 100, 2);
        } else {
            $val = $this->validatePositiveNumber($bot, $text, __('bot.field_tranche_amount', [], $lang));
            if ($val === null) {
                return;
            }
            $amount = $val;
        }

        $this->currentTrancheVal = $amount;
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
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $text = trim($bot->message()?->text ?? '');

        try {
            $date = \Carbon\Carbon::createFromFormat('d.m.Y', $text)->startOfDay();
            if ($date->isPast() && ! $date->isToday()) {
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
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $text = $this->validateText($bot, $bot->message()?->text, __('bot.field_comment', [], $lang), 255);
        if ($text === null) {
            return;
        }

        $this->tranches[] = [
            'amount' => $this->currentTrancheVal,
            'percent' => $this->currentTranchePercent ?? null,
            'due_date' => $this->currentTrancheDate,
            'comment' => $text,
        ];

        if (count($this->tranches) < $this->totalTranches) {
            $this->askNextTranche($bot);
        } else {
            $request = $this->pendingRequest($bot);
            if (! $request) {
                return;
            }
            $grandTotal = $request->items->sum('total_price');
            $tranchesTotal = array_sum(array_column($this->tranches, 'amount'));

            if (abs($grandTotal - $tranchesTotal) > 0.01) {
                $bot->sendMessage(
                    __('bot.app_tranches_mismatch', [
                        'tranches' => number_format($tranchesTotal, 2),
                        'total' => number_format($grandTotal, 2),
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

    // ── Step 6: Approvers ─────────────────────────────────────────────────────

    protected function sendApproversKeyboard(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $request = $this->pendingRequest($bot);
        if (! $request) {
            return;
        }
        $actor = User::where('telegram_id', $bot->userId())->firstOrFail();
        $approvers = app(\App\Services\ApprovalParticipantPolicy::class)->eligible($request->project_id, [$request->applicant_id, $actor->id])
            ->whereNotIn('users.id', $this->approvers)->get();

        if ($approvers->isEmpty() && empty($this->approvers)) {
            $bot->sendMessage(__('bot.app_no_approvers_at_all', [], $lang), parse_mode: 'HTML');
            $this->sendMenu($bot);
            $this->end();

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($approvers as $user) {
            $label = htmlspecialchars($user->full_name ?? '');
            if ($user->title) {
                $label .= ' ('.htmlspecialchars($user->title).')';
            }
            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: "apr:{$user->id}"));
        }
        if (! empty($this->approvers)) {
            $keyboard->addRow(InlineKeyboardButton::make(__('bot.conv_approver_done_btn', [], $lang), callback_data: 'apr:done'));
        }
        $this->withCancelButton($keyboard, $bot);

        $selected = '';
        if (! empty($this->approvers)) {
            $selected = "\n\n".__('bot.app_approver_queue', [], $lang)."\n";
            foreach ($this->approvers as $i => $id) {
                $u = User::find($id);
                $selected .= ($i + 1).'. '.htmlspecialchars($u->full_name ?? '').
                    ($u->title ? " ({$u->title})" : '')."\n";
            }
        }

        $bot->sendMessage(
            __('bot.conv_approver_select', ['level' => count($this->approvers) + 1], $lang).$selected,
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleApprover');
    }

    public function handleApprover(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.conv_select_btns', [], $lang));

            return;
        }
        $data = $bot->callbackQuery()->data;
        if ($data === 'global:cancel') {
            return;
        }
        if (! str_starts_with($data, 'apr:')) {
            return;
        }

        $value = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        if ($value === 'done') {
            if (empty($this->approvers)) {
                $bot->sendMessage(__('bot.conv_need_approver', [], $lang));
                $this->sendApproversKeyboard($bot);

                return;
            }
            // A double-tap or retried webhook must not run the conversion twice.
            if (! $this->claimCallbackOnce($bot, 'conv_done')) {
                return;
            }
            try {
                $bot->editMessageReplyMarkup(reply_markup: null);
            } catch (\Exception) {
            }
            $this->convert($bot);

            return;
        }

        $approverId = (int) $value;
        if (in_array($approverId, $this->approvers)) {
            $bot->sendMessage(__('bot.conv_already_added', [], $lang));
            $this->sendApproversKeyboard($bot);

            return;
        }
        $this->approvers[] = $approverId;
        $approver = User::find($approverId);
        $bot->sendMessage(__('bot.conv_approver_added', [
            'level' => count($this->approvers),
            'name' => htmlspecialchars($approver->full_name ?? ''),
        ], $lang));
        $this->sendApproversKeyboard($bot);
    }

    // ── Final: Convert ────────────────────────────────────────────────────────

    protected function convert(Nutgram $bot)
    {
        $request = $this->pendingRequest($bot);
        if (! $request) {
            return;
        }
        try {
            $request = app(\App\Services\SupplyWorkflow::class)->convert($request->id, User::where('telegram_id', $bot->userId())->firstOrFail(), [
                'revision' => $this->revision, 'currency' => $this->currency, 'supplier_id' => $this->supplierId,
                'supplier_name' => $this->supplierName, 'transaction_type' => $this->transactionType,
                'deadline_from_supplier' => $this->deadlineDate ? substr($this->deadlineDate, 0, 10) : null,
                'factual_delivery_date' => $this->factualDeliveryDate ? substr($this->factualDeliveryDate, 0, 10) : null,
                'approvers' => $this->approvers,
                'items' => $request->items->map(fn ($i) => ['id' => $i->id, 'quantity' => $i->quantity, 'price_per_unit' => $i->price_per_unit])->all(),
                'tranches' => $this->isPrepayment ? array_map(fn ($t) => array_merge($t, ['due_date' => substr($t['due_date'], 0, 10)]), $this->tranches) : [],
            ]);
        } catch (\Throwable $e) {
            $this->releaseCallbackClaim($bot, 'conv_done');
            $bot->sendMessage($e instanceof \Illuminate\Validation\ValidationException ? implode("\n", \Illuminate\Support\Arr::flatten($e->errors())) : 'Заявка изменилась или доступ отозван.');

            return;
        }
        $this->end();
        $bot->sendMessage('Заявка #'.$request->id.' передана на финансовое согласование.');
    }

    protected function pendingRequest(Nutgram $bot): ?ApprovalRequest
    {
        try {
            abort_unless($this->revision, 409);
            $actor = User::where('telegram_id', $bot->userId())->firstOrFail();
            $request = app(\App\Services\SupplyWorkflow::class)->pending($this->requestId, $actor, $this->revision)->load(['items', 'applicant', 'project']);
            foreach ($request->items as $item) {
                $item->quantity = $this->itemQuantities[$item->id] ?? $item->quantity;
                $item->price_per_unit = $this->itemPrices[$item->id] ?? $item->price_per_unit;
                $item->total_price = round($item->quantity * ($item->price_per_unit ?? 0), 2);
            }

            return $request;
        } catch (\Throwable) {
            $bot->sendMessage('Заявка изменилась или доступ отозван. Откройте её заново.');
            $this->end();

            return null;
        }
    }
}
