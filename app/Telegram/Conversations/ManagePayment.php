<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\User;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ManagePayment extends Conversation
{
    use ConversationHelpers;

    public ?int $requestId = null;

    public ?int $currentTrancheId = null;

    public ?string $promisedDate = null;

    public string $fromFilter = 'pending';

    public function start(Nutgram $bot, int $requestId = 0, string $fromFilter = 'pending')
    {
        $this->requestId = $requestId;
        $this->fromFilter = $fromFilter;
        $request = $this->loadAuthorizedRequest($bot, ['tranches', 'project', 'applicant']);
        if (! $request) {
            return;
        }

        $this->showTranches($bot, $request);
    }

    protected function showTranches(Nutgram $bot, ApprovalRequest $request)
    {
        $lang = BotLang::getLang($bot);

        $summary = __('bot.pay_title', ['id' => $request->id], $lang)."\n\n";
        $summary .= __('bot.pay_project', ['name' => $request->project?->name ?? 'N/A'], $lang)."\n";
        $summary .= __('bot.pay_supplier', ['name' => htmlspecialchars($request->supplier_name)], $lang)."\n";
        $summary .= __('bot.pay_total', [
            'total' => number_format($request->total_price, 2, '.', ' '),
            'currency' => $request->currency,
        ], $lang)."\n";

        if ($request->payment_commitment_date) {
            $summary .= __('bot.pay_commitment_date', ['date' => $request->payment_commitment_date->format('d.m.Y')], $lang)."\n";
            if ($request->payment_commitment_comment) {
                $summary .= '💬 <i>'.htmlspecialchars($request->payment_commitment_comment)."</i>\n";
            }
        }
        $summary .= "\n";
        $summary .= __('bot.pay_tranches_header', [], $lang)."\n";

        $keyboard = InlineKeyboardMarkup::make();

        foreach ($request->tranches as $i => $t) {
            $statusIcon = match ($t->status) {
                'paid' => '✅', 'failed' => '❌', default => '⏳'
            };
            $date = $t->due_date ? $t->due_date->format('d.m.Y') : '—';
            $statusLabel = match ($t->status) {
                'paid' => __('bot.pay_tranche_paid', [], $lang),
                'failed' => __('bot.pay_tranche_failed', [], $lang),
                default => '',
            };
            $summary .= ($t->promised_date ? 'Обещано: '.$t->promised_date->format('d.m.Y')."\n" : '').($i + 1).". {$statusIcon} {$date} — ".number_format($t->amount, 2, '.', ' ')." {$request->currency}{$statusLabel}\n";

            if ($t->status !== 'paid') {
                $keyboard->addRow(InlineKeyboardButton::make(
                    __('bot.pay_tranche_pay_btn', [
                        'num' => $i + 1,
                        'amount' => number_format($t->amount, 2, '.', ' '),
                    ], $lang),
                    callback_data: "pay_tranche:{$t->id}"
                ));
            }
        }

        $commitBtnLabel = ($request->transaction_type === 'cash')
            ? __('bot.pay_commit_cash_btn', [], $lang)
            : __('bot.pay_commit_bank_btn', [], $lang);

        $keyboard->addRow(InlineKeyboardButton::make($commitBtnLabel, callback_data: 'pay:commit'));
        if ($request->tranches->contains('status', 'pending')) {
            $keyboard->addRow(InlineKeyboardButton::make(__('bot.pay_reject_btn', [], $lang), callback_data: 'pay:reject'));
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.pay_download_pdf_btn', [], $lang), callback_data: 'pay:download_pdf'));
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_back_to_list_btn', [], $lang), callback_data: 'pay:back'));

        $bot->sendMessage($summary, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleChoice');
    }

    public function handleChoice(Nutgram $bot)
    {
        if (! $bot->isCallbackQuery()) {
            $lang = BotLang::getLang($bot);
            $bot->sendMessage(__('bot.pay_select_tranche', [], $lang));

            return;
        }

        $data = $bot->callbackQuery()->data;
        $lang = BotLang::getLang($bot);

        if ($data === 'pay:back') {
            $bot->answerCallbackQuery();
            FinancePanel::begin($bot, data: [$this->fromFilter]);
            $this->end();

            return;
        }

        $request = $this->loadAuthorizedRequest($bot, ['tranches', 'project', 'applicant']);
        if (! $request) {
            return;
        }

        if ($data === 'pay:download_pdf') {
            $bot->answerCallbackQuery();
            if ($request->pdf_url) {
                $path = \Illuminate\Support\Facades\Storage::disk('local')->path($request->pdf_url);
                if (file_exists($path)) {
                    $bot->sendDocument(
                        \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make(fopen($path, 'r')),
                        caption: __('bot.cb_pdf_caption', ['id' => $request->id], $lang)
                    );
                } else {
                    $bot->sendMessage(__('bot.pay_pdf_not_found', [], $lang));
                }
            } else {
                $bot->sendMessage(__('bot.pay_pdf_not_generated', [], $lang));
            }
            $this->showTranches($bot, $request);

            return;
        }

        if ($data === 'pay:commit') {
            $bot->answerCallbackQuery();
            $prompt = 'Укажите обещанную дату для неоплаченных траншей (дд.мм.гггг). Согласованные сроки останутся прежними.';

            $bot->sendMessage($prompt, parse_mode: 'HTML', reply_markup: $this->cancelButton($bot));
            $this->next('handleCommitDate');

            return;
        }

        if ($data === 'pay:reject') {
            $bot->answerCallbackQuery();
            $bot->sendMessage(
                __('bot.pay_reject_reason', [], $lang),
                parse_mode: 'HTML',
                reply_markup: $this->cancelButton($bot)
            );
            $this->next('handleRejectPaymentReason');

            return;
        }

        if (str_starts_with($data, 'pay_tranche:')) {
            $this->currentTrancheId = (int) explode(':', $data)[1];
            $bot->answerCallbackQuery();

            $tranche = $request->tranches()
                ->where('status', '!=', 'paid')
                ->find($this->currentTrancheId);
            if (! $tranche) {
                $bot->sendMessage(__('bot.pay_request_not_found', [], $lang));
                $this->showTranches($bot, $request);

                return;
            }
            $bot->sendMessage(
                __('bot.pay_proof_prompt', [
                    'amount' => number_format($tranche->amount, 2, '.', ' '),
                    'currency' => $request->currency,
                ], $lang),
                parse_mode: 'HTML',
                reply_markup: $this->cancelButton($bot)
            );
            $this->next('handleProof');
        }
    }

    public function handleProof(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }

        $message = $bot->message();
        $fileId = null;

        if ($message?->document) {
            $fileId = $message->document->file_id;
        } elseif ($message?->photo) {
            $fileId = end($message->photo)->file_id;
        }

        $lang = BotLang::getLang($bot);

        if (! $fileId) {
            $bot->sendMessage(__('bot.pay_send_proof', [], $lang));

            return;
        }

        $request = $this->loadAuthorizedRequest($bot, ['applicant', 'tranches']);
        if (! $request) {
            return;
        }

        $tranche = $request->tranches()
            ->where('status', '!=', 'paid')
            ->find($this->currentTrancheId);
        if (! $tranche) {
            $bot->sendMessage(__('bot.pay_request_not_found', [], $lang));
            $this->start($bot, $this->requestId, $this->fromFilter);

            return;
        }
        try {
            [$request, $tranche] = app(\App\Services\TrancheWorkflow::class)->update(
                User::where('telegram_id', $bot->userId())->firstOrFail(), $request->id, $tranche->id,
                ['status' => 'paid'], telegramProof: $fileId
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            $bot->sendMessage(implode("\n", \Illuminate\Support\Arr::flatten($e->errors())));

            return;
        }
        $allPaid = $request->status === 'paid';

        $bot->sendMessage(__('bot.pay_tranche_paid_msg', [
            'amount' => number_format($tranche->amount, 2, '.', ' '),
            'currency' => $request->currency,
        ], $lang));

        $this->start($bot, $request->id, $this->fromFilter);
    }

    public function handleCommitDate(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $request = $this->loadAuthorizedRequest($bot);
        if (! $request) {
            return;
        }
        $text = trim($bot->message()?->text ?? '');
        try {
            \Illuminate\Support\Facades\Validator::make(['date' => $text], ['date' => 'required|date_format:d.m.Y|after_or_equal:today'])->validate();
            $this->promisedDate = \Carbon\Carbon::createFromFormat('d.m.Y', $text)->toDateString();
        } catch (\Throwable) {
            $bot->sendMessage('Введите сегодняшнюю или будущую дату в формате дд.мм.гггг.');

            return;
        }
        $bot->sendMessage('Укажите причину изменения обещанной даты (обязательно).');
        $this->next('handleCommitComment');
    }

    public function handleCommitComment(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $reason = $this->validateText($bot, $bot->message()?->text, 'Причина', 1000);
        if ($reason === null || ! $this->promisedDate) {
            return;
        }
        try {
            $actor = User::where('telegram_id', $bot->userId())->firstOrFail();
            app(\App\Services\TrancheWorkflow::class)->promise($actor, $this->requestId, $this->promisedDate, $reason);
        } catch (\Throwable) {
            $bot->sendMessage('Не удалось изменить срок. Проверьте доступ и состояние заявки.');

            return;
        }
        $bot->sendMessage('Обещанная дата сохранена с причиной для неоплаченных траншей.');
        $this->start($bot, $this->requestId, $this->fromFilter);
    }

    public function handleRejectPaymentReason(Nutgram $bot)
    {
        if ($this->isCancelled($bot)) {
            return;
        }
        $lang = BotLang::getLang($bot);
        $reason = $this->validateText($bot, $bot->message()?->text, __('bot.pay_reject_reason_field', [], $lang), 1000);
        if ($reason === null) {
            return;
        }

        $request = $this->loadAuthorizedRequest($bot, ['applicant']);
        if (! $request) {
            return;
        }

        try {
            $changed = app(\App\Services\TrancheWorkflow::class)->rejectUnpaid(
                User::where('telegram_id', $bot->userId())->firstOrFail(), $request->id, $reason
            );
        } catch (\Throwable) {
            $bot->sendMessage(__('bot.pay_reject_unavailable', [], $lang));

            return;
        }
        $bot->sendMessage(__($changed ? 'bot.pay_reject_sent' : 'bot.pay_reject_already', [], $lang));
        $this->start($bot, $this->requestId, $this->fromFilter);
    }

    protected function loadAuthorizedRequest(Nutgram $bot, array $with = []): ?ApprovalRequest
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        $lang = $user?->lang ?? BotLang::getLang($bot);

        if (! $user || ! $user->isApproved() || (! $user->isAccountant() && ! $user->isTreasurer())) {
            $bot->sendMessage(__('bot.fin_no_access', [], $lang));
            $this->end();

            return null;
        }

        $query = app(\App\Services\ApprovalRequestAccess::class)->financialQuery(
            ApprovalRequest::query()->with($with), $user
        );

        $request = $query->find($this->requestId);
        if (! $request) {
            $bot->sendMessage(__('bot.pay_request_not_found', [], $lang));
            $this->end();
        }

        return $request;
    }
}
