<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PasswordChangeService
{
    public function usesLegacyPassword(User $user): bool
    {
        return $user->password && \Illuminate\Support\Facades\Cache::remember(
            'legacy-password:'.hash('sha256', $user->password), 86400,
            fn () => Hash::check('admin123', $user->password),
        );
    }

    public function mustChange(User $user): bool
    {
        return $user->password_change_required || $this->usesLegacyPassword($user);
    }

    public function notice(User $user): ?array
    {
        if (! $this->mustChange($user)) {
            return null;
        }

        // Temporary and legacy credentials never grant access to business pages.
        User::whereKey($user->id)->where('password', $user->getRawOriginal('password'))->whereNull('password_change_due_at')->update([
            'password_change_required' => true,
            'password_change_due_at' => now(),
        ]);
        $user->refresh();
        if (! $user->password_change_required || ! $user->password_change_due_at) {
            return null;
        }

        return [
            'deadline' => $user->password_change_due_at->format('d.m.Y H:i'),
            'overdue' => $user->password_change_due_at->isPast(),
        ];
    }

    public function temporaryPassword(): string
    {
        return Str::password(20, symbols: false);
    }
}
