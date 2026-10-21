<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyTelegramWebhook
{
    public function handle(Request $request, Closure $next)
    {
        $secret = config('nutgram.webhook_secret');
        abort_unless(is_string($secret) && preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret)
            && hash_equals($secret, $request->header('X-Telegram-Bot-Api-Secret-Token', '')), 403);

        return $next($request);
    }
}
