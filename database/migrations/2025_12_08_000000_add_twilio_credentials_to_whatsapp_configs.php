<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds user-provided Twilio credentials to support the new architecture
     * where users connect their own Twilio accounts instead of using system-owned numbers.
     */
    public function up(): void
    {
        Schema::table('whatsapp_store_configs', function (Blueprint $table) {
            // User's Twilio Account SID
            $table->string('twilio_sid')->nullable()->after('store_name');
            // User's Twilio Auth Token (will be encrypted via model accessor)
            $table->text('twilio_token')->nullable()->after('twilio_sid');
        });

        // Make twilio_phone_number nullable since it may not exist when user first connects
        // Using a separate Schema::table call for the change() method
        Schema::table('whatsapp_store_configs', function (Blueprint $table) {
            $table->string('twilio_phone_number')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_store_configs', function (Blueprint $table) {
            $table->dropColumn(['twilio_sid', 'twilio_token']);
        });

        // Restore twilio_phone_number to non-nullable (may fail if there are null values)
        Schema::table('whatsapp_store_configs', function (Blueprint $table) {
            $table->string('twilio_phone_number')->nullable(false)->change();
        });
    }
};

