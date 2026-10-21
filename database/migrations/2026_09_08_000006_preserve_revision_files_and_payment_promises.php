<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_attachments', fn (Blueprint $t) => $t->timestamp('archived_at')->nullable());
        Schema::table('request_draft_files', fn (Blueprint $t) => $t->string('telegram_file_id')->nullable());
        Schema::table('request_drafts', fn (Blueprint $t) => $t->boolean('revision_files_initialized')->default(false));
        Schema::table('request_tranches', fn (Blueprint $t) => $t->date('promised_date')->nullable());
        Schema::create('tranche_deadline_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('request_tranche_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->date('previous_date')->nullable();
            $t->date('promised_date')->nullable();
            $t->text('reason');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tranche_deadline_changes');
        Schema::table('request_tranches', fn (Blueprint $t) => $t->dropColumn('promised_date'));
        Schema::table('request_drafts', fn (Blueprint $t) => $t->dropColumn('revision_files_initialized'));
        Schema::table('request_draft_files', fn (Blueprint $t) => $t->dropColumn('telegram_file_id'));
        Schema::table('request_attachments', fn (Blueprint $t) => $t->dropColumn('archived_at'));
    }
};
