<?php

namespace App\Telegram\Conversations;

use App\Models\User;
use App\Services\ApprovalRouteRecovery;
use App\Services\TelegramNotificationMessage;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class RecoverApprovalRoute extends Conversation
{
    public int $requestId;

    public int $revision;

    public int $sequence;

    public array $approvers = [];

    public bool $saved = false;

    public int $page = 0;

    public function start(Nutgram $bot, int $id, int $revision): void
    {
        $this->requestId = $id;
        try {
            $data = app(ApprovalRouteRecovery::class)->editor($id, $this->actor($bot));
            abort_unless($data['revision'] === $revision, 409);
            $this->revision = $revision;
            $this->sequence = $data['sequence'];
            $this->approvers = $data['draft']['approvers'] ?? $data['route']->pluck('id')->all();
            $this->saved = $data['draft'] !== null;
            $this->show($bot, $data);
        } catch (\Throwable $e) {
            $this->error($bot, $e);
        }
    }

    private function actor(Nutgram $bot): User
    {
        return User::where('telegram_id', $bot->userId())->firstOrFail();
    }

    private function show(Nutgram $bot, array $data): void
    {
        $lang = $this->actor($bot)->lang ?? 'ru';
        $lines = [__('notifications.edit_approvers', [], $lang).' #'.$this->requestId];
        foreach ($data['unavailable'] as $person) {
            $lines[] = __('notifications.approver_unavailable', ['name' => $person['name']], $lang);
        }
        $lines[] = __('notifications.route_recovery_required', [], $lang);
        $lines[] = __('notifications.route_history_preserved', [], $lang);
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($this->approvers as $index => $id) {
            $eligible = $data['eligible']->firstWhere('id', $id);
            $person = $eligible?->full_name ?? $data['route']->firstWhere('id', $id)['name'] ?? '#'.$id;
            $lines[] = ($index + 1).'. '.$person.($eligible ? '' : ' · '.__('notifications.unavailable', [], $lang));
            $buttons = [InlineKeyboardButton::make('✕ '.mb_substr($person, 0, 35), callback_data: $this->callback('remove', $id))];
            if ($index > 0) {
                $buttons[] = InlineKeyboardButton::make('↑', callback_data: $this->callback('up', $id));
            }
            $keyboard->addRow(...$buttons);
        }
        $available = $data['eligible']->reject(fn ($u) => in_array($u->id, $this->approvers, true))->values();
        $this->page = min($this->page, max(0, (int) ceil($available->count() / 8) - 1));
        foreach ($available->slice($this->page * 8, 8) as $person) {
            $keyboard->addRow(InlineKeyboardButton::make('+ '.mb_substr($person->full_name, 0, 40), callback_data: $this->callback('add', $person->id)));
        }
        if ($available->count() > 8) {
            $keyboard->addRow(InlineKeyboardButton::make('←', callback_data: $this->callback('page', max(0, $this->page - 1))),
                InlineKeyboardButton::make('→', callback_data: $this->callback('page', $this->page + 1)));
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('notifications.save_route', [], $lang), callback_data: $this->callback('save')));
        if ($this->saved) {
            $keyboard->addRow(InlineKeyboardButton::make(__('notifications.resubmit_route', [], $lang), callback_data: $this->callback('submit')));
        }
        $keyboard->addRow(InlineKeyboardButton::make(__('notifications.open_request', [], $lang), url: route('requests.detail', $this->requestId)));
        $pages = app(TelegramNotificationMessage::class)->pages(implode("\n", $lines));
        foreach ($pages as $i => $page) {
            $bot->sendMessage($page, parse_mode: 'HTML', reply_markup: $i === array_key_last($pages) ? $keyboard : null);
        }
        $this->next('handle');
    }

    private function callback(string $action, int $value = 0): string
    {
        return "route:{$this->requestId}:{$this->revision}:{$this->sequence}:{$action}:{$value}";
    }

    public function handle(Nutgram $bot): void
    {
        if (! $bot->isCallbackQuery()) {
            return;
        }
        $bot->answerCallbackQuery();
        $parts = explode(':', $bot->callbackQuery()->data ?? '');
        if (count($parts) !== 6 || $parts[0] !== 'route' || (int) $parts[1] !== $this->requestId
            || (int) $parts[2] !== $this->revision || (int) $parts[3] !== $this->sequence) {
            return;
        }
        try {
            $service = app(ApprovalRouteRecovery::class);
            $actor = $this->actor($bot);
            $data = $service->editor($this->requestId, $actor);
            abort_unless($data['revision'] === $this->revision && $data['sequence'] === $this->sequence, 409);
            $action = $parts[4];
            $value = (int) $parts[5];
            $input = ['revision' => $this->revision, 'sequence' => $this->sequence];
            if ($action === 'submit' && $this->saved) {
                $service->submit($this->requestId, $actor, $input);
                $bot->sendMessage(__('notifications.route_resubmitted', [], $actor->lang ?? 'ru'));
                $this->end();

                return;
            }
            if ($action === 'save') {
                $service->save($this->requestId, $actor, $input + ['approvers' => $this->approvers]);
                $data = $service->editor($this->requestId, $actor);
                $this->sequence = $data['sequence'];
                $this->saved = true;
            } elseif ($action === 'add' && count($this->approvers) < 30 && ! in_array($value, $this->approvers, true)
                && $data['eligible']->contains('id', $value)) {
                $this->approvers[] = $value;
                $this->saved = false;
            } elseif ($action === 'remove') {
                $this->approvers = array_values(array_diff($this->approvers, [$value]));
                $this->saved = false;
            } elseif ($action === 'up') {
                $index = array_search($value, $this->approvers, true);
                if ($index !== false && $index > 0) {
                    [$this->approvers[$index - 1], $this->approvers[$index]] = [$this->approvers[$index], $this->approvers[$index - 1]];
                    $this->saved = false;
                }
            } elseif ($action === 'page') {
                $this->page = max(0, $value);
            }
            $this->show($bot, $data);
        } catch (\Throwable $e) {
            $this->error($bot, $e);
        }
    }

    private function error(Nutgram $bot, \Throwable $e): void
    {
        $text = $e instanceof ValidationException ? implode("\n", array_merge(...array_values($e->errors())))
            : __('notifications.route_changed', [], $this->actor($bot)->lang ?? 'ru');
        foreach (app(TelegramNotificationMessage::class)->pages($text) as $page) {
            $bot->sendMessage($page, parse_mode: 'HTML');
        }
        $this->end();
    }
}
