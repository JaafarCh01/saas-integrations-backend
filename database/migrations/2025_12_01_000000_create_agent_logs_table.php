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
        Schema::create('agent_logs', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->index(); // Devaito databaseName (e.g., "mugstroe")
            $table->string('conversation_id')->index(); // Format: {store_name}_{phone}
            $table->string('customer_phone');
            $table->text('user_message')->nullable();
            $table->text('ai_response')->nullable();
            $table->integer('tokens_used')->default(0);
            $table->decimal('cost_estimate_usd', 8, 5)->default(0);
            $table->enum('status', ['success', 'error'])->default('success');
            $table->timestamps();

            // Composite index for efficient queries
            $table->index(['store_name', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_logs');
    }
};

