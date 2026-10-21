<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Helpers\BotLang;
use App\Models\ApprovalRequest;
use App\Models\Project;
use App\Models\User;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class ViewStats extends Conversation
{
    use ConversationHelpers;

    public function start(Nutgram $bot)
    {
        $user = User::where('telegram_id', $bot->userId())->first();
        if (! $user) {
            $bot->sendMessage(__('bot.user_not_found_start', [], 'ru'));
            $this->end();

            return;
        }

        $lang = $user->lang ?? 'ru';

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.stats_general_btn', [], $lang), callback_data: 'stats:general')
            );

        $projects = Project::whereIn('id', app(\App\Services\ApprovalRequestAccess::class)->scope(ApprovalRequest::query(), $user)->select('project_id'))->get();
        foreach ($projects as $project) {
            $keyboard->addRow(
                InlineKeyboardButton::make("📁 {$project->name}", callback_data: "stats:project:{$project->id}")
            );
        }

        $keyboard->addRow(
            InlineKeyboardButton::make(__('bot.stats_month_btn', [], $lang), callback_data: 'stats:month'),
            InlineKeyboardButton::make(__('bot.stats_year_btn', [], $lang), callback_data: 'stats:year')
        );

        $bot->sendMessage(__('bot.stats_title', [], $lang), parse_mode: 'HTML', reply_markup: $keyboard);
        $this->next('handleChoice');
    }

    public function handleChoice(Nutgram $bot)
    {
        if (! $bot->isCallbackQuery()) {
            $lang = BotLang::getLang($bot);
            $bot->sendMessage(__('bot.select_from_btns', [], $lang));

            return;
        }

        $data = $bot->callbackQuery()->data;
        $bot->answerCallbackQuery();

        $user = User::where('telegram_id', $bot->userId())->first();
        $isAdmin = in_array($user->role, ['admin', 'superadmin']);

        if ($data === 'stats:general') {
            $this->showGeneralStats($bot, $user, $isAdmin);
        } elseif (str_starts_with($data, 'stats:project:')) {
            $projectId = (int) explode(':', $data)[2];
            $this->showProjectStats($bot, $user, $isAdmin, $projectId);
        } elseif ($data === 'stats:month') {
            $this->showPeriodStats($bot, $user, $isAdmin, 'month');
        } elseif ($data === 'stats:year') {
            $this->showPeriodStats($bot, $user, $isAdmin, 'year');
        }

        $this->sendMenu($bot);
        $this->end();
    }

    protected function showGeneralStats(Nutgram $bot, User $user, bool $isAdmin)
    {
        $this->showMetrics($bot, $user);
    }

    protected function showProjectStats(Nutgram $bot, User $user, bool $isAdmin, int $projectId)
    {
        $this->showMetrics($bot, $user, ['project_id' => $projectId]);
    }

    protected function showPeriodStats(Nutgram $bot, User $user, bool $isAdmin, string $period)
    {
        $this->showMetrics($bot, $user, ['date_from' => ($period === 'month' ? now()->startOfMonth() : now()->startOfYear())->toDateString(), 'date_to' => today()->toDateString()]);
    }

    private function showMetrics(Nutgram $bot, User $user, array $filters = []): void
    {
        $data = app(\App\Services\DashboardMetrics::class)->forUser($user->fresh(), $filters);
        $english = $user->lang === 'en';
        $text = ($english ? 'Visible requests: ' : 'Доступных заявок: ').array_sum($data['counts']);
        foreach ($data['counts'] as $status => $count) {
            $text .= "\n".$status.': '.$count;
        }
        $labels = $english ? ['approved' => 'Approved', 'paid' => 'Paid', 'remaining' => 'Remaining', 'overdue' => 'Overdue']
            : ['approved' => 'Согласовано', 'paid' => 'Оплачено', 'remaining' => 'Остаток', 'overdue' => 'Просрочено'];
        foreach ($data['financialSummary'] as $currency => $values) {
            $text .= "\n\n".$currency;
            foreach ($labels as $key => $label) {
                $text .= "\n".$label.': '.number_format($values[$key], 2, '.', ' ');
            }
        }
        $text .= "\n\n".($english ? 'Payments recorded in period:' : 'Платежи, отмеченные за период:');
        foreach ($data['paymentActivity'] as $currency => $amount) {
            $text .= "\n".$currency.': '.number_format($amount, 2, '.', ' ');
        }
        $bot->sendMessage($text);
    }
}
