<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow multiple stores to use the same Twilio Sandbox number (+14155238886)
 * by removing the unique constraint on twilio_phone_number.
 * 
 * For production, each store should have a unique number, but sandbox mode
 * allows shared demo numbers.
 */
return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('whatsapp_store_configs', function (Blueprint $table) {
            // Drop the unique index on twilio_phone_number
            $table->dropUnique(['twilio_phone_number']);

            // Keep the regular index for lookups
            // (already exists from original migration, but ensure it's there)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_store_configs', function (Blueprint $table) {
            // Re-add unique constraint
            $table->unique('twilio_phone_number');
        });
    }
};
