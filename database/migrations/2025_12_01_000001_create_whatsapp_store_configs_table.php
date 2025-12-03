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
        Schema::create('whatsapp_store_configs', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->unique(); // Devaito databaseName (e.g., "mugstroe")
            $table->string('twilio_phone_number')->unique(); // e.g., "+14155238886"
            $table->text('api_token'); // Encrypted Bearer token for Devaito API
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Index for quick lookups
            $table->index('twilio_phone_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_store_configs');
    }
};

