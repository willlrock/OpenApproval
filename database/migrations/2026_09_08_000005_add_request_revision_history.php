<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_drafts', function (Blueprint $t) {
            $t->string('management_preview_path')->nullable();
            $t->unsignedInteger('preview_version')->nullable();
            $t->boolean('is_revision')->default(false);
            $t->unsignedInteger('source_revision')->nullable();
        });
        Schema::table('approval_requests', fn (Blueprint $t) => $t->unsignedInteger('revision')->default(1));
        Schema::create('request_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('snapshot');
            $t->timestamp('created_at');
            $t->unique(['approval_request_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_revisions');
        Schema::table('approval_requests', fn (Blueprint $t) => $t->dropColumn('revision'));
        Schema::table('request_drafts', fn (Blueprint $t) => $t->dropColumn(['is_revision', 'source_revision', 'management_preview_path', 'preview_version']));
    }
};
