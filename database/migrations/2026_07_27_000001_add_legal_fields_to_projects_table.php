<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('name');
            $table->string('inn', 32)->nullable()->after('company_name');
            $table->string('bank_account', 64)->nullable()->after('inn');
            $table->string('bank_mfo', 32)->nullable()->after('bank_account');
            $table->text('aliases')->nullable()->after('bank_mfo');

            $table->index('inn');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['inn']);
            $table->dropColumn([
                'company_name',
                'inn',
                'bank_account',
                'bank_mfo',
                'aliases',
            ]);
        });
    }
};
