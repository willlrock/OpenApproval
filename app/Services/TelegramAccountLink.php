<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TelegramAccountLink
{
    public function issue(User $actor): string
    {
        return DB::transaction(function () use ($actor) {
            $user = User::lockForUpdate()->findOrFail($actor->id);
            abort_unless($user->isApproved() && ! $user->telegram_id, 403);
            $token = Str::random(48);
            $user->forceFill([
                'telegram_link_token_hash' => hash('sha256', $token),
                'telegram_link_expires_at' => now()->addMinutes(10),
            ])->save();

            return $token;
        });
    }

    public function consume(string $token, int $telegramId, ?string $username): User
    {
        return DB::transaction(function () use ($token, $telegramId, $username) {
            app(UserAdministration::class)->lockAdministrators();
            $user = strlen($token) === 48
                ? User::where('telegram_link_token_hash', hash('sha256', $token))->lockForUpdate()->first()
                : null;
            if (! $user || ! $user->isApproved() || $user->telegram_id
                || ! $user->telegram_link_expires_at || $user->telegram_link_expires_at->isPast()) {
                throw ValidationException::withMessages(['telegram' => 'Код недействителен или истёк. Создайте новый код в профиле сайта.']);
            }
            $placeholder = User::where('telegram_id', $telegramId)->lockForUpdate()->first();
            if ($placeholder) {
                // Only an untouched /start placeholder can be removed. Never merge
                // a registered account, its permissions, signatures, or history.
                if ($placeholder->account_status !== 'new' || $placeholder->full_name
                    || $placeholder->phone || $placeholder->signature_path || $placeholder->password) {
                    throw ValidationException::withMessages(['telegram' => 'Этот Telegram уже связан с аккаунтом. Обратитесь к администратору.']);
                }
                app(DirectoryDeletion::class)->deleteRegistrationPlaceholder($placeholder->id);
            }
            $user->forceFill([
                'telegram_id' => $telegramId,
                'username' => $username,
                'telegram_link_token_hash' => null,
                'telegram_link_expires_at' => null,
            ])->save();

            return $user;
        });
    }
}
