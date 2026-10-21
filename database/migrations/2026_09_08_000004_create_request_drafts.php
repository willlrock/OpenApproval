<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('submitted_request_id')->nullable()->constrained('approval_requests');
            $table->foreignId('source_request_id')->nullable()->constrained('approval_requests');
            $table->timestamps();
        });
        Schema::create('request_draft_files', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('request_draft_id')->constrained('request_drafts')->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->string('file_type');
            $table->timestamps();
        });
        Schema::create('request_creation_notifications', function (Blueprint $table) {
            $table->foreignId('approval_request_id')->primary()->constrained('approval_requests')->cascadeOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_creation_notifications');
        Schema::dropIfExists('request_draft_files');
        Schema::dropIfExists('request_drafts');
    }
};
