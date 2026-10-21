<?php

use App\Models\ApprovalRequest;
use App\Models\User;
use App\Telegram\Conversations\AdminPanel;
use App\Telegram\Conversations\CancelSupplyRequest;
use App\Telegram\Conversations\ConvertSupplyRequest;
use App\Telegram\Conversations\CreateApprovalPdfRequest;
use App\Telegram\Conversations\CreateApprovalRequest;
use App\Telegram\Conversations\CreateManagementDocument;
use App\Telegram\Conversations\CreateSupplyRequest;
use App\Telegram\Conversations\EditRejectedRequest;
use App\Telegram\Conversations\FinancePanel;
use App\Telegram\Conversations\ManagePayment;
use App\Telegram\Conversations\MyApprovals;
use App\Telegram\Conversations\MyRequests;
use App\Telegram\Conversations\RegisterUser;
use App\Telegram\Conversations\RejectRequest;
use App\Telegram\Conversations\ViewStats;
use App\Telegram\Menu;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** @var Nutgram $bot */
$bot->middleware(new \App\Telegram\Middleware\EnsureActiveAccount);

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__.'/telegram-helpers.php';

// ─────────────────────────────────────────────────────────────────────────────
// BASIC COMMANDS
// ─────────────────────────────────────────────────────────────────────────────

$bot->onCommand('start', function (Nutgram $bot) {
    $telegramId = $bot->userId();
    $telegramUser = User::where('telegram_id', $telegramId)->first();

    if (! $telegramUser) {
        $applicantRole = \App\Models\Role::where('name', 'requester')->first();
        User::create([
            'telegram_id' => $telegramId,
            'account_status' => 'new',
            'role_id' => $applicantRole?->id,
        ]);
        RegisterUser::begin($bot);

        return;
    }

    if ($telegramUser->account_status === 'new') {
        RegisterUser::begin($bot);

        return;
    }

    $lang = $telegramUser->lang ?? 'ru';

    if ($telegramUser->isPending()) {
        $bot->sendMessage(__('bot.start_pending', [], $lang), parse_mode: 'HTML');

        return;
    }

    if ($telegramUser->isRejected()) {
        // A rejection must not be a permanent lockout — let the user re-apply.
        $bot->sendMessage(
            __('bot.start_rejected', [], $lang),
            parse_mode: 'HTML',
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(__('bot.reg_reapply_btn', [], $lang), callback_data: 'reg:reapply')
            )
        );

        return;
    }

    $telegramUser->update([
        'username' => $bot->user()->username ?? $telegramUser->username,
    ]);

    $roleLabels = [
        'superadmin' => __('bot.role_superadmin', [], $lang),
        'admin' => __('bot.role_admin', [], $lang),
        'supplier' => __('bot.role_supplier', [], $lang),
        'approver' => __('bot.role_approver', [], $lang),
        'requester' => __('bot.role_requester', [], $lang),
    ];

    $project = $telegramUser->projects()->first();
    $dept = $telegramUser->departments()->first();
    $projectLabel = $project?->name;
    $deptLabel = $dept?->label;
    $displayName = $telegramUser->full_name;
    $roleLabel = $roleLabels[$telegramUser->role] ?? $telegramUser->role;

    $msg = __('bot.start_greeting', ['name' => $displayName, 'role' => $roleLabel], $lang);
    if ($projectLabel) {
        $msg .= __('bot.start_project_line', ['project' => $projectLabel], $lang);
    }
    if ($deptLabel) {
        $msg .= __('bot.start_dept_line', ['dept' => $deptLabel], $lang);
    }
    if ($telegramUser->title) {
        $msg .= __('bot.start_title_line', ['title' => $telegramUser->title], $lang);
    }
    $msg .= __('bot.start_use_menu', [], $lang);

    $bot->sendMessage($msg, parse_mode: 'HTML', reply_markup: Menu::main($telegramUser));
});

