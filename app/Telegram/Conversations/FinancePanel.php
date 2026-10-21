<?php

namespace App\Telegram\Conversations;

use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\RequestTranche;
use App\Models\User;
use App\Concerns\ConversationHelpers;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class FinancePanel extends Conversation
{
    use ConversationHelpers;

    public function start(Nutgram $bot, string $filter = '')
    {
        $user = $this->authorizedUser($bot);
        if (!$user) return;

        if ($filter === 'pending') {
            $this->showPendingRequests($bot);
            return;
        }

        $this->showMainMenu($bot, $user);
    }

    protected function showMainMenu(Nutgram $bot, User $user)
    {
        $lang = $user->lang ?? 'ru';

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.fin_pending_btn', [], $lang), callback_data: 'fin:pending'),
                InlineKeyboardButton::make(__('bot.fin_paid_btn', [], $lang), callback_data: 'fin:paid')
            )
            ->addRow(InlineKeyboardButton::make(__('bot.fin_export_btn', [], $lang), callback_data: 'fin:export'))
            ->addRow(InlineKeyboardButton::make(__('bot.fin_home_btn', [], $lang), callback_data: 'fin:back_home'));

        $bot->sendMessage(
            __('bot.fin_title', [], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleMainChoice');
    }

    public function handleMainChoice(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) return;
        $user = $this->authorizedUser($bot);
        if (!$user) return;

        $data = $bot->callbackQuery()->data;
        $bot->answerCallbackQuery();

        if ($data === 'fin:back_home') {
            $this->sendMenu($bot);
            $this->end();
            return;
        }

        if ($data === 'fin:pending') {
            $this->showPendingRequests($bot);
            return;
        }

        if ($data === 'fin:paid') {
            $this->showPaidRequests($bot);
            return;
        }

        if ($data === 'fin:export') {
            $lang = $user->lang ?? 'ru';
            $filename = 'export_tranches_' . time() . '.xlsx';
            $path = 'exports/' . $filename;

            \Maatwebsite\Excel\Facades\Excel::store(new \App\Exports\TranchesExport($user), $path, 'local');

            $url = \Illuminate\Support\Facades\Storage::disk('local')->path($path);

            $bot->sendDocument(
                \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make(fopen($url, 'r')),
                caption: __('bot.fin_export_caption', [], $lang)
            );

            @unlink($url);

            $this->showMainMenu($bot, $user);
            return;
        }
    }

    protected function showPendingRequests(Nutgram $bot)
    {
        $user = $this->authorizedUser($bot);
        if (!$user) return;

        $lang = $user->lang ?? 'ru';
        $requests = $this->financeRequestsQuery($user)
            ->where('status', 'approved')
            ->whereHas('tranches', function($q) {
                $q->where('status', 'pending');
            })
            ->with(['tranches', 'project', 'applicant'])
            ->orderBy('deadline_date')
            ->limit(10)
            ->get();

        if ($requests->isEmpty()) {
            $bot->sendMessage(__('bot.fin_all_paid', [], $lang));
            $this->showMainMenu($bot, $user);
            return;
        }

        $msg = __('bot.fin_pending_header', [], $lang) . "\n\n";
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($requests as $req) {
            $projectName = $req->project?->name ?? 'N/A';
            $pendingTranches = $req->tranches->where('status', 'pending')->count();
            $totalTranches = $req->tranches->count();

            $msg .= "🔹 <b>#{$req->id}</b> | {$projectName}\n";
            $msg .= "   💰 " . number_format($req->total_price, 2, '.', ' ') . " {$req->currency}\n";
            $msg .= __('bot.fin_tranches_count', ['pending' => $pendingTranches, 'total' => $totalTranches], $lang) . "\n";
            if ($req->deadline_date) {
                $msg .= __('bot.fin_deadline', ['date' => $req->deadline_date->format('d.m.Y')], $lang) . "\n";
            }
            $msg .= "\n";

            $keyboard->addRow(InlineKeyboardButton::make(
                __('bot.fin_manage_btn', ['id' => $req->id], $lang),
                callback_data: "pay_manage:{$req->id}"
            ));
        }

        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_back_btn', [], $lang), callback_data: 'fin:back'));

        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleRequestChoice');
    }

    public function handleRequestChoice(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) return;
        $user = $this->authorizedUser($bot);
        if (!$user) return;

        $data = $bot->callbackQuery()->data;
        $bot->answerCallbackQuery();

        if ($data === 'fin:back') {
            $this->showMainMenu($bot, $user);
            return;
        }

        if (str_starts_with($data, 'pay_manage:')) {
            $id = (int) explode(':', $data)[1];
            ManagePayment::begin($bot, data: [$id, 'pending']);
            $this->end();
            return;
        }
    }

    protected function showPaidRequests(Nutgram $bot)
    {
        $user = $this->authorizedUser($bot);
        if (!$user) return;

        $lang = $user->lang ?? 'ru';

        $requests = $this->financeRequestsQuery($user)
            ->where('status', 'paid')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        if ($requests->isEmpty()) {
            $bot->sendMessage(__('bot.fin_empty_history', [], $lang));
            $this->showMainMenu($bot, $user);
            return;
        }

        $msg = __('bot.fin_paid_header', [], $lang) . "\n\n";
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($requests as $req) {
            $msg .= "🔹 <b>#{$req->id}</b> | " . number_format($req->total_price, 2, '.', ' ') . " {$req->currency}\n";
            $msg .= __('bot.fin_paid_at', ['date' => $req->updated_at->format('d.m.Y')], $lang) . "\n\n";

            $keyboard->addRow(InlineKeyboardButton::make(
                __('bot.fin_protocol_btn', ['id' => $req->id], $lang),
                callback_data: "pay:pdf_{$req->id}"
            ));
        }

        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_back_btn', [], $lang), callback_data: 'fin:back'));

        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handlePaidChoice');
    }

    public function handlePaidChoice(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) return;
        $user = $this->authorizedUser($bot);
        if (!$user) return;

        $data = $bot->callbackQuery()->data;
        $bot->answerCallbackQuery();

        if ($data === 'fin:back') {
            $this->showMainMenu($bot, $user);
            return;
        }

        if (str_starts_with($data, 'pay:pdf_')) {
            $id = (int) explode('_', $data)[1];
            $request = $this->financeRequestsQuery($user)
                ->where('status', 'paid')
                ->find($id);
            $lang = BotLang::getLang($bot);
            if ($request && $request->pdf_url) {
                $path = \Illuminate\Support\Facades\Storage::disk('local')->path($request->pdf_url);
                if (file_exists($path)) {
                    $bot->sendDocument(
                        \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make(fopen($path, 'r')),
                        caption: __('bot.cb_pdf_caption', ['id' => $request->id], $lang)
                    );
                }
            }
            $this->showPaidRequests($bot);
            return;
        }
    }

    protected function authorizedUser(Nutgram $bot): ?User
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if (!$user || !$user->isApproved() || (!$user->isAccountant() && !$user->isTreasurer())) {
            $bot->sendMessage(__('bot.fin_no_access', [], $user?->lang ?? BotLang::getLang($bot)));
            $this->end();

            return null;
        }

        return $user;
    }

    protected function financeRequestsQuery(User $user)
    {
        $query = ApprovalRequest::query()->where('type', '!=', 'management_document');

        if ($user->role === 'accountant') {
            $query->where('transaction_type', 'bank');
        }

        if (!in_array($user->role, ['admin', 'superadmin'], true)) {
            $query->whereIn('project_id', $user->projects()->pluck('projects.id'));
        }

        return $query;
    }
}
