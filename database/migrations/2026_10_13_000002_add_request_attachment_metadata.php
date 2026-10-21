<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_attachments', function (Blueprint $table) {
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('request_attachments', fn (Blueprint $table) => $table->dropColumn(['original_name', 'file_size']));
    }
};
