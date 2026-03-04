<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('instagram_configs', function (Blueprint $table) {
            $table->string('type_website')->nullable()->after('api_token');
            $table->text('store_description')->nullable()->after('type_website');
        });
    }

    public function down(): void
    {
        Schema::table('instagram_configs', function (Blueprint $table) {
            $table->dropColumn(['type_website', 'store_description']);
        });
    }
};
