<?php

namespace App\Telegram\Conversations;

use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\User;
use App\Services\RequestDescriptionMessage;
use App\Concerns\ConversationHelpers;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class MyRequests extends Conversation
{
    use ConversationHelpers;

    public ?string $statusFilter = null;

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
                InlineKeyboardButton::make(__('bot.my_filter_all', [], $lang), callback_data: 'filter:all'),
                InlineKeyboardButton::make(__('bot.my_filter_pending', [], $lang), callback_data: 'filter:pending_approval')
            )
            ->addRow(
                InlineKeyboardButton::make(__('bot.my_filter_approved', [], $lang), callback_data: 'filter:approved'),
                InlineKeyboardButton::make(__('bot.my_filter_rejected', [], $lang), callback_data: 'filter:rejected')
            )
            ->addRow(
                InlineKeyboardButton::make(__('bot.my_filter_supply', [], $lang), callback_data: 'filter:pending_supply')
            )
            ->addRow(InlineKeyboardButton::make(__('notifications.approval_paused', [], $lang), callback_data: 'filter:approval_paused'))
            ->addRow(
                InlineKeyboardButton::make(__('bot.my_filter_stats', [], $lang), callback_data: 'filter:stats')
            );

        $bot->sendMessage(__('bot.my_title', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleFilter');
    }

    public function handleFilter(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) return;
            $lang = BotLang::getLang($bot);
            $bot->sendMessage(__('bot.select_filter_btns', [], $lang));
            return;
        }

        $data = $bot->callbackQuery()->data;
        if ($data === 'filter:back_to_menu') {
            $bot->answerCallbackQuery();
            $this->start($bot);
            return;
        }
        if (!str_starts_with($data, 'filter:')) return;

        $filter = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        $user = User::where('telegram_id', $bot->userId())->first();
        $lang = $user->lang ?? 'ru';

        if ($filter === 'stats') {
            $this->showStats($bot, $user);
            return;
        }

        $this->statusFilter = $filter;

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.my_period_all', [], $lang), callback_data: 'period:all'),
                InlineKeyboardButton::make(__('bot.my_period_month', [], $lang), callback_data: 'period:month')
            )
            ->addRow(
                InlineKeyboardButton::make(__('bot.my_period_last_month', [], $lang), callback_data: 'period:last_month'),
                InlineKeyboardButton::make(__('bot.my_period_year', [], $lang), callback_data: 'period:year')
            )
            ->addRow(
                InlineKeyboardButton::make(__('bot.my_back_btn', [], $lang), callback_data: 'period:back')
            );

        $bot->sendMessage(__('bot.my_period_title', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handlePeriod');
    }

    public function handlePeriod(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) return;
            $lang = BotLang::getLang($bot);
            $bot->sendMessage(__('bot.select_filter_btns', [], $lang));
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (!str_starts_with($data, 'period:')) return;

        $period = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        if ($period === 'back') {
            $this->start($bot);
            return;
        }

        $user = User::where('telegram_id', $bot->userId())->first();
        $lang = $user->lang ?? 'ru';

        $query = ApprovalRequest::with('project')
            ->where('applicant_id', $user->id)
            ->orderByDesc('created_at');

        $filter = $this->statusFilter ?? 'all';
        if ($filter !== 'all') {
            $query->where('status', $filter);
        }

        $now = now();
        match ($period) {
            'month'      => $query->where('created_at', '>=', $now->copy()->startOfMonth()),
            'last_month' => $query->whereBetween('created_at', [
                $now->copy()->subMonthNoOverflow()->startOfMonth(),
                $now->copy()->subMonthNoOverflow()->endOfMonth(),
            ]),
            'year'       => $query->where('created_at', '>=', $now->copy()->startOfYear()),
            default      => null,
        };

        $requests = $query->limit(200)->get();

        if ($requests->isEmpty()) {
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(__('bot.my_back_btn', [], $lang), callback_data: 'filter:back_to_menu'));
            $bot->sendMessage(__('bot.my_no_requests', [], $lang), reply_markup: $keyboard);
            $this->next('handleFilter');
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

        $periodLabel = __('bot.my_period_' . $period, [], $lang);

        $header = __('bot.my_list_header', ['count' => $requests->count()], $lang) . "\n";
        $header .= "📅 {$periodLabel}\n\n";

        // Telegram caps messages at 4096 chars — chunk long lists into several messages.
        $chunks = [];
        $current = $header;
        foreach ($requests as $req) {
            $status = $statusLabels[$req->status] ?? $req->status;
            $projectName = $req->project?->name ?? 'N/A';
            $amount = $req->total_price
                ? number_format($req->total_price, 2, '.', ' ') . ' ' . $req->currency
                : '—';

            $entry  = "<b>#{$req->id}</b> | {$status}\n";
            $entry .= "📁 {$projectName} | 📌 {$req->name}\n";
            $entry .= "💰 {$amount} | 📅 {$req->created_at->format('d.m.Y')}\n\n";

            if (mb_strlen($current) + mb_strlen($entry) > 3800) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $entry;
        }
        $chunks[] = $current;

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($requests->take(5) as $req) {
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    __('bot.my_detail_btn', ['id' => $req->id], $lang),
                    callback_data: "detail:{$req->id}"
                )
            );
        }

        $last = array_key_last($chunks);
        foreach ($chunks as $i => $chunk) {
            $bot->sendMessage(
                $chunk,
                parse_mode: 'HTML',
                reply_markup: $i === $last ? $keyboard : null,
            );
        }

        $this->next('handleDetail');
    }

    public function handleDetail(Nutgram $bot)
    {
        if (!$bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) return;
            $this->end();
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (!str_starts_with($data, 'detail:')) {
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

        $txLabel = match ($request->transaction_type) {
            'cash' => __('bot.my_tx_cash', [], $lang),
            'bank' => __('bot.my_tx_bank', [], $lang),
            default => '—',
        };

        $typeLabel = match ($request->type) {
            'supply' => __('bot.my_type_supply', [], $lang),
            'management_document' => __('bot.my_type_management_document', [], $lang),
            default => __('bot.my_type_expense', [], $lang),
        };

        $msg = __('bot.my_detail_header', ['id' => $request->id], $lang) . "\n\n";
        $msg .= __('bot.my_detail_project', ['name' => $request->project?->name ?? 'N/A'], $lang) . "\n";
        $msg .= __('bot.my_detail_name', ['name' => $request->name], $lang) . "\n";
        $descriptionPages = app(RequestDescriptionMessage::class)->pages($request, $lang);
        $detailHeader = $msg;
        $msg = '';
        $msg .= __('bot.my_detail_type', ['type' => $typeLabel], $lang) . "\n";
        $msg .= __('bot.my_detail_status_label', ['status' => $statusLabels[$request->status] ?? $request->status], $lang) . "\n";

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
        if ($request->transaction_type) {
            $msg .= __('bot.my_detail_method', ['method' => $txLabel], $lang) . "\n";
        }
        $msg .= __('bot.my_detail_created', ['date' => $request->created_at->format('d.m.Y H:i')], $lang) . "\n";
        $msg .= __('bot.my_detail_files', ['count' => $request->attachments->count()], $lang) . "\n\n";

        if ($request->approvals->isNotEmpty()) {
            $msg .= __('bot.my_approval_route', [], $lang) . "\n";
            foreach ($request->approvals->sortBy('level') as $approval) {
                $approverName = $approval->approver
                    ? ($approval->approver->full_name ?? __('bot.my_approver_unknown', [], $lang))
                    : __('bot.my_approver_unknown', [], $lang);
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
                    $msg .= " — " . \Carbon\Carbon::parse($approval->action_date)->format('d.m.Y H:i');
                }
                if ($approval->comment) {
                    $msg .= "\n   💬 {$approval->comment}";
                }
                $msg .= "\n";
            }
        }

        if ($request->tranches->isNotEmpty()) {
            $msg .= "\n" . __('bot.my_tranches_header', [], $lang) . "\n";
            foreach ($request->tranches as $i => $t) {
                $statusIcon = $t->status === 'paid' ? '✅' : '⏳';
                $date = $t->due_date ? $t->due_date->format('d.m.Y') : '—';
                $msg .= "{$statusIcon} " . ($i + 1) . ". {$date} — " . number_format($t->amount, 2, '.', ' ') . " {$request->currency}\n";
                if ($t->comment) {
                    $msg .= "   💬 <i>{$t->comment}</i>\n";
                }
            }
        }

        $keyboard = InlineKeyboardMarkup::make();

        if ($request->status === 'rejected') {
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    __('bot.my_edit_btn', [], $lang),
                    callback_data: "edit_rejected:{$request->id}"
                )
            );
        }

        // Applicants may delete their own requests as long as they are not approved yet.
        if ($request->applicant_id === $user->id && $request->status !== 'approved') {
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    __('bot.my_delete_btn', [], $lang),
                    callback_data: "del_req:{$request->id}"
                )
            );
        }

        if ($request->status === 'approved' && $request->pdf_url) {
            $msg .= __('bot.my_detail_pdf', [], $lang);
        }

        if (count($descriptionPages) === 1 && mb_strlen($detailHeader.$descriptionPages[0].$msg) < 3500) {
            $msg = $detailHeader.$descriptionPages[0]."\n".$msg;
        } else {
            $bot->sendMessage($detailHeader, parse_mode: 'HTML');
            foreach ($descriptionPages as $descriptionPage) {
                $bot->sendMessage($descriptionPage, parse_mode: 'HTML');
            }
        }
        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->end();
    }

    protected function showStats(Nutgram $bot, User $user)
    {
        $lang = $user->lang ?? 'ru';
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $startOfYear  = $now->copy()->startOfYear();

        $statsQuery = ApprovalRequest::where('applicant_id', $user->id)
            ->where('status', 'approved');

        $monthSum = (clone $statsQuery)->where('updated_at', '>=', $startOfMonth)->sum('total_price');
        $yearSum  = (clone $statsQuery)->where('updated_at', '>=', $startOfYear)->sum('total_price');

        $byProject = (clone $statsQuery)->where('updated_at', '>=', $startOfMonth)
            ->selectRaw('project_id, SUM(total_price) as sum, currency')
            ->groupBy('project_id', 'currency')
            ->with('project')
            ->get();

        $msg = __('bot.my_stats_header', [], $lang) . "\n\n";
        $msg .= __('bot.my_stats_month', ['sum' => number_format($monthSum, 2, '.', ' ')], $lang) . "\n";
        $msg .= __('bot.my_stats_year', ['sum' => number_format($yearSum, 2, '.', ' ')], $lang) . "\n\n";

        if ($byProject->isNotEmpty()) {
            $msg .= __('bot.my_stats_by_project', [], $lang) . "\n";
            foreach ($byProject as $stat) {
                $msg .= "🔹 " . ($stat->project?->name ?? 'N/A') . ": " . number_format($stat->sum, 2, '.', ' ') . " {$stat->currency}\n";
            }
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.my_back_btn', [], $lang), callback_data: 'filter:back_to_menu'));

        $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleFilter');
    }

    protected function finish(Nutgram $bot): void
    {
        $this->sendMenu($bot);
        $this->end();
    }
}
