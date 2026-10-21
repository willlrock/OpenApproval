<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_tranches', function (Blueprint $table) {
            $table->text('comment')->nullable()->change();
            $table->text('payment_proof')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Older application versions accept text columns too. Keep their width
        // on rollback rather than truncating already saved payment information.
    }
};
