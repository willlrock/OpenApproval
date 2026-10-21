<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ShiftTimestampsTimezone extends Command
{
    protected $signature = 'timezone:shift
        {--hours=5 : Hours to add to every timestamp (use 5 when switching UTC -> Asia/Tashkent)}
        {--dry-run : Show what would change without writing}
        {--force : Skip the backup confirmation prompt}';

    protected $description = 'Shifts every datetime column in app tables by N hours. Run ONCE right after changing config/app.php timezone — and only on a fresh backup.';

    /**
     * Authoritative list — generated from information_schema on 2026-05-07.
     * Pivot/log tables included so foreign-key timestamps stay consistent.
     */
    private array $tables = [
        'approval_requests'      => ['created_at', 'updated_at', 'deadline_from_supplier', 'deadline_date', 'factual_delivery_date', 'payment_commitment_date'],
        'contractors'            => ['created_at', 'updated_at'],
        'department_user'        => ['created_at', 'updated_at'],
        'departments'            => ['created_at', 'updated_at'],
        'failed_jobs'            => ['failed_at'],
        'password_reset_tokens'  => ['created_at'],
        'project_user'           => ['created_at', 'updated_at'],
        'projects'               => ['created_at', 'updated_at'],
        'request_approvals'      => ['action_date', 'created_at', 'notified_at', 'updated_at'],
        'request_attachments'    => ['created_at', 'updated_at'],
        'request_items'          => ['created_at', 'updated_at'],
        'request_tranches'       => ['created_at', 'due_date', 'updated_at'],
        'roles'                  => ['created_at', 'updated_at'],
        'suppliers'              => ['created_at', 'updated_at'],
        'users'                  => ['created_at', 'updated_at'],
    ];

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        if ($hours === 0) {
            $this->error('--hours must be non-zero');
            return self::FAILURE;
        }

        $dry      = (bool) $this->option('dry-run');
        $driver   = DB::connection()->getDriverName();
        $database = DB::connection()->getDatabaseName();

        if (!in_array($driver, ['pgsql', 'mysql'], true)) {
            $this->error("Unsupported DB driver: {$driver}. Only pgsql and mysql are wired up.");
            return self::FAILURE;
        }

        $this->warn(sprintf(
            'Plan: %s every datetime column by %+d hours on [%s] db=%s',
            $dry ? 'PREVIEW' : 'SHIFT',
            $hours,
            $driver,
            $database,
        ));

        $totalRows = 0;
        foreach ($this->tables as $table => $cols) {
            $rows = DB::table($table)->count();
            $totalRows += $rows;
            $this->line(sprintf('  %-24s rows=%-7d cols=%s', $table, $rows, implode(',', $cols)));
        }
        $this->line("  total rows: {$totalRows}");

        if ($dry) {
            $this->info('Dry run — no changes written.');
            return self::SUCCESS;
        }

        if (!$this->option('force')) {
            $this->newLine();
            $this->warn('THIS IS DESTRUCTIVE — running it twice will double-shift your data.');
            if (!$this->confirm('Did you take a fresh database backup?', false)) {
                $this->error('Aborted. Take a backup first, then re-run with --force or confirm.');
                return self::FAILURE;
            }
            if (!$this->confirm("Apply {$hours}-hour shift on db [{$database}] now?", false)) {
                $this->error('Aborted by user.');
                return self::FAILURE;
            }
        }

        $totalAffected = 0;

        DB::transaction(function () use ($hours, $driver, &$totalAffected) {
            foreach ($this->tables as $table => $cols) {
                foreach ($cols as $col) {
                    $sql = match ($driver) {
                        'pgsql' => sprintf(
                            'UPDATE "%s" SET "%s" = "%s" + INTERVAL \'%d hours\' WHERE "%s" IS NOT NULL',
                            $table, $col, $col, $hours, $col
                        ),
                        'mysql' => sprintf(
                            'UPDATE `%s` SET `%s` = DATE_ADD(`%s`, INTERVAL %d HOUR) WHERE `%s` IS NOT NULL',
                            $table, $col, $col, $hours, $col
                        ),
                    };

                    $affected = DB::update($sql);
                    $totalAffected += $affected;
                    $this->line(sprintf('  %-24s.%-26s shifted=%d', $table, $col, $affected));
                }
            }
        });

        $this->newLine();
        $this->info("Done. Rows updated across all columns: {$totalAffected}");
        return self::SUCCESS;
    }
}
