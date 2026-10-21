<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\PasswordChangeService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BootstrapAdmin extends Command
{
    protected $signature = 'admin:bootstrap {--phone= : Phone number of the first administrator} {--name= : Full name}';

    protected $description = 'Create the first superadmin from the console with a unique temporary password';

    public function handle(PasswordChangeService $passwords): int
    {
        $phone = preg_replace('/\D/', '', $this->option('phone') ?: $this->ask('Phone number'));
        $name = trim($this->option('name') ?: $this->ask('Full name'));
        if (strlen($phone) < 7 || strlen($phone) > 15 || $name === '') {
            $this->error('A valid phone number and full name are required.');

            return self::INVALID;
        }
        $password = $passwords->temporaryPassword();
        try {
            app(\App\Services\PhoneIdentity::class)->persist(function () use ($phone, $name, $password) {
                $role = Role::firstOrCreate(['name' => 'superadmin']);
                Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
                if (User::where('role_id', $role->id)->exists()
                    || app(\App\Services\PhoneIdentity::class)->matches($phone)->isNotEmpty()) {
                    throw ValidationException::withMessages(['admin' => 'A superadmin or this phone already exists; bootstrap never replaces an account.']);
                }
                User::create([
                    'phone' => $phone, 'full_name' => $name, 'role_id' => $role->id,
                    'account_status' => 'approved', 'password' => $password, 'password_change_required' => true,
                ]);
            });
        } catch (ValidationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Superadmin created. Deliver this credential privately; a password change is required on sign-in.');
        $this->line('Temporary password: '.$password);

        return self::SUCCESS;
    }
}