$bot->onCommand('help', function (Nutgram $bot) {
    $user = checkUserAccess($bot);
    if (! $user) {
        return;
    }

    $lang = $user->lang ?? 'ru';
    $bot->sendMessage(__('bot.help_text', [], $lang), parse_mode: 'HTML', reply_markup: Menu::main($user));
});

$bot->onCommand('cancel', function (Nutgram $bot) {
    $user = User::where('telegram_id', $bot->userId())->first();
    $lang = $user?->lang ?? 'ru';
    $bot->sendMessage(
        __('bot.op_cancelled', [], $lang),
        reply_markup: $user?->isApproved() ? Menu::main($user) : Menu::remove()
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// GLOBAL CANCEL CALLBACK
// ─────────────────────────────────────────────────────────────────────────────

$bot->onCallbackQueryData('global:cancel', function (Nutgram $bot) {
    $user = User::where('telegram_id', $bot->userId())->first();
    $lang = $user?->lang ?? 'ru';

    $bot->answerCallbackQuery(text: __('bot.op_cancelled', [], $lang));
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }

    $bot->sendMessage(
        __('bot.op_cancelled_full', [], $lang),
        reply_markup: $user?->isApproved() ? Menu::main($user) : Menu::remove()
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// CONVERSATIONS
// ─────────────────────────────────────────────────────────────────────────────

$bot->onCommand('approval', fn (Nutgram $bot) => checkUserAccess($bot) && CreateApprovalRequest::begin($bot));
$bot->onCommand('approvalpdf', fn (Nutgram $bot) => checkUserAccess($bot) && CreateApprovalPdfRequest::begin($bot));
$bot->onCommand('supply', fn (Nutgram $bot) => checkUserAccess($bot) && CreateSupplyRequest::begin($bot));
$bot->onCommand('document', fn (Nutgram $bot) => checkUserAccess($bot) && CreateManagementDocument::begin($bot));
$bot->onCommand('myrequests', fn (Nutgram $bot) => checkUserAccess($bot) && MyRequests::begin($bot));
$bot->onCommand('stats', fn (Nutgram $bot) => checkUserAccess($bot) && ViewStats::begin($bot));
$bot->onCommand('admin', fn (Nutgram $bot) => checkUserAccess($bot) && AdminPanel::begin($bot));
$bot->onCommand('finance', fn (Nutgram $bot) => checkUserAccess($bot) && FinancePanel::begin($bot));

// ─────────────────────────────────────────────────────────────────────────────
// AUTHENTICATED WEBSITE ACCOUNT LINK
// ─────────────────────────────────────────────────────────────────────────────

$bot->onCommand('link {token}', function (Nutgram $bot, string $token) {
    if ($bot->chatId() !== $bot->userId()) {
        return;
    }
    if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts('telegram-link:'.$bot->userId(), 5)) {
        $bot->sendMessage('Слишком много попыток. Повторите через минуту.');

        return;
    }
    \Illuminate\Support\Facades\RateLimiter::hit('telegram-link:'.$bot->userId(), 60);
    try {
        app(\App\Services\TelegramAccountLink::class)->consume($token, $bot->userId(), $bot->user()->username);
        $bot->sendMessage('Telegram привязан к вашему аккаунту. Отправьте /start.');
    } catch (\Illuminate\Validation\ValidationException $e) {
        $bot->sendMessage($e->getMessage());
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// REGISTRATION CALLBACKS
// ─────────────────────────────────────────────────────────────────────────────

$bot->onCallbackQueryData('reg_approve:{id}', function (Nutgram $bot, $id) {
    $admin = User::where('telegram_id', $bot->userId())->first();
    $lang = $admin?->lang ?? 'ru';

    if (! $admin || ! in_array($admin->role, ['admin', 'superadmin'])) {
        $bot->answerCallbackQuery(text: __('bot.cb_no_permission', [], $lang), show_alert: true);

        return;
    }

    $user = User::find((int) $id);
    if (! $user) {
        $bot->answerCallbackQuery(text: __('bot.cb_user_not_found', [], $lang), show_alert: true);

        return;
    }
    if ($user->account_status === 'approved') {
        $bot->answerCallbackQuery(text: __('bot.cb_already_approved', [], $lang), show_alert: true);

        return;
    }

    try {
        app(\App\Services\UserAdministration::class)->update($admin, $user->id, ['account_status' => 'approved']);
    } catch (\Throwable) {
        $bot->answerCallbackQuery(text: __('bot.cb_no_permission', [], $lang), show_alert: true);

        return;
    }
    $bot->answerCallbackQuery(text: __('bot.cb_user_approved_toast', [], $lang));
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }

    $bot->sendMessage(
        __('bot.cb_user_approved_msg', ['name' => $user->full_name], $lang),
        parse_mode: 'HTML'
    );

    try {
        $userLang = $user->lang ?? 'ru';
        $groupKeyboard = InlineKeyboardMarkup::make()->addRow(
            InlineKeyboardButton::make(
                text: $userLang === 'ru' ? '👥 Вступить в канал' : '👥 Join Channel',
                url: config('telegram.channel_invite_url')
            )
        );
        app(\App\Services\TelegramNotificationMessage::class)->sendTranslated(
            $bot, 'notifications.registration_approved', ['login' => route('login'), 'recovery' => route('password.request')],
            $userLang, $user->telegram_id, $groupKeyboard
        );
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::warning("Could not notify user {$user->id}: ".$e->getMessage());
    }
});

$bot->onCallbackQueryData('reg_reject:{id}', function (Nutgram $bot, $id) {
    $admin = User::where('telegram_id', $bot->userId())->first();
    $lang = $admin?->lang ?? 'ru';

    if (! $admin || ! in_array($admin->role, ['admin', 'superadmin'])) {
        $bot->answerCallbackQuery(text: __('bot.cb_no_permission', [], $lang), show_alert: true);

        return;
    }

    $user = User::find((int) $id);
    if (! $user) {
        $bot->answerCallbackQuery(text: __('bot.cb_user_not_found', [], $lang), show_alert: true);

        return;
    }

    try {
        app(\App\Services\UserAdministration::class)->rejectRegistration($admin, $user->id);
    } catch (\Throwable) {
        $bot->answerCallbackQuery(text: __('bot.cb_no_permission', [], $lang), show_alert: true);

        return;
    }
    $bot->answerCallbackQuery(text: __('bot.cb_user_rejected_toast', [], $lang));
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }

    $bot->sendMessage(
        __('bot.cb_user_rejected_msg', ['name' => $user->full_name], $lang),
        parse_mode: 'HTML'
    );

    try {
        $userLang = $user->lang ?? 'ru';
        app(\App\Services\TelegramNotificationMessage::class)->sendTranslated($bot, 'notifications.registration_rejected', [], $userLang, $user->telegram_id);
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::warning("Could not notify user {$user->id}: ".$e->getMessage());
    }
});

$bot->onCallbackQueryData('reg:reapply', function (Nutgram $bot) {
    $user = User::where('telegram_id', $bot->userId())->first();
    $lang = $user?->lang ?? 'ru';

    if (! $user || ! $user->isRejected()) {
        $bot->answerCallbackQuery(text: __('bot.cb_user_not_found', [], $lang), show_alert: true);

        return;
    }

    $bot->answerCallbackQuery();
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }

    // Back to 'new' so the wizard can overwrite the old data and re-submit.
    $user->update(['account_status' => 'new']);
    RegisterUser::begin($bot);
});

// ─────────────────────────────────────────────────────────────────────────────
// REQUEST CALLBACKS
// ─────────────────────────────────────────────────────────────────────────────

$bot->onCallbackQueryData('convert_supply:{id}', function (Nutgram $bot, $id) {
    $bot->answerCallbackQuery();
    ConvertSupplyRequest::begin($bot, data: [(int) $id]);
});

$bot->onCallbackQueryData('cancel_supply:{id}', function (Nutgram $bot, $id) {
    $bot->answerCallbackQuery();
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }
    CancelSupplyRequest::begin($bot, data: [(int) $id]);
});

$bot->onCallbackQueryData('edit_rejected:{id}', function (Nutgram $bot, $id) {
    $bot->answerCallbackQuery();
    EditRejectedRequest::begin($bot, data: [(int) $id]);
});

$bot->onCallbackQueryData('reject:{id}', function (Nutgram $bot, $id) {
    [$id, $revision] = array_pad(explode('_', (string) $id, 2), 2, '1');
    $id = (int) $id;
    $revision = (int) $revision;
    $bot->answerCallbackQuery();
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }
    RejectRequest::begin($bot, data: [(int) $id, $revision]);
});

$bot->onCallbackQueryData('pay_manage:{id}', function (Nutgram $bot, $id) {
    $bot->answerCallbackQuery();
    ManagePayment::begin($bot, data: [(int) $id]);
});

$bot->onCallbackQueryData('approve:{id}', function (Nutgram $bot, $id) {
    [$id, $revision] = array_pad(explode('_', (string) $id, 2), 2, '1');
    $id = (int) $id;
    $revision = (int) $revision;
    $telegramUser = User::where('telegram_id', $bot->userId())->first();
    if (! $telegramUser) {
        return;
    }

    $lang = $telegramUser->lang ?? 'ru';

    $request = ApprovalRequest::with(['approvals'])->find($id);
    if (! $request || $request->revision !== $revision) {
        $bot->answerCallbackQuery(text: __('bot.cb_request_not_found', [], $lang), show_alert: true);

        return;
    }
    if ($request->status !== 'pending_approval') {
        $bot->answerCallbackQuery(text: __('bot.cb_request_processed', [], $lang), show_alert: true);

        return;
    }

    $currentApproval = $request->approvals->firstWhere('level', $request->expected_approval_level);
    if (! $currentApproval || $currentApproval->approver_id !== $telegramUser->id) {
        $bot->answerCallbackQuery(text: __('bot.cb_cant_approve_now', [], $lang), show_alert: true);

        return;
    }
    if ($currentApproval->status !== 'pending') {
        $bot->answerCallbackQuery(text: __('bot.cb_already_decided', [], $lang), show_alert: true);

        return;
    }

    // Ask whether to add an optional comment before finalizing.
    try {
        $bot->answerCallbackQuery();
    } catch (\Exception) {
    }
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }

    $kb = InlineKeyboardMarkup::make()->addRow(
        InlineKeyboardButton::make(__('bot.cb_btn_with_comment', [], $lang), callback_data: "approve_comment:{$id}_{$revision}"),
        InlineKeyboardButton::make(__('bot.cb_btn_skip_comment', [], $lang), callback_data: "approve_skip:{$id}_{$revision}")
    );

    try {
        $bot->sendMessage(
            __('bot.cb_ask_comment', ['id' => $id], $lang),
            parse_mode: 'HTML',
            reply_markup: $kb
        );
    } catch (\Exception $e) {
        \Illuminate\Support\Facades\Log::warning('Ask comment send failed: '.$e->getMessage());
    }
});

$bot->onCallbackQueryData('approve_skip:{id}', function (Nutgram $bot, $id) {
    [$id, $revision] = array_pad(explode('_', (string) $id, 2), 2, '1');
    $id = (int) $id;
    $revision = (int) $revision;
    try {
        $bot->answerCallbackQuery();
    } catch (\Exception) {
    }
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }
    finalizeApproval($bot, (int) $id, null, $revision);
});

