<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Изменяем status на string (вместо enum), чтобы поддержать все статусы
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });

        // 2. Делаем supply_applicant_id nullable (при обычных заявках на расход его нет)
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('supply_applicant_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft')->change();
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('supply_applicant_id')->nullable(false)->change();
        });
    }
};
