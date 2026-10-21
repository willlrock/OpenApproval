<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;

class DeadlineNotifier
{
    public function __construct(private Nutgram $bot) {}

    /**
     * Шлёт сообщение пользователю в Telegram. Возвращает true если успешно.
     * Молча проглатывает любые ошибки (заблокированный бот, нет telegram_id и т.д.) —
     * ошибка одного получателя не должна валить весь job.
     */
    public function send(User $user, string $textKey, array $params = []): bool
    {
        // Jobs can hold an account loaded before its access was revoked.
        $user = $user->fresh();
        if (! $user?->isApproved() || empty($user->telegram_id)) {
            return false;
        }

        $lang = $user->lang ?? 'ru';
        $pages = app(TelegramNotificationMessage::class)->translatedPages($textKey, $params, $lang);
        $keyboard = null;
        if (str_starts_with($textKey, 'bot.dl_') && ! empty($params['id'])) {
            $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup::make()->addRow(
                \SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton::make(
                    __('notifications.open_request', [], $lang), url: route('requests.detail', $params['id'])
                )
            );
        }

        try {
            foreach ($pages as $index => $page) {
                $this->bot->sendMessage(
                    text: $page,
                    chat_id: $user->telegram_id,
                    parse_mode: 'HTML',
                    reply_markup: $index === array_key_last($pages) ? $keyboard : null,
                );
            }

            return true;
        } catch (\Throwable $e) {
            // Логирование само может упасть (например, нет прав на laravel.log).
            // Не дадим этому уронить весь job — глотаем любую ошибку логгера.
            try {
                Log::warning('DeadlineNotifier send failed', [
                    'user_id' => $user->id,
                    'telegram_id' => $user->telegram_id,
                    'key' => $textKey,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // ignore
            }

            return false;
        }
    }

    /**
     * Шлёт всем пользователям с одной из перечисленных ролей.
     * Используется для эскалации/массовых уведомлений (фин.директор, админы и т.д.).
     */
    public function sendToRoles(array $roleNames, string $textKey, array $params = [], ?int $projectId = null): int
    {
        $sent = 0;
        $users = User::query()
            ->whereHas('roleRelation', fn ($q) => $q->whereIn('name', $roleNames))
            ->whereNotNull('telegram_id')
            ->where('account_status', 'approved')->where('is_active', true)
            ->when($projectId !== null, fn ($q) => $q->where(function ($q) use ($projectId) {
                $q->whereHas('roleRelation', fn ($role) => $role->whereIn('name', ['admin', 'superadmin']))
                    ->orWhereHas('projects', fn ($project) => $project->where('projects.id', $projectId));
            }))
            ->get();

        foreach ($users as $user) {
            if ($this->send($user, $textKey, $params)) {
                $sent++;
            }
        }

        return $sent;
    }
}
