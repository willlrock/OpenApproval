<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_checklists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('card_id');
            $table->string('title');
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->foreign('card_id')->references('id')->on('tracker_cards')->cascadeOnDelete();
        });

        Schema::create('tracker_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('checklist_id');
            $table->string('content');
            $table->boolean('is_completed')->default(false);
            $table->unsignedBigInteger('assignee_id')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->foreign('checklist_id')->references('id')->on('tracker_checklists')->cascadeOnDelete();
            $table->foreign('assignee_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_checklist_items');
        Schema::dropIfExists('tracker_checklists');
    }
};
