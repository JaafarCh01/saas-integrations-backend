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
        // Add manual_approval to email_configs
        Schema::table('email_configs', function (Blueprint $table) {
            $table->boolean('manual_approval')->default(false)->after('ai_system_prompt');
        });

        // Add draft/approval fields to agent_logs
        Schema::table('agent_logs', function (Blueprint $table) {
            $table->string('action')->nullable()->after('status');           // 'replied', 'draft_generated'
            $table->text('draft_reply')->nullable()->after('action');        // AI draft text
            $table->string('approval_status')->nullable()->after('draft_reply');  // 'pending_approval', 'approved', 'rejected'
            $table->string('reply_to_email')->nullable()->after('approval_status');   // Customer email for SMTP
            $table->string('reply_subject')->nullable()->after('reply_to_email');     // Email subject for reply
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_configs', function (Blueprint $table) {
            $table->dropColumn('manual_approval');
        });

        Schema::table('agent_logs', function (Blueprint $table) {
            $table->dropColumn([
                'action',
                'draft_reply',
                'approval_status',
                'reply_to_email',
                'reply_subject',
            ]);
        });
    }
};
