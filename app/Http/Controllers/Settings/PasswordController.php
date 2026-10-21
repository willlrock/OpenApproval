<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(): Response
    {
        return Inertia::render('settings/Password', [
            'passwordChangeRequired' => app(\App\Services\PasswordChangeService::class)->mustChange(auth()->user()),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => $request->password,
            'password_change_required' => false,
            'password_change_due_at' => null,
            'telegram_link_token_hash' => null,
            'telegram_link_expires_at' => null,
        ])->save();
        $request->user()->setRememberToken(\Illuminate\Support\Str::random(60));
        $request->user()->save();
        \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())->delete();

        return back();
    }
}
