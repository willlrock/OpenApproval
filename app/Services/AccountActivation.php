<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountActivation
{
    public function update(User $actor, int $id, bool $active): void
    {
        $administration = app(UserAdministration::class);
        $administration->transaction($actor, $id, function (User $user) use ($actor, $id, $active, $administration) {
            $administration->assertCanChangeAccess($actor, $user);

            if (! $active && $actor->id === $user->id) {
                throw ValidationException::withMessages(['is_active' => 'Нельзя деактивировать собственный аккаунт.']);
            }

            $administration->protectLastAdministrator($user, $user->role_id, $user->account_status, $active);

            $user->is_active = $active;
            if (! $active) {
                $user->remember_token = Str::random(60);
                $user->forceFill(['telegram_link_token_hash' => null, 'telegram_link_expires_at' => null]);
            }
            $user->save();
            app(ApprovalRouteRecovery::class)->accessChanged($user, $actor);

            if (! $active && config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table(config('session.table', 'sessions'))->where('user_id', $id)->delete();
            }
        });
    }
}
