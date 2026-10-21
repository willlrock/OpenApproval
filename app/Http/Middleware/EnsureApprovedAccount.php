<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureApprovedAccount
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user() && ! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Ваш аккаунт деактивирован. Обратитесь к администратору.');
        }

        // A challenge opened before deactivation must not restore access.
        if ($request->session()->has('login.id')) {
            $loginUser = \App\Models\User::find($request->session()->get('login.id'));
            if ($loginUser && ! $loginUser->is_active) {
                $request->session()->forget(['login.id', 'login.remember']);
                abort(403, 'Ваш аккаунт деактивирован. Обратитесь к администратору.');
            }
        }

        if ($request->user() && ! $request->user()->isApproved() && ! $request->routeIs('logout')) {
            abort(403, 'Ваш аккаунт не одобрен. Обратитесь к администратору.');
        }

        $user = $request->user();
        if ($user && app(\App\Services\PasswordChangeService::class)->mustChange($user)
            && ! $request->routeIs('user-password.*', 'logout', 'password.*')) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => 'Смените временный пароль перед продолжением работы.', 'redirect' => route('user-password.edit')], 403);
            }

            return redirect()->route('user-password.edit');
        }

        return $next($request);
    }
}