$bot->onCallbackQueryData('approve_comment:{id}', function (Nutgram $bot, $id) {
    [$id, $revision] = array_pad(explode('_', (string) $id, 2), 2, '1');
    $id = (int) $id;
    $revision = (int) $revision;
    try {
        $bot->answerCallbackQuery();
    } catch (\Exception) {
    }
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }
    \App\Telegram\Conversations\ApproveComment::begin($bot, data: [(int) $id, $revision]);
});

// ─────────────────────────────────────────────────────────────────────────────
// DELETE OWN REQUEST (from "My Requests" detail view)
// ─────────────────────────────────────────────────────────────────────────────

$bot->onCallbackQueryData('del_req:{id}', function (Nutgram $bot, $id) {
    $user = User::where('telegram_id', $bot->userId())->first();
    $lang = $user?->lang ?? 'ru';

    $request = ApprovalRequest::find((int) $id);
    if (! $user || ! $request) {
        $bot->answerCallbackQuery(text: __('bot.cb_request_not_found', [], $lang), show_alert: true);

        return;
    }
    if ($request->applicant_id !== $user->id) {
        $bot->answerCallbackQuery(text: __('bot.del_not_author', [], $lang), show_alert: true);

        return;
    }
    if (! in_array($request->status, ['pending_supply', 'pending_approval', 'rejected', 'cancelled'], true)) {
        $bot->answerCallbackQuery(text: __('bot.del_not_allowed', [], $lang), show_alert: true);

        return;
    }

    $bot->answerCallbackQuery();

    $kb = InlineKeyboardMarkup::make()->addRow(
        InlineKeyboardButton::make(__('bot.del_yes_btn', [], $lang), callback_data: "del_req_yes:{$request->id}"),
        InlineKeyboardButton::make(__('bot.del_no_btn', [], $lang), callback_data: "del_req_no:{$request->id}")
    );

    $bot->sendMessage(
        __('bot.del_confirm_prompt', ['id' => $request->id], $lang),
        parse_mode: 'HTML',
        reply_markup: $kb
    );
});

