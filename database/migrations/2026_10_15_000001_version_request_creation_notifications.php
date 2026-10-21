<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_creation_notifications', function (Blueprint $table) {
            $table->unsignedInteger('revision')->default(1);
        });
        DB::table('request_creation_notifications')->update([
            'revision' => DB::raw('(SELECT revision FROM approval_requests WHERE approval_requests.id = request_creation_notifications.approval_request_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('request_creation_notifications', fn (Blueprint $table) => $table->dropColumn('revision'));
    }
};
