<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->string('effective_date_mode')->nullable()->after('document_kind');
            $table->date('effective_date')->nullable()->after('effective_date_mode');
            $table->string('applicant_signature_snapshot_path')->nullable()->after('source_sha256');
            $table->string('applicant_signature_sha256', 64)->nullable()->after('applicant_signature_snapshot_path');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn([
                'effective_date_mode',
                'effective_date',
                'applicant_signature_snapshot_path',
                'applicant_signature_sha256',
            ]);
        });
    }
};
