<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PasswordChangeService;
use App\Services\UserAdministration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResetAccountPassword extends Command
{
    protected $signature = 'account:reset-password {--phone= : Phone of an existing approved account}';

    protected $description = 'Issue a unique temporary password from the trusted server console and revoke existing web sessions';

    public function handle(PasswordChangeService $passwords, UserAdministration $administration): int
    {
        $phone = preg_replace('/\D/', '', $this->option('phone') ?: $this->ask('Phone number'));
        if (strlen($phone) < 7 || strlen($phone) > 15) {
            $this->error('A valid phone number is required.');

            return self::INVALID;
        }
        $password = $passwords->temporaryPassword();
        try {
            DB::transaction(function () use ($phone, $password, $administration) {
                $administration->lockAdministrators();
                $ids = app(\App\Services\PhoneIdentity::class)->matches($phone)->modelKeys();
                $users = User::whereKey($ids)->lockForUpdate()->get();
                $currentIds = app(\App\Services\PhoneIdentity::class)->matches($phone)->modelKeys();
                if ($users->count() !== 1 || $currentIds !== $ids || ! $users->first()->isApproved()) {
                    throw ValidationException::withMessages(['phone' => 'Exactly one approved account must match. No account was changed.']);
                }
                $user = $users->first();
                $user->forceFill([
                    'password' => $password, 'password_change_required' => true, 'password_change_due_at' => null,
                    'remember_token' => Str::random(60), 'telegram_link_token_hash' => null, 'telegram_link_expires_at' => null,
                ])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('web_password_recoveries')->where('user_id', $user->id)->delete();
            });
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Existing web sessions revoked. Deliver this password privately; personal password change is required on sign-in. Roles, Telegram and 2FA are preserved.');
        $this->line('Temporary password: '.$password);

        return self::SUCCESS;
    }
}
