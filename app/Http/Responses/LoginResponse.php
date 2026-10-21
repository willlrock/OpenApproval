<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        $user = $request->user();
        if (app(\App\Services\PasswordChangeService::class)->mustChange($user)) {
            return $request->wantsJson()
                ? response()->json(['two_factor' => false, 'redirect' => route('user-password.edit')])
                : redirect()->route('user-password.edit');
        }

        $home = match ($user->role) {
            'supplier' => '/supply',
            default => '/dashboard',
        };

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended($home);
    }
}
