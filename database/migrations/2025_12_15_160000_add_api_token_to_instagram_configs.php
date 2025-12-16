<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     * 
     * Adds api_token to instagram_configs for Devaito API access.
     * This token is captured from the Bearer header when user connects Instagram.
     */
    public function up(): void
    {
        Schema::table('instagram_configs', function (Blueprint $table) {
            $table->text('api_token')->nullable()->after('ai_system_prompt');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('instagram_configs', function (Blueprint $table) {
            $table->dropColumn('api_token');
        });
    }
};
