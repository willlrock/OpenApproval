<?php

namespace App\Http\Controllers\Settings;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use SergiX44\Nutgram\Nutgram;

class PasswordRecoveryController extends Controller
{
    use PasswordValidationRules;

    public function create()
    {
        return Inertia::render('auth/ForgotPassword', ['status' => session('status')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['phone' => 'required|string|max:30']);
        $phone = trim($data['phone']);
        $digits = preg_replace('/\D/', '', $phone);
        $status = 'Если номер привязан к одобренному аккаунту с Telegram, мы отправили туда ссылку. Если сообщения нет, обратитесь к администратору.';
        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return back()->with('status', $status);
        }

        RateLimiter::attempt('password-recovery:'.hash('sha256', $digits), 1, function () use ($phone) {
            $matches = app(\App\Services\PhoneIdentity::class)->matches($phone);
            if ($matches->count() !== 1 || ! $matches->first()->isApproved() || ! $matches->first()->telegram_id) {
                return;
            }
            $user = $matches->first();
            $token = Str::random(64);
            $hash = hash('sha256', $token);
            DB::table('web_password_recoveries')->updateOrInsert(['user_id' => $user->id], [
                'token_hash' => $hash, 'expires_at' => now()->addMinutes(30),
            ]);
            try {
                foreach (app(\App\Services\TelegramNotificationMessage::class)->translatedPages('notifications.password_recovery', ['url' => route('password.reset', $token)], $user->lang ?? 'ru') as $page) {
                    app(Nutgram::class)->sendMessage(text: $page, chat_id: $user->telegram_id, parse_mode: 'HTML');
                }
            } catch (\Throwable) {
                DB::table('web_password_recoveries')->where('token_hash', $hash)->delete();
                \Illuminate\Support\Facades\Log::warning('Password recovery delivery failed', ['user_id' => $user->id]);
            }
        }, 60);

        return back()->with('status', $status);
    }

    public function edit(string $token)
    {
        return Inertia::render('auth/ResetPassword', ['token' => $token]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['token' => 'required|string|size:64', 'password' => $this->passwordRules()]);
        DB::transaction(function () use ($data) {
            $recovery = DB::table('web_password_recoveries')->where('token_hash', hash('sha256', $data['token']))->lockForUpdate()->first();
            if (! $recovery || now()->gte($recovery->expires_at)) {
                throw ValidationException::withMessages(['token' => 'Ссылка недействительна или истекла. Запросите новую.']);
            }
            $user = User::whereKey($recovery->user_id)->lockForUpdate()->firstOrFail();
            if (! $user->isApproved()) {
                throw ValidationException::withMessages(['token' => 'Аккаунт не одобрен.']);
            }
            $user->forceFill([
                'password' => $data['password'], 'password_change_required' => false, 'password_change_due_at' => null,
                'telegram_link_token_hash' => null, 'telegram_link_expires_at' => null,
            ])->save();
            $user->setRememberToken(Str::random(60));
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('web_password_recoveries')->where('user_id', $user->id)->delete();
        });

        return redirect()->route('login')->with('status', 'Пароль изменён. Войдите с новым паролем.');
    }
}
