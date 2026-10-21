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
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['supply', 'approval'])->default('supply');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('applicant_id');
            $table->unsignedBigInteger('supply_applicant_id');
            $table->string('name');
            $table->timestamp('deadline_from_user')->nullable();
            $table->timestamp('deadline_from_accountant')->nullable();
            $table->text('description')->nullable();
            $table->string('currency')->nullable();
            $table->decimal('price_per_unit', 15, 2)->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('total_price', 15, 2)->nullable();
            $table->enum('transaction_type', ['cash', 'bank'])->nullable();
            $table->string('supplier_name')->nullable();
            $table->enum('status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->integer('expected_approval_level')->default(1);
            $table->string('pdf_url')->nullable();
            $table->timestamps();

            $table->foreign('supply_applicant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('applicant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
