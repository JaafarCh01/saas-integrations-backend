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
        Schema::create('email_configs', function (Blueprint $table) {
            $table->id();
            $table->string('store_name')->unique();

            // Connection settings
            $table->string('email_address');
            $table->string('provider');           // gmail, yahoo, outlook, custom
            $table->text('app_password');         // Encrypted

            // IMAP settings
            $table->string('imap_host');
            $table->integer('imap_port')->default(993);
            $table->string('imap_encryption')->default('ssl');

            // SMTP settings
            $table->string('smtp_host');
            $table->integer('smtp_port')->default(587);
            $table->string('smtp_encryption')->default('tls');

            // AI settings
            $table->boolean('ai_active')->default(false);
            $table->text('ai_system_prompt')->nullable();

            // API token for Devaito
            $table->text('api_token')->nullable();

            // Status
            $table->boolean('is_active')->default(false);
            $table->timestamp('last_polled_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_configs');
    }
};
