<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_cards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('list_id');
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->integer('card_number');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('position', 16, 6)->default(0);
            $table->enum('priority', ['none', 'low', 'medium', 'high', 'urgent'])->default('none');
            $table->timestamp('start_date')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->decimal('estimate', 8, 2)->nullable();
            $table->integer('story_points')->nullable();
            $table->enum('cover_type', ['none', 'color', 'attachment'])->default('none');
            $table->string('cover_value')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('board_id')->references('id')->on('tracker_boards')->cascadeOnDelete();
            $table->foreign('list_id')->references('id')->on('tracker_lists')->cascadeOnDelete();
            $table->foreign('parent_id')->references('id')->on('tracker_cards')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['board_id', 'list_id', 'position']);
            $table->unique(['board_id', 'card_number']);
            $table->index('parent_id');
            $table->index('due_date');
        });

        Schema::create('tracker_card_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('card_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->foreign('card_id')->references('id')->on('tracker_cards')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['card_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_card_user');
        Schema::dropIfExists('tracker_cards');
    }
};
