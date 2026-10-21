<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:webhook
                            {action=set : Action to perform (set, delete, info)}
                            {--url= : Custom webhook URL (defaults to TELEGRAM_WEBHOOK_URL or APP_URL/telegram/webhook)}';

    protected $description = 'Manage Telegram bot webhook (set, delete, or get info)';

    public function handle(Nutgram $bot): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'set' => $this->setWebhook($bot),
            'delete' => $this->deleteWebhook($bot),
            'info' => $this->getWebhookInfo($bot),
            default => $this->invalidAction(),
        };
    }

    protected function setWebhook(Nutgram $bot): int
    {
        $url = $this->option('url') ?: config('nutgram.webhook_url') ?: rtrim(config('app.url'), '/').'/telegram/webhook';
        $secret = config('nutgram.webhook_secret');
        if (! is_string($secret) || ! preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $secret)
            || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            $this->error('Configure an HTTPS webhook URL and TELEGRAM_WEBHOOK_SECRET (32-256 letters, digits, _ or -).');

            return self::FAILURE;
        }

        $this->info("Setting webhook to: {$url}");

        try {
            $registered = $bot->setWebhook(
                url: $url,
                allowed_updates: ['message', 'callback_query'],
                drop_pending_updates: false,
                secret_token: $secret,
            );
            if ($registered !== true) {
                throw new \RuntimeException('Telegram did not confirm registration.');
            }

            $this->info('✅ Webhook set successfully!');

            // Verify
            $info = $bot->getWebhookInfo();
            if ($info->url !== $url) {
                throw new \RuntimeException('Telegram returned a different webhook endpoint.');
            }
            $this->table(
                ['Property', 'Value'],
                [
                    ['URL', $info->url],
                    ['Pending updates', $info->pending_update_count ?? 0],
                    ['Max connections', $info->max_connections ?? 'default'],
                ]
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to set webhook. Check token, HTTPS endpoint and Telegram connectivity.');

            return self::FAILURE;
        }
    }

    protected function deleteWebhook(Nutgram $bot): int
    {
        try {
            $bot->deleteWebhook(drop_pending_updates: false);
            $this->info('✅ Webhook deleted successfully!');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to delete webhook. Check token and Telegram connectivity.');

            return self::FAILURE;
        }
    }

    protected function getWebhookInfo(Nutgram $bot): int
    {
        try {
            $info = $bot->getWebhookInfo();

            $this->table(
                ['Property', 'Value'],
                [
                    ['URL', $info->url ?: '(not set)'],
                    ['Has custom certificate', $info->has_custom_certificate ? 'Yes' : 'No'],
                    ['Pending updates', $info->pending_update_count ?? 0],
                    ['Max connections', $info->max_connections ?? 'default'],
                    ['IP address', $info->ip_address ?? '—'],
                    ['Last error date', $info->last_error_date ? date('Y-m-d H:i:s', $info->last_error_date) : '—'],
                    ['Last error message', $info->last_error_message ?? '—'],
                ]
            );

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to get webhook info. Check token and Telegram connectivity.');

            return self::FAILURE;
        }
    }

    protected function invalidAction(): int
    {
        $this->error('Invalid action. Use: set, delete, or info');

        return self::INVALID;
    }
}
