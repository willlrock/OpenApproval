<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('request_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_request_id');
            $table->string('file_type'); // pdf, jpg, docx
            $table->string('file_path')->nullable();
            $table->string('telegram_file_id')->nullable();
            $table->timestamps();

            $table->foreign('approval_request_id')->references('id')->on('approval_requests')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('request_attachments');
    }
};
