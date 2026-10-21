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
         Schema::create('request_approvals', function (Blueprint $table) {
             $table->id();
             $table->unsignedBigInteger('approval_request_id');
             $table->unsignedBigInteger('approver_id');
             $table->integer('level');
             $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
             $table->text('comment')->nullable();
             $table->timestamp('action_date')->nullable();
             $table->timestamps();

             $table->foreign('approver_id')->references('id')->on('users')->cascadeOnDelete();
             $table->foreign('approval_request_id')->references('id')->on('approval_requests')->cascadeOnDelete();
         });
     }

     /**
      * Reverse the migrations.
      */
     public function down(): void
     {
         Schema::dropIfExists('request_approvals');
     }
 };