$bot->onCallbackQueryData('del_req_yes:{id}', function (Nutgram $bot, $id) {
    $user = User::where('telegram_id', $bot->userId())->first();
    $lang = $user?->lang ?? 'ru';

    $request = ApprovalRequest::with(['approvals.approver'])->find((int) $id);
    if (! $user || ! $request) {
        $bot->answerCallbackQuery(text: __('bot.cb_request_not_found', [], $lang), show_alert: true);
        try {
            $bot->editMessageReplyMarkup(reply_markup: null);
        } catch (\Exception) {
        }

        return;
    }
    if ($request->applicant_id !== $user->id) {
        $bot->answerCallbackQuery(text: __('bot.del_not_author', [], $lang), show_alert: true);

        return;
    }
    if (! in_array($request->status, ['pending_supply', 'pending_approval', 'rejected', 'cancelled'], true)) {
        $bot->answerCallbackQuery(text: __('bot.del_not_allowed', [], $lang), show_alert: true);

        return;
    }

    $bot->answerCallbackQuery();
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }

    // Let the approver whose turn it is know the request is gone.
    $currentApproval = $request->approvals
        ->where('level', $request->expected_approval_level)
        ->where('status', 'pending')
        ->first();

    $requestId = $request->id;
    $wasPending = $request->status === 'pending_approval';

    // Soft deletion preserves children and document files for restoration.
    try {
        app(\App\Services\RequestDeletion::class)->delete($request->id, $user);
    } catch (\Illuminate\Validation\ValidationException) {
        $bot->sendMessage(__('bot.del_not_allowed', [], $lang));

        return;
    }

    $bot->sendMessage(__('bot.del_done', ['id' => $requestId], $lang), parse_mode: 'HTML');

    if ($wasPending && $currentApproval?->approver?->telegram_id) {
        try {
            $aprLang = $currentApproval->approver->lang ?? 'ru';
            app(\App\Services\TelegramNotificationMessage::class)->sendTranslated(
                $bot, 'bot.del_approver_notice', ['id' => $requestId, 'name' => $user->full_name ?? ''], $aprLang,
                $currentApproval->approver->telegram_id
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to notify approver about deleted request: '.$e->getMessage());
        }
    }
});

