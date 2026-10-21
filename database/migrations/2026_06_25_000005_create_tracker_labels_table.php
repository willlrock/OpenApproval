<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_labels', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('board_id');
            $table->string('name');
            $table->string('color')->default('#9ca3af');
            $table->timestamps();

            $table->foreign('board_id')->references('id')->on('tracker_boards')->cascadeOnDelete();
        });

        Schema::create('tracker_card_label', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('card_id');
            $table->unsignedBigInteger('label_id');

            $table->foreign('card_id')->references('id')->on('tracker_cards')->cascadeOnDelete();
            $table->foreign('label_id')->references('id')->on('tracker_labels')->cascadeOnDelete();
            $table->unique(['card_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_card_label');
        Schema::dropIfExists('tracker_labels');
    }
};
