<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE approval_requests DROP CONSTRAINT IF EXISTS approval_requests_type_check');
        }

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->string('type')->default('supply')->change();
        });

        Schema::table('approval_requests', function (Blueprint $table) {
            $table->string('document_kind')->nullable()->after('type');
            $table->string('source_docx_path')->nullable()->after('pdf_url');
            $table->string('preview_pdf_path')->nullable()->after('source_docx_path');
            $table->string('source_sha256', 64)->nullable()->after('preview_pdf_path');
            $table->string('document_number')->nullable()->after('source_sha256');
            $table->unsignedInteger('document_version')->default(1)->after('document_number');
            $table->timestamp('finalized_at')->nullable()->after('document_version');
        });
    }

    public function down(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn([
                'document_kind',
                'source_docx_path',
                'preview_pdf_path',
                'source_sha256',
                'document_number',
                'document_version',
                'finalized_at',
            ]);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type::text = ANY (ARRAY['supply', 'approval']::text[]))");
        }
    }
};
