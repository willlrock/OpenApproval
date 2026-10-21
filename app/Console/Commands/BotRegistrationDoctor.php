<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use SergiX44\Nutgram\Nutgram;

/**
 * Diagnoses and repairs users stuck in the Telegram registration wizard.
 *
 * A user gets stuck when the RegisterUser conversation stays cached at a step
 * they cannot complete. Their `users` row keeps account_status = 'new', so it
 * never shows up in the default registrations list.
 */
class BotRegistrationDoctor extends Command
{
    protected $signature = 'bot:registration-doctor
                            {user? : telegram_id, phone or @username of a specific user}
                            {--reset : Drop the cached conversation so the user can /start over}
                            {--reopen : Send a rejected account back to "new" so the user can register again}
                            {--purge : Delete the unfinished (account_status=new) user row as well}
                            {--stale=0 : With no user argument, only list rows older than N hours}';

    protected $description = 'List, reset or purge users stuck in the bot registration wizard';

    public function handle(Nutgram $bot): int
    {
        if ($this->option('reopen') && $this->option('purge')) {
            $this->error('--reopen and --purge are mutually exclusive.');
            return self::FAILURE;
        }

        $identifier = $this->argument('user');

        $users = $identifier
            ? $this->findByIdentifier($identifier)
            : $this->findStuck((int) $this->option('stale'));

        if ($users->isEmpty()) {
            $this->info('Nothing found.');
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'telegram_id', 'full_name', 'phone', 'status', 'signature', 'created_at'],
            $users->map(fn (User $u) => [
                $u->id,
                $u->telegram_id,
                $u->full_name ?? '—',
                $u->phone ?? '—',
                $u->account_status,
                $u->signature_path ? 'yes' : 'no',
                $u->created_at?->format('Y-m-d H:i'),
            ])->all()
        );

        if (!$this->option('reset') && !$this->option('reopen') && !$this->option('purge')) {
            $this->line('Add --reset to clear the stuck conversation, --reopen to unblock a rejected account, --purge to delete the row.');
            return self::SUCCESS;
        }

        foreach ($users as $user) {
            if (!$user->telegram_id) {
                $this->warn("User {$user->id} has no telegram_id — skipping conversation reset.");
            } elseif ($this->option('reset') || $this->option('reopen')) {
                // Private chat: chat_id equals user_id.
                $bot->endConversation((int) $user->telegram_id, (int) $user->telegram_id, null);
                $this->info("Conversation cleared for telegram_id {$user->telegram_id}.");
            }

            if ($this->option('reopen')) {
                if ($user->account_status === 'approved') {
                    $this->warn("User {$user->id} is already approved — skipping reopen.");
                } else {
                    $user->update(['account_status' => 'new']);
                    $this->info("User {$user->id} reopened — they can now run /start and register again.");
                }
            }

            if ($this->option('purge')) {
                if ($user->account_status !== 'new') {
                    $this->warn("User {$user->id} is '{$user->account_status}', not 'new' — refusing to delete.");
                    continue;
                }
                $user->delete();
                $this->info("Deleted unfinished user {$user->id}.");
            }
        }

        return self::SUCCESS;
    }

    protected function findByIdentifier(string $identifier)
    {
        $digits = preg_replace('/\D/', '', $identifier);

        return User::query()
            ->when($digits !== '', fn ($q) => $q
                ->where('telegram_id', $digits)
                ->orWhere('phone', 'like', "%{$digits}%"))
            ->when($digits === '', fn ($q) => $q
                ->where('username', ltrim($identifier, '@'))
                ->orWhere('full_name', 'like', "%{$identifier}%"))
            ->get();
    }

    protected function findStuck(int $staleHours)
    {
        return User::where('account_status', 'new')
            ->when($staleHours > 0, fn ($q) => $q->where('created_at', '<', now()->subHours($staleHours)))
            ->orderBy('created_at')
            ->get();
    }
}