$bot->onCallbackQueryData('del_req_no:{id}', function (Nutgram $bot, $id) {
    $user = User::where('telegram_id', $bot->userId())->first();
    $lang = $user?->lang ?? 'ru';

    $bot->answerCallbackQuery();
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }
    $bot->sendMessage(__('bot.del_cancelled', [], $lang));
});

// ─────────────────────────────────────────────────────────────────────────────
// MENU BUTTON HANDLERS — Russian
// ─────────────────────────────────────────────────────────────────────────────

$bot->onText('📋 Заявка на расход', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    CreateApprovalRequest::begin($bot);
});

$bot->onText('📄 Заявка из PDF', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    CreateApprovalPdfRequest::begin($bot);
});

$bot->onText('📄 Приказ / постановление', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    CreateManagementDocument::begin($bot);
});

$bot->onText('📦 Заявка на снабжение', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    CreateSupplyRequest::begin($bot);
});

$bot->onText('📂 Мои заявки', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    MyRequests::begin($bot);
});

$bot->onText('📊 Статистика', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    ViewStats::begin($bot);
});

$bot->onText('⚙️ Админ панель', function (Nutgram $bot) {
    $user = User::where('telegram_id', $bot->userId())->first();
    if (in_array($user?->role, ['admin', 'superadmin', 'supplier'])) {
        AdminPanel::begin($bot);
    }
});

