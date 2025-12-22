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
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('store_name');
            $table->string('conversation_id');    // Thread ID or Message-ID
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->string('subject');
            $table->text('user_message')->nullable();
            $table->text('ai_response')->nullable();
            $table->string('message_id')->nullable();  // Original email Message-ID
            $table->string('status')->default('received');
            $table->timestamps();

            $table->index(['store_name', 'conversation_id']);
            $table->index('message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
