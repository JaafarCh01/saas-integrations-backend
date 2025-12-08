<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Makes api_token nullable since it's no longer required when connecting a Twilio account.
     * The api_token was previously required for Devaito API access, but that's now handled separately.
     */
    public function up(): void
    {
        Schema::table('whatsapp_store_configs', function (Blueprint $table) {
            $table->text('api_token')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('whatsapp_store_configs', function (Blueprint $table) {
            $table->text('api_token')->nullable(false)->change();
        });
    }
};

