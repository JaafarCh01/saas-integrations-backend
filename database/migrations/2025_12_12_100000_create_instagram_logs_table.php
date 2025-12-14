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
        Schema::create('instagram_logs', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->index();
            $table->string('unipile_account_id')->index();
            $table->string('chat_id')->index(); // Unipile chat ID
            $table->string('sender_name')->nullable();
            $table->string('sender_username')->nullable();
            $table->text('user_message')->nullable();
            $table->text('ai_response')->nullable();
            $table->enum('status', ['received', 'processed', 'replied', 'error'])->default('received');
            $table->timestamps();

            // Composite indexes for efficient queries
            $table->index(['store_name', 'created_at']);
            $table->index(['chat_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('instagram_logs');
    }
};
