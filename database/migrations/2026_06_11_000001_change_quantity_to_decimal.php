<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Excel import allows fractional quantities (e.g. 3.5 kg), but the columns were
 * integer — on PostgreSQL inserting '3.5' throws, silently killing the
 * "Подтвердить" step and leaving orphan requests without items/approvals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->default(1)->change();
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('request_items', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->change();
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->integer('quantity')->default(1)->change();
        });
    }
};