$bot->onText('✅ Мои согласования', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    MyApprovals::begin($bot);
});

$bot->onText('🗄 Кабинет оплат', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    FinancePanel::begin($bot);
});

// ─────────────────────────────────────────────────────────────────────────────
// MENU BUTTON HANDLERS — English
// ─────────────────────────────────────────────────────────────────────────────

$bot->onText('📋 Expense Request', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    CreateApprovalRequest::begin($bot);
});

$bot->onText('📄 PDF Request', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    CreateApprovalPdfRequest::begin($bot);
});

$bot->onText('📄 Order / resolution', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    CreateManagementDocument::begin($bot);
});

$bot->onText('📦 Supply Request', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    CreateSupplyRequest::begin($bot);
});

$bot->onText('📂 My Requests', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    MyRequests::begin($bot);
});

$bot->onText('📊 Statistics', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    ViewStats::begin($bot);
});

$bot->onText('⚙️ Admin Panel', function (Nutgram $bot) {
    $user = User::where('telegram_id', $bot->userId())->first();
    if (in_array($user?->role, ['admin', 'superadmin', 'supplier'])) {
        AdminPanel::begin($bot);
    }
});

$bot->onText('✅ My Approvals', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    MyApprovals::begin($bot);
});

$bot->onText('🗄 Payment Cabinet', function (Nutgram $bot) {
    if (! checkUserAccess($bot)) {
        return;
    }
    FinancePanel::begin($bot);
});

// ─────────────────────────────────────────────────────────────────────────────
// FALLBACK FOR EXPIRED-DIALOG BUTTONS
// Fires only when no conversation step is active and no specific handler above
// matched — i.e. the tapped inline button (Подтвердить, выбор проекта и т.п.)
// belongs to a dialog whose state was lost (bot restart, cache:clear, TTL).
// Previously such taps were silently ignored and the button looked "broken".
// ─────────────────────────────────────────────────────────────────────────────

$bot->onCallbackQuery(function (Nutgram $bot) {
    $user = User::where('telegram_id', $bot->userId())->first();
    $lang = $user?->lang ?? 'ru';

    try {
        $bot->answerCallbackQuery(text: __('bot.cb_session_expired_full', [], $lang), show_alert: true);
    } catch (\Exception) {
    }
    try {
        $bot->editMessageReplyMarkup(reply_markup: null);
    } catch (\Exception) {
    }
});

// Recovery operations live in ApprovalRouteRecovery; this endpoint only collects input.
$bot->onCallbackQueryData('recover_route:{id}_{revision}', function (Nutgram $bot, $id, $revision) {
    $bot->answerCallbackQuery();
    \App\Telegram\Conversations\RecoverApprovalRoute::begin($bot, (int) $id, (int) $revision);
});
