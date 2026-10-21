<?php

namespace App\Services;

use App\Models\User;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhoneIdentity
{
    public function matches(string $phone, ?int $exceptId = null): Collection
    {
        $canonical = PhoneNumber::canonical($phone);
        if ($canonical === null) {
            return new Collection;
        }

        // Legacy ambiguous/invalid rows stay unmodified during migration. Check
        // their original values too so no new account can claim a collision.
        return User::where(fn ($q) => $q->where('phone_normalized', $canonical)->orWhereNull('phone_normalized'))
            ->whereNotNull('phone')
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->get()->filter(fn (User $user) => PhoneNumber::canonical($user->phone) === $canonical)->values();
    }

    public function forLogin(string $phone): ?User
    {
        $matches = $this->matches($phone);
        if ($matches->count() === 1) {
            return $matches->first();
        }

        // Preserve exact existing login identifiers until an administrator
        // resolves a legacy ambiguity; never pick an arbitrary matching account.
        return User::where('phone', trim($phone))->first();
    }

    public function persist(Closure $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (UniqueConstraintViolationException $e) {
            if (! preg_match('/users_phone_(?:normalized_)?unique|users\.phone(?:_normalized)?\b/i', $e->errorInfo[2] ?? '')) {
                throw $e;
            }
            throw ValidationException::withMessages(['phone' => 'Этот номер телефона уже используется.']);
        }
    }
}
