<?php

namespace App\Telegram\Conversations;

use App\Concerns\ConversationHelpers;
use App\Helpers\BotLang;
use App\Models\Project;
use App\Models\User;
use App\Services\SignatureCleanerService;
use Illuminate\Support\Facades\Storage;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

class RegisterUser extends Conversation
{
    use ConversationHelpers;

    public ?string $full_name = null;

    public ?int $projectId = null;

    public ?int $departmentId = null;

    public ?string $signature_file_id = null;

    public ?string $signature_clean_path = null;

    public ?string $phone = null;

    public string $lang = 'ru';

    public int $signatureAttempts = 0;

    public static function getProjects(): array
    {
        return Project::orderBy('name')->pluck('name', 'id')->toArray();
    }

    public function start(Nutgram $bot)
    {
        $this->askLanguage($bot);
    }

    protected function askLanguage(Nutgram $bot): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🇷🇺 Русский', callback_data: 'lang:ru'),
                InlineKeyboardButton::make('🇺🇸 English', callback_data: 'lang:en')
            );

        $bot->sendMessage(
            "👋 Добро пожаловать в <b>TEG Approval Bot</b>!\n\n".
            'Выберите язык интерфейса / Choose your language:',
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleLanguage');
    }

    public function handleLanguage(Nutgram $bot)
    {
        if (! $bot->isCallbackQuery()) {
            // The user typed instead of tapping (or the keyboard was lost).
            // Re-send the prompt so the step is never a silent dead end.
            if ($this->isCancelled($bot)) {
                return;
            }
            $this->askLanguage($bot);

            return;
        }

        $data = $bot->callbackQuery()->data;
        $bot->answerCallbackQuery();

        if (! str_starts_with($data, 'lang:')) {
            return;
        }
        $this->lang = explode(':', $data)[1];

        $user = User::where('telegram_id', $bot->userId())->first();
        if ($user) {
            $user->update(['lang' => $this->lang]);
        }

        $bot->sendMessage(
            __('bot.reg_start_form', [], $this->lang),
            parse_mode: 'HTML'
        );
        $this->next('handleFullName');
    }

    public function handleFullName(Nutgram $bot)
    {
        $lang = $this->lang ?: BotLang::getLang($bot);
        if ($this->isCancelled($bot)) {
            return;
        }

        $text = $this->validateText($bot, $bot->message()?->text, __('bot.field_full_name', [], $lang), 100);
        if ($text === null) {
            return;
        }

        if (count(explode(' ', trim($text))) < 2) {
            $bot->sendMessage(__('bot.reg_invalid_name', [], $lang));

            return;
        }

        $this->full_name = $text;
        $this->askPhone($bot);
    }

    protected function askPhone(Nutgram $bot)
    {
        $lang = $this->lang ?: BotLang::getLang($bot);

        $keyboard = \SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup::make(
            resize_keyboard: true,
            one_time_keyboard: true
        )->addRow(
            \SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton::make(
                __('bot.reg_send_phone_btn', [], $lang),
                request_contact: true
            )
        );

        $bot->sendMessage(
            __('bot.reg_phone_step', [], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handlePhone');
    }

    public function handlePhone(Nutgram $bot)
    {
        $lang = $this->lang ?: BotLang::getLang($bot);

        if ($bot->message()?->contact) {
            if ($bot->message()->contact->user_id !== $bot->userId()) {
                $bot->sendMessage($lang === 'ru' ? 'Отправьте свой контакт кнопкой ниже.' : 'Share your own contact using the button below.');

                return;
            }
            $this->phone = \App\Support\PhoneNumber::canonical($bot->message()->contact->phone_number) ?? '';
            if (strlen($this->phone) < 7 || strlen($this->phone) > 15) {
                $bot->sendMessage(__('bot.reg_invalid_phone', [], $lang));

                return;
            }
            if ($this->isPhoneTaken($bot)) {
                return;
            }
            $this->askSignature($bot);

            return;
        }

        $text = trim($bot->message()?->text ?? '');
        if ($this->isCancelled($bot)) {
            return;
        }

        $this->phone = \App\Support\PhoneNumber::canonical($text) ?? '';
        if (strlen($this->phone) < 7 || strlen($this->phone) > 15) {
            $bot->sendMessage(__('bot.reg_invalid_phone', [], $lang));

            return;
        }

        if ($this->isPhoneTaken($bot)) {
            return;
        }
        $this->askSignature($bot);
    }

    protected function isPhoneTaken(Nutgram $bot): bool
    {
        $lang = $this->lang ?: BotLang::getLang($bot);

        $existing = app(\App\Services\PhoneIdentity::class)->matches($this->phone)
            ->first(fn ($user) => $user->telegram_id !== $bot->userId());

        if ($existing) {
            $bot->sendMessage($lang === 'ru'
                ? 'Этот номер уже используется. Войдите в свой профиль на сайте и создайте код привязки Telegram, либо обратитесь к администратору.'
                : 'This phone is already in use. Sign in on the website and create a Telegram link code, or contact an administrator.');

            return true;
        }

        return false;
    }

    protected function askSignature(Nutgram $bot)
    {
        $lang = $this->lang ?: BotLang::getLang($bot);

        $bot->sendMessage(
            __('bot.reg_signature_step', [], $lang),
            parse_mode: 'HTML'
        );
        $this->next('handleSignature');
    }

    public function handleSignature(Nutgram $bot)
    {
        $lang = $this->lang ?: BotLang::getLang($bot);
        $message = $bot->message();

        if (! $message?->photo) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.reg_send_photo', [], $lang), parse_mode: 'HTML');

            return;
        }

        $photos = $message->photo;
        $photo = end($photos);
        $this->signature_file_id = $photo->file_id;

        // Clean up any leftover preview from a previous retry
        $this->cleanupTempSignature();

        $bot->sendMessage(__('bot.reg_signature_processing', [], $lang), parse_mode: 'HTML');

        $result = $this->downloadAndCleanSignature($bot);
        $this->signatureAttempts++;

        if ($result === null) {
            // Download itself failed — nothing usable, ask for another photo.
            $bot->sendMessage(__('bot.reg_signature_clean_failed', [], $lang), parse_mode: 'HTML');

            return; // stays on handleSignature — user can resend a photo
        }

        [$path, $wasCleaned] = $result;

        // Cleaning may be unavailable on this host (missing python/opencv) or the
        // photo may be unreadable. Give the user one retry, then fall back to the
        // original photo so registration is never a dead end.
        if (! $wasCleaned && $this->signatureAttempts < 2) {
            Storage::disk('local')->delete($path);
            $bot->sendMessage(__('bot.reg_signature_clean_failed', [], $lang), parse_mode: 'HTML');

            return;
        }

        $this->signature_clean_path = $path;

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make(__('bot.reg_confirm_btn', [], $lang), callback_data: 'reg:confirm'),
                InlineKeyboardButton::make(__('bot.reg_retry_btn', [], $lang), callback_data: 'reg:retry')
            );

        $captionKey = $wasCleaned ? 'bot.reg_signature_cleaned_review' : 'bot.reg_signature_raw_review';

        $abs = Storage::disk('local')->path($path);
        $bot->sendPhoto(
            InputFile::make(fopen($abs, 'r')),
            caption: __($captionKey, [
                'name' => $this->full_name,
                'phone' => $this->phone,
            ], $lang),
            parse_mode: 'HTML',
            reply_markup: $keyboard
        );
        $this->next('handleConfirm');
    }

    /**
     * Downloads the signature photo and tries to clean it.
     *
     * @return array{0:string,1:bool}|null [relativePath, wasCleaned], or null if
     *                                     the photo could not be downloaded/stored at all.
     */
    protected function downloadAndCleanSignature(Nutgram $bot): ?array
    {
        $rawRel = null;

        try {
            $cached = app(\App\Services\TelegramFileCache::class)->resolve($this->signature_file_id);

            $stamp = $bot->userId().'_'.time().'_'.bin2hex(random_bytes(3));
            $rawRel = "signatures/temp/{$stamp}_raw.jpg";
            $cleanRel = "signatures/temp/{$stamp}_clean.png";

            Storage::disk('local')->copy($cached, $rawRel);

            $cleaner = app(SignatureCleanerService::class);
            $rawAbs = Storage::disk('local')->path($rawRel);
            $cleanAbs = Storage::disk('local')->path($cleanRel);

            $ok = $cleaner->clean($rawAbs, $cleanAbs);

            if ($ok && Storage::disk('local')->exists($cleanRel)) {
                Storage::disk('local')->delete($rawRel);

                return [$cleanRel, true];
            }

            // Keep the raw photo as a usable fallback.
            Storage::disk('local')->delete($cleanRel);

            return [$rawRel, false];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Signature processing failed', ['exception_type' => get_class($e)]);
            if ($rawRel && Storage::disk('local')->exists($rawRel)) {
                return [$rawRel, false];
            }

            return null;
        }
    }

    protected function cleanupTempSignature(): void
    {
        if ($this->signature_clean_path && Storage::disk('local')->exists($this->signature_clean_path)) {
            Storage::disk('local')->delete($this->signature_clean_path);
        }
        $this->signature_clean_path = null;
    }

    public function handleConfirm(Nutgram $bot)
    {
        $lang = $this->lang ?: BotLang::getLang($bot);

        if (! $bot->isCallbackQuery()) {
            if ($this->isCancelled($bot)) {
                return;
            }
            $bot->sendMessage(__('bot.reg_confirm_hint', [], $lang), parse_mode: 'HTML');

            return;
        }

        $data = $bot->callbackQuery()->data;
        $bot->answerCallbackQuery();

        if ($data === 'reg:retry') {
            $this->cleanupTempSignature();
            $bot->sendMessage(__('bot.reg_retry_photo', [], $lang));
            $this->next('handleSignature');

            return;
        }

        if ($data !== 'reg:confirm') {
            return;
        }

        $user = User::where('telegram_id', $bot->userId())->first();

        if (! $user || ! in_array($user->account_status, ['new', 'pending'], true)) {
            $this->cleanupTempSignature();
            $bot->sendMessage(__('bot.reg_error_start', [], $lang));
            $this->end();

            return;
        }
        if ($this->isPhoneTaken($bot)) {
            return;
        }

        // Move the cleaned preview from temp/ to its permanent user-keyed location
        $filename = null;
        if ($this->signature_clean_path && Storage::disk('local')->exists($this->signature_clean_path)) {
            $ext = pathinfo($this->signature_clean_path, PATHINFO_EXTENSION) ?: 'png';
            $filename = "signatures/user_{$user->id}_".time().".{$ext}";
            try {
                Storage::disk('local')->move($this->signature_clean_path, $filename);
                $this->signature_clean_path = null;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Signature move failed: '.$e->getMessage());
                $filename = null;
            }
        } else {
            \Illuminate\Support\Facades\Log::warning("Signature missing on confirm for user {$user->id}");
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($user, $filename) {
                $user = User::lockForUpdate()->findOrFail($user->id);
                abort_unless(in_array($user->account_status, ['new', 'pending'], true), 403);
                if (app(\App\Services\PhoneIdentity::class)->matches($this->phone, $user->id)->isNotEmpty()) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['phone' => 'Phone already registered.']);
                }
                $oldSignature = $user->signature_path;
                $user->update([
                    'full_name' => $this->full_name,
                    'signature_path' => $filename,
                    'account_status' => 'pending',
                    'phone' => $this->phone,
                ]);
                if ($oldSignature && $oldSignature !== $filename) {
                    \Illuminate\Support\Facades\DB::afterCommit(fn () => Storage::disk('local')->delete($oldSignature));
                }
            });
        } catch (\Throwable $e) {
            if ($filename) {
                Storage::disk('local')->delete($filename);
            }
            \Illuminate\Support\Facades\Log::warning('Registration could not be saved', ['user_id' => $user->id, 'exception_type' => get_class($e)]);
            $bot->sendMessage('Не удалось сохранить регистрацию. Отправьте /start и повторите; существующий аккаунт можно привязать кодом из профиля сайта.');
            $this->end();

            return;
        }

        $bot->sendMessage(__('bot.reg_submitted', [], $lang), parse_mode: 'HTML');
        $this->notifyAdmins($bot, $user->fresh());

        $this->sendMenu($bot);
        $this->end();
    }

    protected function notifyAdmins(Nutgram $bot, User $user): void
    {
        $admins = User::whereHas('roleRelation', function ($q) {
            $q->whereIn('name', ['admin', 'superadmin']);
        })
            ->where('is_active', true)
            ->whereNotNull('telegram_id')
            ->get();

        foreach ($admins as $admin) {
            $lang = $admin->lang ?? 'ru';
            $message = implode('', app(\App\Services\TelegramNotificationMessage::class)->translatedPages('bot.reg_admin_notify', [
                'name' => $user->full_name,
                'phone' => $user->phone ?? __('bot.reg_phone_not_set', [], $lang),
                'username' => $user->username,
                'id' => $user->id,
            ], $lang));
            $keyboard = InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make(__('bot.reg_approve_btn', [], $lang), callback_data: "reg_approve:{$user->id}"),
                InlineKeyboardButton::make(__('bot.reg_reject_btn', [], $lang), callback_data: "reg_reject:{$user->id}")
            );
            $stream = null;
            try {
                if ($user->signature_path) {
                    $signaturePath = Storage::disk('local')->path($user->signature_path);
                    $stream = fopen($signaturePath, 'r');
                    $bot->sendPhoto(
                        \SergiX44\Nutgram\Telegram\Types\Internal\InputFile::make($stream),
                        chat_id: $admin->telegram_id,
                        caption: $message,
                        parse_mode: 'HTML',
                        reply_markup: $keyboard
                    );
                } else {
                    $bot->sendMessage(
                        $message,
                        chat_id: $admin->telegram_id,
                        parse_mode: 'HTML',
                        reply_markup: $keyboard
                    );
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to notify admin {$admin->id}: " . $e->getMessage());
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }
}
