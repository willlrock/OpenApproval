<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_approvals', function (Blueprint $table) {
            $table->string('signature_snapshot_path')->nullable()->after('comment');
            $table->string('signature_sha256', 64)->nullable()->after('signature_snapshot_path');
        });
    }

    public function down(): void
    {
        Schema::table('request_approvals', function (Blueprint $table) {
            $table->dropColumn(['signature_snapshot_path', 'signature_sha256']);
        });
    }
};
