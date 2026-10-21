<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->unsignedBigInteger('card_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('type');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->foreign('board_id')->references('id')->on('tracker_boards')->cascadeOnDelete();
            $table->foreign('card_id')->references('id')->on('tracker_cards')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['board_id', 'created_at']);
            $table->index(['card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_activities');
    }
};
