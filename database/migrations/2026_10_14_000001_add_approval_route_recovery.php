<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->json('route_unavailable')->nullable();
            $table->json('route_draft')->nullable();
            $table->unsignedInteger('route_recovery_sequence')->default(0);
            $table->timestamp('approval_paused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn(['route_unavailable', 'route_draft', 'route_recovery_sequence', 'approval_paused_at']);
        });
    }
};
