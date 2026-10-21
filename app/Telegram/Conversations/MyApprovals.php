<?php

namespace App\Telegram\Conversations;

use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\RequestApproval;
use App\Models\User;
use App\Concerns\ConversationHelpers;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class MyApprovals extends Conversation
{
    use ConversationHelpers;

    public function start(Nutgram $bot)
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if (!$user) {
            $bot->sendMessage(__('bot.user_not_found_start', [], 'ru'));
            $this->end();
            return;
        }

        $lang = $user->lang ?? 'ru';

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.ma_filter_active', [], $lang), callback_data: 'ma:active'),
                InlineKeyboardButton::make(__('bot.ma_filter_history', [], $lang), callback_data: 'ma:history')
            );

        $bot->sendMessage(__('bot.ma_title', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleFilter');
    }

    public function handleFilter(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $lang = BotLang::getLang($bot);
            $bot->sendMessage(__('bot.select_filter_btns', [], $lang));
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === 'ma:back') {
            $bot->answerCallbackQuery();
            $this->start($bot);
            return;
        }

        if (!str_starts_with($data, 'ma:')) return;

        $filter = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        $user = User::where('telegram_id', $bot->userId())->first();
        $lang = $user->lang ?? 'ru';

        if ($filter === 'active') {
            $this->showActive($bot, $user, $lang);
        } elseif ($filter === 'history') {
            $this->showHistory($bot, $user, $lang);
        }
    }

    protected function showActive(Nutgram $bot, User $user, string $lang)
    {
        $requests = ApprovalRequest::with(['project', 'applicant'])
            ->where('status', 'pending_approval')
            ->whereHas('approvals', function ($q) use ($user) {
                $q->where('approver_id', $user->id)
                  ->where('status', 'pending');
            })
            ->whereColumn('expected_approval_level', '=',
                ApprovalRequest::raw('(SELECT level FROM request_approvals WHERE request_approvals.approval_request_id = approval_requests.id AND request_approvals.approver_id = ' . (int) $user->id . ' AND request_approvals.status = \'pending\' LIMIT 1)')
            )
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        if ($requests->isEmpty()) {
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(__('bot.ma_back_btn', [], $lang), callback_data: 'ma:back'));
            $bot->sendMessage(__('bot.ma_no_active', [], $lang), reply_markup: $keyboard);
            $this->next('handleFilter');
            return;
        }

        $msg = __('bot.ma_active_header', ['count' => $requests->count()], $lang) . "\n\n";

        foreach ($requests as $req) {
            $projectName = $req->project?->name ?? 'N/A';
            $applicant = $req->applicant?->full_name ?? 'N/A';
            $amount = $req->total_price
                ? number_format($req->total_price, 2, '.', ' ') . ' ' . $req->currency
                : '—';

            $msg .= "<b>#{$req->id}</b> | ⏳\n";
            $msg .= "📁 {$projectName} | 📌 {$req->name}\n";
            $msg .= "👤 {$applicant}\n";
            $msg .= "💰 {$amount} | 📅 {$req->created_at->format('d.m.Y')}\n\n";
        }

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($requests->take(5) as $req) {
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    __('bot.ma_detail_btn', ['id' => $req->id], $lang),
                    callback_data: "ma_detail:{$req->id}"
                )
            );
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.ma_back_btn', [], $lang), callback_data: 'ma:back'));

        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleDetail');
    }

    protected function showHistory(Nutgram $bot, User $user, string $lang)
    {
        $approvals = RequestApproval::with(['approvalRequest.project', 'approvalRequest.applicant'])
            ->where('approver_id', $user->id)
            ->whereIn('status', ['approved', 'rejected'])
            ->orderByDesc('action_date')
            ->limit(20)
            ->get();

        if ($approvals->isEmpty()) {
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(__('bot.ma_back_btn', [], $lang), callback_data: 'ma:back'));
            $bot->sendMessage(__('bot.ma_no_history', [], $lang), reply_markup: $keyboard);
            $this->next('handleFilter');
            return;
        }

        $msg = __('bot.ma_history_header', ['count' => $approvals->count()], $lang) . "\n\n";

        foreach ($approvals as $approval) {
            $req = $approval->approvalRequest;
            if (!$req) continue;

            $projectName = $req->project?->name ?? 'N/A';
            $applicant = $req->applicant?->full_name ?? 'N/A';
            $amount = $req->total_price
                ? number_format($req->total_price, 2, '.', ' ') . ' ' . $req->currency
                : '—';
            $statusLabel = $approval->status === 'approved'
                ? __('bot.ma_status_approved', [], $lang)
                : __('bot.ma_status_rejected', [], $lang);
            $date = $approval->action_date
                ? $approval->action_date->format('d.m.Y H:i')
                : '—';

            $msg .= "<b>#{$req->id}</b> | {$statusLabel}\n";
            $msg .= "📁 {$projectName} | 📌 {$req->name}\n";
            $msg .= "👤 {$applicant} | 💰 {$amount}\n";
            $msg .= "📅 {$date}";
            if ($approval->comment) {
                $msg .= "\n💬 <i>{$approval->comment}</i>";
            }
            $msg .= "\n\n";
        }

        $keyboard = InlineKeyboardMarkup::make();
        $shown = 0;
        foreach ($approvals as $approval) {
            if ($shown >= 5) break;
            if (!$approval->approvalRequest) continue;
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    __('bot.ma_detail_btn', ['id' => $approval->approvalRequest->id], $lang),
                    callback_data: "ma_detail:{$approval->approvalRequest->id}"
                )
            );
            $shown++;
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.ma_back_btn', [], $lang), callback_data: 'ma:back'));

        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleDetail');
    }

    public function handleDetail(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            $this->end();
            return;
        }

        $data = $bot->callbackQuery()->data;

        if ($data === 'ma:back') {
            $bot->answerCallbackQuery();
            $this->start($bot);
            return;
        }

        if (!str_starts_with($data, 'ma_detail:')) {
            $this->end();
            return;
        }

        $requestId = (int) explode(':', $data)[1];
        $bot->answerCallbackQuery();

        $user = User::where('telegram_id', $bot->userId())->first();
        $lang = $user->lang ?? 'ru';

        $request = ApprovalRequest::with(['project', 'applicant', 'approvals.approver', 'attachments', 'tranches'])->find($requestId);
        if (!$request) {
            $bot->sendMessage(__('bot.my_request_not_found', [], $lang));
            $this->end();
            return;
        }

        $statusLabels = [
            'approval_paused' => __('notifications.approval_paused', [], $lang),
            'draft'            => __('bot.my_status_draft', [], $lang),
            'pending_approval' => __('bot.my_status_pending', [], $lang),
            'pending_supply'   => __('bot.my_status_supply', [], $lang),
            'approved'         => __('bot.my_status_approved', [], $lang),
            'rejected'         => __('bot.my_status_rejected', [], $lang),
            'cancelled'        => __('bot.my_status_cancelled', [], $lang),
        ];

        $msg = __('bot.my_detail_header', ['id' => $request->id], $lang) . "\n\n";
        $msg .= __('bot.my_detail_project', ['name' => $request->project?->name ?? 'N/A'], $lang) . "\n";
        $msg .= __('bot.my_detail_name', ['name' => $request->name], $lang) . "\n";
        $msg .= __('bot.my_detail_status_label', ['status' => $statusLabels[$request->status] ?? $request->status], $lang) . "\n";
        $msg .= "👤 " . ($request->applicant?->full_name ?? 'N/A') . "\n";

        if ($request->status === 'approval_paused') {
            $pages = app(\App\Services\TelegramNotificationMessage::class)->pages(app(\App\Services\RequestEventMessage::class)->text(
                $request, 'route-paused:detail', ['unavailable' => $request->route_unavailable ?? []], $lang));
            $recoveryKeyboard = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(__('notifications.open_request', [], $lang), url: route('requests.detail', $request->id)));
            if (app(\App\Services\ApprovalRouteRecovery::class)->canRecover($request, $user)) {
                $recoveryKeyboard->addRow(InlineKeyboardButton::make(__('notifications.edit_approvers_bot', [], $lang), callback_data: "recover_route:{$request->id}_{$request->revision}"));
            }
            foreach ($pages as $page) {
                $bot->sendMessage($page, parse_mode: 'HTML', reply_markup: $recoveryKeyboard);
            }
        }

        if ($request->total_price) {
            $msg .= __('bot.my_detail_amount', [
                'amount'   => number_format($request->total_price, 2, '.', ' '),
                'currency' => $request->currency,
            ], $lang) . "\n";
        }
        if ($request->supplier_name) {
            $msg .= __('bot.my_detail_supplier', ['name' => $request->supplier_name], $lang) . "\n";
        }
        $msg .= __('bot.my_detail_created', ['date' => $request->created_at->format('d.m.Y H:i')], $lang) . "\n";
        $msg .= __('bot.my_detail_files', ['count' => $request->attachments->count()], $lang) . "\n\n";

        if ($request->approvals->isNotEmpty()) {
            $msg .= __('bot.my_approval_route', [], $lang) . "\n";
            foreach ($request->approvals->sortBy('level') as $approval) {
                $approverName = $approval->approver?->full_name ?? __('bot.my_approver_unknown', [], $lang);
                $approverTitle = $approval->approver?->title ? " ({$approval->approver->title})" : '';

                $statusIcon = match ($approval->status) {
                    'approved' => '✅',
                    'rejected' => '❌',
                    default    => '⏳',
                };

                $msg .= __('bot.my_approval_level', [
                    'icon'  => $statusIcon,
                    'level' => $approval->level,
                    'name'  => $approverName . $approverTitle,
                ], $lang);

                if ($approval->action_date) {
                    $msg .= " — " . $approval->action_date->format('d.m.Y H:i');
                }
                if ($approval->comment) {
                    $msg .= "\n   💬 {$approval->comment}";
                }
                $msg .= "\n";
            }
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.ma_back_btn', [], $lang), callback_data: 'ma:back'));

        // If request is waiting for this user's approval, show approve/reject buttons
        if ($request->status === 'pending_approval') {
            $myApproval = $request->approvals
                ->where('level', $request->expected_approval_level)
                ->where('approver_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if ($myApproval) {
                $keyboard = InlineKeyboardMarkup::make()
                    ->addRow(
                        InlineKeyboardButton::make(__('bot.notify_approve_btn', [], $lang), callback_data: "approve:{$request->id}_{$request->revision}"),
                        InlineKeyboardButton::make(__('bot.notify_reject_btn', [], $lang), callback_data: "reject:{$request->id}_{$request->revision}")
                    )
                    ->addRow(InlineKeyboardButton::make(__('bot.ma_back_btn', [], $lang), callback_data: 'ma:back'));
            }
        }

        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->end();
    }
}
