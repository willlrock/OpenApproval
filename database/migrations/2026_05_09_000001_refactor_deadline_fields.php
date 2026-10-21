<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn(['deadline_from_user', 'deadline_from_accountant']);

            $table->boolean('is_overdue')->default(false);
            $table->index('is_overdue');
            $table->index('deadline_date');
            $table->index('deadline_from_supplier');
        });

        Schema::table('request_approvals', function (Blueprint $table) {
            $table->index(['status', 'notified_at']);
        });

        Schema::table('request_tranches', function (Blueprint $table) {
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('request_tranches', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
        });

        Schema::table('request_approvals', function (Blueprint $table) {
            $table->dropIndex(['status', 'notified_at']);
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropIndex(['deadline_from_supplier']);
            $table->dropIndex(['deadline_date']);
            $table->dropIndex(['is_overdue']);
            $table->dropColumn('is_overdue');

            $table->timestamp('deadline_from_user')->nullable();
            $table->timestamp('deadline_from_accountant')->nullable();
        });
    }
};
