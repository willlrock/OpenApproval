<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_lists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->string('name');
            $table->integer('position')->default(0);
            $table->enum('status_category', ['backlog', 'todo', 'in_progress', 'done'])->default('todo');
            $table->string('color')->nullable();
            $table->integer('wip_limit')->nullable();
            $table->integer('aging_threshold_days')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->foreign('board_id')->references('id')->on('tracker_boards')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_lists');
    }
};
