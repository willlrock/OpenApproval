<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->index(['project_id', 'status']);
            $table->index(['applicant_id', 'created_at']);
            $table->index(['supply_applicant_id', 'status']);
            $table->index(['status', 'created_at']);
        });
        Schema::table('request_approvals', fn (Blueprint $table) => $table->index(['approver_id', 'status', 'approval_request_id']));
        Schema::create('operational_heartbeats', function (Blueprint $table) {
            $table->string('component')->primary();
            $table->timestamp('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_heartbeats');
        Schema::table('request_approvals', fn (Blueprint $table) => $table->dropIndex(['approver_id', 'status', 'approval_request_id']));
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'status']);
            $table->dropIndex(['applicant_id', 'created_at']);
            $table->dropIndex(['supply_applicant_id', 'status']);
            $table->dropIndex(['status', 'created_at']);
        });
    }
};
