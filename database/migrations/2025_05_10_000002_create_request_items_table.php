<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('approval_request_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_per_unit', 15, 2)->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('total_price', 15, 2)->nullable();
            $table->timestamps();

            $table->foreign('approval_request_id')->references('id')->on('approval_requests')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_items');
    }
};
