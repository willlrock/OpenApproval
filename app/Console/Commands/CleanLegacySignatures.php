<?php

namespace App\Console\Commands;

use App\Services\LegacySignatureCleanup;
use Illuminate\Console\Command;

class CleanLegacySignatures extends Command
{
    protected $signature = 'signatures:clean-legacy
        {--apply : Back up originals and apply cleanup; otherwise preview only}
        {--user=* : Limit cleanup to these user IDs}
        {--restore= : Restore original signatures from a backup batch}';

    protected $description = 'Clean legacy user signatures with verified private backups, preserving signed documents';

    public function handle(LegacySignatureCleanup $cleanup): int
    {
        try {
            if ($this->option('restore') && $this->option('user')) {
                throw new \RuntimeException('--user cannot be combined with --restore');
            }
            foreach ($this->option('user') as $id) {
                if (! ctype_digit((string) $id) || (int) $id < 1) {
                    throw new \RuntimeException('--user must be a positive user ID');
                }
            }
            $apply = (bool) $this->option('apply');
            $result = $this->option('restore')
                ? $cleanup->restore($this->option('restore'), $apply)
                : $cleanup->migrate($apply, $this->option('user'));
            $this->line($apply ? 'Signature cleanup result:' : 'Preview only; no signatures changed.');
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if ($result['batch']) {
                $this->info('Private backup: storage/app/private/signature-cleanup/'.$result['batch']);
                $this->line('Rollback: php artisan signatures:clean-legacy --restore='.$result['batch'].' --apply');
            }

            return $result['failed'] ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
