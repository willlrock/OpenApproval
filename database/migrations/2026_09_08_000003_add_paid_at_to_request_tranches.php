<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_tranches', function (Blueprint $table) {
            // Historical payment dates are unknown: do not infer them from updated_at.
            $table->timestamp('paid_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('request_tranches', fn (Blueprint $table) => $table->dropColumn('paid_at'));
    }
};
