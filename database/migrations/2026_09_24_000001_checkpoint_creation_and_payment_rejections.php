<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_creation_notifications', function (Blueprint $table) {
            $table->json('completed_steps')->nullable();
        });
        Schema::table('request_tranches', function (Blueprint $table) {
            $table->text('failure_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('request_tranches', fn (Blueprint $table) => $table->dropColumn('failure_reason'));
        Schema::table('request_creation_notifications', fn (Blueprint $table) => $table->dropColumn('completed_steps'));
    }
};
