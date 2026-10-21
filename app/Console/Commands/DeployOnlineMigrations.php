<?php

namespace App\Console\Commands;

use App\Support\OnlineMigrationPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeployOnlineMigrations extends Command
{
    protected $signature = 'deployment:migrate {--check : Validate pending migrations without executing them}';

    protected $description = 'Run only reviewed, checksum-pinned migrations compatible with the running release';

    public function handle(): int
    {
        try {
            $migrator = app('migrator');
            if (! $migrator->repositoryExists()) {
                throw new RuntimeException('Migration repository is missing. Use an explicit maintenance deployment.');
            }
            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $pending = array_diff_key($files, array_flip($migrator->getRepository()->getRan()));
            OnlineMigrationPolicy::check($pending, base_path('ops/online-migrations.json'));
            if ($this->option('check') || $pending === []) {
                $this->info(count($pending).' reviewed migrations pending.');

                return self::SUCCESS;
            }
            if (DB::connection()->getDriverName() !== 'pgsql') {
                throw new RuntimeException('Online production migrations require PostgreSQL.');
            }
            // Bound lock acquisition: an ALTER waiting for a lock must not queue
            // ordinary requests behind it indefinitely. Never auto-rollback DB.
            $timeouts = DB::selectOne("SELECT current_setting('lock_timeout') AS lock_timeout, current_setting('statement_timeout') AS statement_timeout");
            DB::statement("SET lock_timeout = '2s'");
            DB::statement("SET statement_timeout = '60s'");
            try {
                $migrator->setOutput($this->output)->run(array_values($pending), ['step' => false]);
            } finally {
                DB::selectOne("SELECT set_config('lock_timeout', ?, false), set_config('statement_timeout', ?, false)", [$timeouts->lock_timeout, $timeouts->statement_timeout]);
            }

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error instanceof RuntimeException ? $error->getMessage() : 'Online migration failed; the previous release remains active. Inspect the migration log.');

            return self::FAILURE;
        }
    }
}
