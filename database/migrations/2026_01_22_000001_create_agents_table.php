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
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->index();  // Multi-tenant support
            $table->string('name');
            $table->string('product_name');
            $table->string('product_url')->nullable();
            $table->string('product_image')->nullable();
            $table->enum('mode', ['b2c', 'b2b', 'both'])->default('b2c');
            $table->enum('config_type', ['auto', 'advanced'])->default('auto');
            $table->enum('status', ['idle', 'running', 'completed', 'error'])->default('idle');
            $table->boolean('is_active')->default(false);
            $table->json('platforms')->nullable();           // ['instagram', 'tiktok', 'twitter', 'linkedin']
            $table->json('platform_sub_options')->nullable(); // {instagram: ['hashtags', 'posts']}
            $table->json('hashtags')->nullable();            // ['#fitness', '#workout']
            $table->json('targeting')->nullable();           // {minFollowers, maxFollowers, excludeVerified}
            $table->unsignedInteger('prospect_count')->default(0);
            $table->unsignedInteger('search_rate')->default(0);
            $table->timestamp('last_run')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
