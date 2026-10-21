<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->timestamp('deadline_date')->nullable();
        });

        Schema::table('request_approvals', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn('deadline_date');
        });

        Schema::table('request_approvals', function (Blueprint $table) {
            $table->dropColumn('notified_at');
        });
    }
};
