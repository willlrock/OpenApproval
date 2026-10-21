<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_boards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('background')->nullable();
            $table->enum('visibility', ['private', 'workspace'])->default('workspace');
            $table->boolean('agile_mode')->default(false);
            $table->json('settings')->nullable();
            $table->integer('position')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('workspace_id')->references('id')->on('tracker_workspaces')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('tracker_board_user', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['board_admin', 'board_member', 'board_observer'])->default('board_member');
            $table->timestamps();

            $table->foreign('board_id')->references('id')->on('tracker_boards')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['board_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_board_user');
        Schema::dropIfExists('tracker_boards');
    }
};
