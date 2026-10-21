<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_tranches', fn (Blueprint $t) => $t->string('payment_proof_telegram_id')->nullable());
        // Legacy payment_proof also contains user-entered text. Its origin cannot
        // be inferred from its shape; backfill only after verifying the upload.
        Schema::table('approval_requests', function (Blueprint $t) {
            foreach (['applicant_id', 'supply_applicant_id', 'project_id'] as $column) {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($column === 'project_id' ? 'projects' : 'users')->restrictOnDelete();
            }
            $t->uuid('submission_key')->nullable()->unique();
            $t->text('cancellation_reason')->nullable();
            $t->unsignedBigInteger('cancelled_by')->nullable();
            $t->timestamp('cancelled_at')->nullable();
        });
        Schema::table('request_approvals', function (Blueprint $t) {
            $t->dropForeign(['approver_id']);
            $t->foreign('approver_id')->references('id')->on('users')->restrictOnDelete();
            // Existing duplicate routes must be resolved explicitly before this migration.
            $t->unique(['approval_request_id', 'level'], 'approval_route_level_unique');
            $t->unique(['approval_request_id', 'approver_id'], 'approval_route_user_unique');
        });
        Schema::create('request_effects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('approval_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('revision');
            $t->string('event');
            $t->json('payload');
            $t->json('completed_steps')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('last_attempt_at')->nullable();
            $t->timestamps();
            $t->unique(['approval_request_id', 'revision', 'event']);
            $t->index(['completed_at', 'last_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::table('request_tranches', fn (Blueprint $t) => $t->dropColumn('payment_proof_telegram_id'));
        Schema::dropIfExists('request_effects');
        Schema::table('request_approvals', function (Blueprint $t) {
            $t->dropUnique('approval_route_level_unique');
            $t->dropUnique('approval_route_user_unique');
            $t->dropForeign(['approver_id']);
            $t->foreign('approver_id')->references('id')->on('users')->cascadeOnDelete();
        });
        Schema::table('approval_requests', function (Blueprint $t) {
            $t->dropUnique(['submission_key']);
            $t->dropColumn(['submission_key', 'cancellation_reason', 'cancelled_by', 'cancelled_at']);
            foreach (['applicant_id', 'supply_applicant_id', 'project_id'] as $column) {
                $t->dropForeign([$column]);
                $t->foreign($column)->references('id')->on($column === 'project_id' ? 'projects' : 'users')->cascadeOnDelete();
            }
        });
    }
};
