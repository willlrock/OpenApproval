<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Только pgsql — SQLite (тесты) не использует CHECK-констрейнт с этим именем,
        // MySQL это отдельный диалект. Любой не-pgsql драйвер пропускаем.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT IF EXISTS approval_requests_status_check');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check CHECK (status::text = ANY (ARRAY['draft', 'pending_approval', 'approved', 'rejected', 'paid']::text[]))");
    }
};
