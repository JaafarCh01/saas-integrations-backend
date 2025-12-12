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
        Schema::create('instagram_configs', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->unique(); // Devaito databaseName (e.g., "mugstroe")
            $table->string('unipile_account_id')->nullable(); // Unipile's account ID
            $table->string('instagram_username')->nullable(); // Connected Instagram username
            $table->boolean('ai_active')->default(false); // Master AI switch
            $table->text('ai_system_prompt')->nullable(); // Custom AI persona/instructions
            $table->boolean('is_active')->default(true); // Connection status
            $table->timestamps();

            // Indexes for quick lookups
            $table->index('unipile_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instagram_configs');
    }
};
