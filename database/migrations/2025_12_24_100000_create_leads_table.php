<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->index();
            $table->string('platform'); // instagram, twitter, tiktok
            $table->string('external_id');
            $table->string('username');
            $table->string('profile_url');
            $table->json('context')->nullable();
            $table->integer('quality_score')->default(0);
            $table->text('draft_message')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('action_taken_at')->nullable();
            $table->timestamps();

            // Prevent duplicate leads per store/platform
            $table->unique(['store_name', 'platform', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
