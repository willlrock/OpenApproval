<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class AdminPanel extends Conversation
{
    use ConversationHelpers;

    public ?int $editingUserId = null;

    public function start(Nutgram $bot)
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if (! $user || ! in_array($user->role, ['admin', 'superadmin', 'supplier'])) {
            $lang = BotLang::getLang($bot);
            $bot->sendMessage(__('bot.adm_no_access', [], $lang));
            $this->end();

            return;
        }

        $lang = $user->lang ?? 'ru';

        $keyboard = InlineKeyboardMarkup::make();

        if (in_array($user->role, ['admin', 'superadmin'])) {
            $keyboard
                ->addRow(InlineKeyboardButton::make(__('bot.adm_manage_pool_btn', [], $lang), callback_data: 'adm:manage_pool'))
                ->addRow(InlineKeyboardButton::make(__('bot.adm_stats_btn', [], $lang), callback_data: 'adm:stats'))
                ->addRow(InlineKeyboardButton::make(__('bot.adm_all_requests_btn', [], $lang), callback_data: 'adm:all_requests'))
                ->addRow(InlineKeyboardButton::make(__('bot.adm_logs_btn', [], $lang), callback_data: 'adm:logs'))
                ->addRow(InlineKeyboardButton::make(__('bot.adm_projects_btn', [], $lang), callback_data: 'adm:projects'))
                ->addRow(InlineKeyboardButton::make(__('bot.adm_broadcast_btn', [], $lang), callback_data: 'adm:broadcast'));
        }

        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_suppliers_btn', [], $lang), callback_data: 'adm:suppliers'));

        $bot->sendMessage(__('bot.adm_title', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleMainChoice');
    }

    public function handleMainChoice(Nutgram $bot)
    {
        if (! $bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (! str_starts_with($data, 'adm:')) {
            return;
        }

        $choice = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        match ($choice) {
            'manage_pool' => $this->managePool($bot),
            'stats' => $this->showStats($bot),
            'all_requests' => $this->showAllRequests($bot),
            'logs' => $this->showLogs($bot),
            'projects' => $this->manageProjects($bot),
            'suppliers' => $this->manageSuppliers($bot),
            'broadcast' => $this->startBroadcast($bot),
            default => $this->start($bot),
        };
    }

    protected function startBroadcast(Nutgram $bot): void
    {
        $this->end();
        BroadcastNotification::begin($bot);
    }

    // ── Pool Management ──────────────────────────────────────────────

    protected function managePool(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $users = User::join('roles', 'users.role_id', '=', 'roles.id')
            ->select('users.*')
            ->orderBy('roles.name')
            ->orderBy('users.full_name')
            ->get();

        $keyboard = InlineKeyboardMarkup::make();

        foreach ($users as $u) {
            $roleLabel = match ($u->role) {
                'superadmin' => '👑',
                'admin' => '🔑',
                'approver' => '✅',
                'supplier' => '📦',
                default => '👤',
            };
            $titleLabel = $u->title ? " • {$u->title}" : '';
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    "{$roleLabel} {$u->full_name}{$titleLabel}",
                    callback_data: "pool_edit:{$u->id}"
                )
            );
        }

        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_back_btn', [], $lang), callback_data: 'pool_edit:back'));

        $bot->sendMessage(__('bot.adm_pool_header', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handlePoolEdit');
    }

    public function handlePoolEdit(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (! $bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (! str_starts_with($data, 'pool_edit:')) {
            return;
        }

        $value = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        if ($value === 'back') {
            $this->start($bot);

            return;
        }

        $userId = (int) $value;
        $user = User::find($userId);
        if (! $user) {
            $bot->sendMessage(__('bot.adm_user_not_found', [], $lang));
            $this->start($bot);

            return;
        }

        $this->editingUserId = $userId;

        $roleLabels = [
            'superadmin' => __('bot.adm_role_superadmin', [], $lang),
            'admin' => __('bot.adm_role_admin', [], $lang),
            'supplier' => __('bot.adm_role_supplier', [], $lang),
            'approver' => __('bot.adm_role_approver', [], $lang),
            'requester' => __('bot.adm_role_requester', [], $lang),
        ];
        $currentRole = $roleLabels[$user->role] ?? $user->role;

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(__('bot.adm_btn_superadmin', [], $lang), callback_data: 'set_role:superadmin'))
            ->addRow(InlineKeyboardButton::make(__('bot.adm_btn_admin', [], $lang), callback_data: 'set_role:admin'))
            ->addRow(InlineKeyboardButton::make(__('bot.adm_btn_supplier', [], $lang), callback_data: 'set_role:supplier'))
            ->addRow(InlineKeyboardButton::make(__('bot.adm_btn_approver', [], $lang), callback_data: 'set_role:approver'))
            ->addRow(InlineKeyboardButton::make(__('bot.adm_btn_requester', [], $lang), callback_data: 'set_role:requester'))
            ->addRow(InlineKeyboardButton::make(__('bot.adm_back_to_list_btn', [], $lang), callback_data: 'set_role:back'))
            ->addRow(InlineKeyboardButton::make(__('bot.adm_user_projects_btn', [], $lang), callback_data: 'adm:user_projects_direct'));

        $bot->sendMessage(
            __('bot.adm_user_card', [
                'name' => htmlspecialchars($user->full_name),
                'role' => $currentRole,
                'title' => $user->title ?? '—',
            ], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleUserAction');
    }

    public function handleUserAction(Nutgram $bot)
    {
        if (! $bot->isCallbackQuery()) {
            return;
        }
        $data = $bot->callbackQuery()->data;

        if (str_starts_with($data, 'set_role:')) {
            $this->handleSetRole($bot);

            return;
        }

        if ($data === 'adm:user_projects_direct') {
            $bot->answerCallbackQuery();
            $this->manageUserProjects($bot);

            return;
        }
    }

    public function handleSetRole(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (! $bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (! str_starts_with($data, 'set_role:')) {
            return;
        }

        $role = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        if ($role === 'back') {
            $this->managePool($bot);

            return;
        }

        $actor = User::where('telegram_id', $bot->userId())->first();
        // Role restriction: only superadmin can assign admin/superadmin
        if (in_array($role, ['admin', 'superadmin']) && $actor?->role !== 'superadmin') {
            $bot->sendMessage(__('bot.adm_role_restricted', [], $lang));
            $this->managePool($bot);

            return;
        }

        $user = User::find($this->editingUserId);
        if (! $user) {
            $bot->sendMessage(__('bot.adm_user_not_found', [], $lang));
            $this->end();

            return;
        }

        $roleModel = \App\Models\Role::where('name', $role)->first();
        if (! $roleModel) {
            $bot->sendMessage(__('bot.adm_role_not_found', ['role' => $role], $lang));
            $this->end();

            return;
        }

        try {
            app(\App\Services\UserAdministration::class)->update($actor, $user->id, ['role_id' => $roleModel->id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $bot->sendMessage(implode("\n", \Illuminate\Support\Arr::flatten($e->errors())));

            return;
        } catch (\Throwable) {
            $bot->sendMessage(__('bot.adm_role_restricted', [], $lang));

            return;
        }

        $roleLabels = [
            'superadmin' => __('bot.adm_role_superadmin', [], $lang),
            'admin' => __('bot.adm_role_admin', [], $lang),
            'supplier' => __('bot.adm_role_supplier', [], $lang),
            'approver' => __('bot.adm_role_approver', [], $lang),
            'requester' => __('bot.adm_role_requester', [], $lang),
        ];
        $roleLabel = $roleLabels[$role] ?? $role;

        $bot->sendMessage(
            __('bot.adm_role_changed', ['name' => htmlspecialchars($user->full_name), 'role' => $roleLabel], $lang),
            parse_mode: 'HTML'
        );
        $this->next('handleSetTitle');
    }

    public function handleSetTitle(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (! $bot->message()?->text) {
            return;
        }

        $text = trim($bot->message()->text);

        if ($text !== '/skip' && $this->editingUserId) {
            $user = User::find($this->editingUserId);
            if ($user) {
                $actor = User::where('telegram_id', $bot->userId())->first();
                if (! $actor) {
                    return;
                }
                try {
                    app(\App\Services\UserAdministration::class)->update($actor, $user->id, ['title' => $text]);
                } catch (\Throwable) {
                    $bot->sendMessage(__('bot.adm_role_restricted', [], $lang));

                    return;
                }
                $bot->sendMessage(__('bot.adm_title_saved', ['title' => htmlspecialchars($text)], $lang), parse_mode: 'HTML');
            }
        } else {
            $bot->sendMessage(__('bot.adm_skipped', [], $lang));
        }

        $this->askManageProjects($bot);
    }

    protected function askManageProjects(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.adm_ask_projects_btn', [], $lang), callback_data: 'adm:user_projects'),
                InlineKeyboardButton::make(__('bot.adm_home_btn', [], $lang), callback_data: 'adm:back_home')
            );

        $bot->sendMessage(__('bot.adm_ask_projects_msg', [], $lang), reply_markup: $keyboard);
        $this->next('handleManageProjectsChoice');
    }

    public function handleManageProjectsChoice(Nutgram $bot)
    {
        if (! $bot->isCallbackQuery()) {
            return;
        }
        $data = $bot->callbackQuery()->data;
        $bot->answerCallbackQuery();

        if ($data === 'adm:user_projects') {
            $this->manageUserProjects($bot);
        } else {
            $this->start($bot);
        }
    }

    protected function manageUserProjects(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $user = User::find($this->editingUserId);
        if (! $user) {
            $this->start($bot);

            return;
        }

        $projects = Project::all();
        $userProjects = $user->projects()->pluck('projects.id')->toArray();

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($projects as $project) {
            $hasAccess = in_array($project->id, $userProjects);
            $status = $hasAccess ? '✅' : '❌';
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    "{$status} {$project->name}",
                    callback_data: "up_toggle:{$project->id}"
                )
            );
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_back_to_user_btn', [], $lang), callback_data: "pool_edit:{$user->id}"));
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_home_main_btn', [], $lang), callback_data: 'adm:back_home'));

        $bot->sendMessage(
            __('bot.adm_user_proj_header', ['name' => htmlspecialchars($user->full_name)], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleUserProjectToggle');
    }

    public function handleUserProjectToggle(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (! $bot->isCallbackQuery()) {
            return;
        }
        $data = $bot->callbackQuery()->data;

        if ($data === 'adm:back_home') {
            $bot->answerCallbackQuery();
            $this->start($bot);

            return;
        }

        if (str_starts_with($data, 'pool_edit:')) {
            $bot->answerCallbackQuery();
            $this->handlePoolEdit($bot);

            return;
        }

        if (! str_starts_with($data, 'up_toggle:')) {
            return;
        }

        $projectId = (int) explode(':', $data)[1];
        $user = User::find($this->editingUserId);

        if ($user) {
            $actor = User::where('telegram_id', $bot->userId())->first();
            if (! $actor) {
                return;
            }
            try {
                app(\App\Services\UserAdministration::class)->toggleProject($actor, $user->id, $projectId);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $bot->answerCallbackQuery();
                $bot->sendMessage(implode("\n", \Illuminate\Support\Arr::flatten($e->errors())));

                return;
            } catch (\Throwable) {
                $bot->answerCallbackQuery(text: __('bot.adm_role_restricted', [], $lang), show_alert: true);

                return;
            }
            $bot->answerCallbackQuery(text: __('bot.adm_access_toggled', [], $lang));
            $this->manageUserProjects($bot);
        }
    }

    // ── Statistics ───────────────────────────────────────────────────

    protected function showStats(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $totalRequests = ApprovalRequest::count();
        $approvedRequests = ApprovalRequest::where('status', 'approved')->count();
        $rejectedRequests = ApprovalRequest::where('status', 'rejected')->count();
        $pendingRequests = ApprovalRequest::where('status', 'pending_approval')->count();
        $supplyRequests = ApprovalRequest::where('status', 'pending_supply')->count();

        $currencies = ApprovalRequest::where('status', 'approved')
            ->whereNotNull('currency')
            ->selectRaw('currency, SUM(total_price) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency');

        $totalUsers = User::count();
        $approverCount = User::whereHas('roleRelation', fn ($q) => $q->whereIn('name', ['admin', 'approver', 'supplier', 'superadmin']))->count();

        $monthApproved = ApprovalRequest::where('status', 'approved')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $monthTotal = ApprovalRequest::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $msg = __('bot.adm_stats_header', [], $lang)."\n\n";
        $msg .= __('bot.adm_users_count', ['total' => $totalUsers, 'approvers' => $approverCount], $lang)."\n\n";
        $msg .= __('bot.adm_requests_header', [], $lang)."\n";
        $msg .= __('bot.adm_stat_total', ['count' => $totalRequests], $lang)."\n";
        $msg .= __('bot.adm_stat_approved', ['count' => $approvedRequests], $lang)."\n";
        $msg .= __('bot.adm_stat_rejected', ['count' => $rejectedRequests], $lang)."\n";
        $msg .= __('bot.adm_stat_pending', ['count' => $pendingRequests], $lang)."\n";
        $msg .= __('bot.adm_stat_supply', ['count' => $supplyRequests], $lang)."\n\n";

        if ($currencies->isNotEmpty()) {
            $msg .= __('bot.adm_approved_sums', [], $lang)."\n";
            foreach ($currencies as $currency => $sum) {
                $msg .= "   • {$currency}: ".number_format($sum, 2, '.', ' ')."\n";
            }
            $msg .= "\n";
        }

        $msg .= __('bot.adm_month_stats', ['approved' => $monthApproved, 'total' => $monthTotal], $lang)."\n";

        // Red Flags
        $redFlags = \App\Models\RequestApproval::with(['approver', 'approvalRequest.project', 'approvalRequest.applicant'])
            ->where('status', 'pending')
            ->where('notified_at', '<', now()->subHours(24))
            ->get();

        $supplyFlags = ApprovalRequest::where('status', 'pending_supply')
            ->where('updated_at', '<', now()->subHours(24))
            ->get();

        $paymentFlags = ApprovalRequest::where('status', 'approved')
            ->where('updated_at', '<', now()->subHours(48))
            ->whereHas('tranches', function ($q) {
                $q->where('status', 'pending');
            })
            ->get();

        if ($redFlags->isNotEmpty() || $supplyFlags->isNotEmpty() || $paymentFlags->isNotEmpty()) {
            $msg .= "\n".__('bot.adm_red_flags', [], $lang)."\n";

            foreach ($redFlags as $rf) {
                $approverName = $rf->approver ? ($rf->approver->full_name ?? __('bot.adm_unknown', [], $lang)) : __('bot.adm_unknown', [], $lang);
                $projectName = $rf->approvalRequest->project->name ?? 'N/A';
                $hours = now()->diffInHours($rf->notified_at);
                $msg .= "   • #{$rf->approval_request_id} ({$projectName}) — <b>{$approverName}</b>\n";
                $msg .= __('bot.adm_approval_delay', ['hours' => $hours], $lang)."\n";
            }

            foreach ($supplyFlags as $sf) {
                $projectName = $sf->project->name ?? 'N/A';
                $hours = now()->diffInHours($sf->updated_at);
                $msg .= "   • #{$sf->id} ({$projectName}) — <b>".__('bot.adm_supply_flags_label', [], $lang)."</b>\n";
                $msg .= __('bot.adm_supply_delay', ['hours' => $hours], $lang)."\n";
            }

            foreach ($paymentFlags as $pf) {
                $projectName = $pf->project->name ?? 'N/A';
                $hours = now()->diffInHours($pf->updated_at);
                $msg .= "   • #{$pf->id} ({$projectName}) — <b>".__('bot.adm_payment_flags_label', [], $lang)."</b>\n";
                $msg .= __('bot.adm_payment_delay', ['hours' => $hours], $lang)."\n";
            }
        }

        $projects = Project::withCount(['approvalRequests as total_count'])
            ->withCount(['approvalRequests as approved_count' => function ($q) {
                $q->where('status', 'approved');
            }])
            ->get();

        if ($projects->isNotEmpty()) {
            $msg .= "\n".__('bot.adm_by_projects', [], $lang)."\n";
            foreach ($projects as $project) {
                $projectSum = ApprovalRequest::where('project_id', $project->id)
                    ->where('status', 'approved')
                    ->sum('total_price');
                $msg .= "   • {$project->name}: {$project->approved_count}/{$project->total_count}";
                if ($projectSum > 0) {
                    $msg .= ' | '.number_format($projectSum, 2, '.', ' ');
                }
                $msg .= "\n";
            }
        }

        $bot->sendMessage($msg, parse_mode: 'HTML');
        $this->sendMenu($bot);
        $this->end();
    }

    // ── All Requests ─────────────────────────────────────────────────

    protected function showAllRequests(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $requests = ApprovalRequest::with(['project', 'applicant'])
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        if ($requests->isEmpty()) {
            $bot->sendMessage(__('bot.adm_no_requests', [], $lang));
            $this->start($bot);

            return;
        }

        $statusLabels = [
            'approval_paused' => __('notifications.approval_paused', [], $lang),
            'draft' => '📝',
            'pending_approval' => '⏳',
            'pending_supply' => '📦',
            'approved' => '✅',
            'rejected' => '❌',
        ];

        $msg = __('bot.adm_last_requests', ['count' => $requests->count()], $lang)."\n\n";

        foreach ($requests as $req) {
            $status = $statusLabels[$req->status] ?? '❓';
            $projectName = $req->project?->name ?? 'N/A';
            $applicantName = $req->applicant
                ? ($req->applicant->full_name ?? __('bot.adm_unknown', [], $lang))
                : __('bot.adm_unknown', [], $lang);
            $amount = $req->total_price
                ? number_format($req->total_price, 2, '.', ' ').' '.$req->currency
                : '—';

            $isDelayed = false;
            if ($req->status === 'pending_approval') {
                $pendingLevel = $req->approvals()->where('status', 'pending')->first();
                if ($pendingLevel && $pendingLevel->notified_at && $pendingLevel->notified_at < now()->subHours(24)) {
                    $isDelayed = true;
                }
            } elseif ($req->status === 'pending_supply' && $req->updated_at < now()->subHours(24)) {
                $isDelayed = true;
            } elseif ($req->status === 'approved' && $req->updated_at < now()->subHours(48)) {
                $hasUnpaid = $req->tranches()->where('status', 'pending')->exists();
                if ($hasUnpaid) {
                    $isDelayed = true;
                }
            }

            $delayIcon = $isDelayed ? '🚩 ' : '';

            $msg .= "{$status} {$delayIcon}<b>#{$req->id}</b> | {$projectName}\n";
            $msg .= "   📌 {$req->name} | 👤 {$applicantName}\n";
            $msg .= "   💰 {$amount} | 📅 {$req->created_at->format('d.m.Y')}\n\n";
        }

        $bot->sendMessage($msg, parse_mode: 'HTML');
        $this->sendMenu($bot);
        $this->end();
    }

    // ── Logs ─────────────────────────────────────────────────────────

    protected function showLogs(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        $recentActions = \App\Models\RequestApproval::with(['approver', 'approvalRequest'])
            ->whereNotNull('action_date')
            ->orderByDesc('action_date')
            ->limit(15)
            ->get();

        if ($recentActions->isEmpty()) {
            $bot->sendMessage(__('bot.adm_no_logs', [], $lang));
            $this->start($bot);

            return;
        }

        $msg = __('bot.adm_logs_header', [], $lang)."\n\n";

        foreach ($recentActions as $action) {
            $approverName = $action->approver
                ? ($action->approver->full_name ?? __('bot.adm_unknown', [], $lang))
                : __('bot.adm_unknown', [], $lang);
            $statusIcon = $action->status === 'approved' ? '✅' : '❌';
            $requestId = $action->approval_request_id;
            $date = \Carbon\Carbon::parse($action->action_date)->format('d.m.Y H:i');

            $msg .= "{$statusIcon} <b>#{$requestId}</b> | {$approverName} | Lv.{$action->level}\n";
            $msg .= "   📅 {$date}";
            if ($action->comment) {
                $msg .= " | 💬 {$action->comment}";
            }
            $msg .= "\n\n";
        }

        $bot->sendMessage($msg, parse_mode: 'HTML');
        $this->sendMenu($bot);
        $this->end();
    }

    // ── Projects Management ──────────────────────────────────────────

    protected function manageProjects(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $projects = Project::all();

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($projects as $project) {
            $count = ApprovalRequest::where('project_id', $project->id)->count();
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    "📁 {$project->name} ({$count})",
                    callback_data: "proj_del:{$project->id}"
                )
            );
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_add_proj_btn', [], $lang), callback_data: 'proj_del:add'));
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_back_btn', [], $lang), callback_data: 'proj_del:back'));

        $bot->sendMessage(__('bot.adm_proj_header', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleProjectAction');
    }

    public function handleProjectAction(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (! $bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (! str_starts_with($data, 'proj_del:')) {
            return;
        }

        $value = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        if ($value === 'back') {
            $this->start($bot);

            return;
        }

        if ($value === 'add') {
            $bot->sendMessage(__('bot.adm_add_proj_prompt', [], $lang));
            $this->next('handleAddProject');

            return;
        }

        $projectId = (int) $value;
        $project = Project::find($projectId);
        if (! $project) {
            $bot->sendMessage(__('bot.adm_proj_not_found', [], $lang));
            $this->manageProjects($bot);

            return;
        }

        $count = ApprovalRequest::where('project_id', $projectId)->count();
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.adm_delete_proj_btn', [], $lang), callback_data: "proj_confirm_del:{$projectId}"),
                InlineKeyboardButton::make(__('bot.adm_back_btn', [], $lang), callback_data: 'proj_confirm_del:back')
            );

        $bot->sendMessage(
            __('bot.adm_proj_card', ['name' => $project->name, 'count' => $count], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleConfirmDelete');
    }

    public function handleConfirmDelete(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (! $bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (! str_starts_with($data, 'proj_confirm_del:')) {
            return;
        }

        $value = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        if ($value === 'back') {
            $this->manageProjects($bot);

            return;
        }

        $project = Project::find((int) $value);
        if ($project) {
            $name = $project->name;
            try {
                app(\App\Services\DirectoryDeletion::class)->project($project->id);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $bot->sendMessage(implode("\n", \Illuminate\Support\Arr::flatten($e->errors())));

                return;
            }
            $bot->sendMessage(__('bot.adm_proj_deleted', ['name' => $name], $lang), parse_mode: 'HTML');
        }

        $this->manageProjects($bot);
    }

    // ── Suppliers Management ──────────────────────────────────────────

    protected function manageSuppliers(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $suppliers = Supplier::orderBy('name')->limit(50)->get();

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($suppliers as $sup) {
            $label = "🏢 {$sup->name}";
            if ($sup->inn) {
                $label .= " (ИНН: {$sup->inn})";
            }
            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: "sup_del:{$sup->id}"));
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_add_sup_btn', [], $lang), callback_data: 'sup_del:add'));
        $keyboard->addRow(InlineKeyboardButton::make(__('bot.adm_back_btn', [], $lang), callback_data: 'sup_del:back'));

        $bot->sendMessage(__('bot.adm_sup_header', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleSupplierAction');
    }

    public function handleSupplierAction(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (! $bot->isCallbackQuery()) {
            return;
        }

        $data = $bot->callbackQuery()->data;
        if (! str_starts_with($data, 'sup_del:')) {
            return;
        }

        $value = explode(':', $data)[1];
        $bot->answerCallbackQuery();

        if ($value === 'back') {
            $this->start($bot);

            return;
        }

        if ($value === 'add') {
            $bot->sendMessage(__('bot.adm_add_sup_prompt', [], $lang), parse_mode: 'HTML');
            $this->next('handleAddSupplier');

            return;
        }

        $supId = (int) $value;
        $sup = Supplier::find($supId);
        if ($sup) {
            $name = $sup->name;
            $sup->delete();
            $bot->sendMessage(__('bot.adm_sup_deleted', ['name' => $name], $lang), parse_mode: 'HTML');
        }

        $this->manageSuppliers($bot);
    }

    public function handleAddSupplier(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);
        $text = trim($bot->message()?->text ?? '');

        if (! $text) {
            $bot->sendMessage(__('bot.adm_sup_enter_name', [], $lang));

            return;
        }

        $parts = explode(',', $text);
        $name = trim($parts[0]);
        $inn = isset($parts[1]) ? trim($parts[1]) : null;

        if (strlen($name) < 2) {
            $bot->sendMessage(__('bot.adm_sup_too_short', [], $lang));

            return;
        }

        Supplier::updateOrCreate(['name' => $name], ['inn' => $inn]);
        $bot->sendMessage(__('bot.adm_sup_saved', ['name' => $name], $lang), parse_mode: 'HTML');
        $this->manageSuppliers($bot);
    }

    public function handleAddProject(Nutgram $bot)
    {
        $lang = BotLang::getLang($bot);

        if (! $bot->message()?->text) {
            $bot->sendMessage(__('bot.adm_enter_proj_name', [], $lang));

            return;
        }

        $name = trim($bot->message()->text);
        if (strlen($name) < 2) {
            $bot->sendMessage(__('bot.adm_proj_too_short', [], $lang));

            return;
        }

        $existing = Project::where('name', $name)->first();
        if ($existing) {
            $bot->sendMessage(__('bot.adm_proj_exists', ['name' => $name], $lang), parse_mode: 'HTML');
            $this->manageProjects($bot);

            return;
        }

        Project::create(['name' => $name]);
        $bot->sendMessage(__('bot.adm_proj_created', ['name' => $name], $lang), parse_mode: 'HTML');
        $this->manageProjects($bot);
    }
}
