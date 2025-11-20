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
        Schema::create('video_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique(); // Internal ID for tracking
            $table->string('store_id')->index();
            $table->string('product_name');
            $table->text('product_description')->nullable();
            $table->string('product_image_url')->nullable();
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('video_url')->nullable(); // Path to stored video
            $table->string('external_video_url')->nullable(); // Original Google URL (temp)
            $table->text('motion_prompt')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_jobs');
    }
};
