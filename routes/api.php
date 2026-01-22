<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoGenerationController;
use App\Http\Controllers\VideoWebhookController;
use App\Http\Controllers\WhatsAppAgentController;
use App\Http\Controllers\WhatsAppProvisioningController;
use App\Http\Controllers\InstagramController;
use App\Http\Controllers\UnipileWebhookController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\AgentController;

Route::middleware('api')->group(function () {
    // 1. Product Mock (Simulating Client SaaS)
    Route::get('/products', [VideoGenerationController::class, 'listProducts']);

    // 2. Video Generation Flow (UGC Agent)
    Route::post('/ugc/generate', [VideoGenerationController::class, 'generate']);
    Route::post('/ugc/generate-prompt', [VideoGenerationController::class, 'generatePrompt']);
    Route::get('/ugc/status/{jobId}', [VideoGenerationController::class, 'status']);
    Route::get('/ugc/video/{jobId}', [VideoGenerationController::class, 'proxyVideo'])->name('video.proxy');
    Route::delete('/ugc/video/{jobId}', [VideoGenerationController::class, 'destroy']);
    Route::get('/ugc/history', [VideoGenerationController::class, 'history']);

    // 3. Webhooks (n8n callbacks & external services)
    Route::post('/webhooks/video-completed', [VideoWebhookController::class, 'handle']);
    Route::post('/webhooks/whatsapp', [WhatsAppAgentController::class, 'twilioWebhook']);
    Route::post('/webhooks/unipile', [UnipileWebhookController::class, 'handle']);

    // 4. WhatsApp Agent API (v1)
    Route::prefix('v1/agent')->group(function () {
        // n8n endpoints
        Route::post('/log', [WhatsAppAgentController::class, 'log']);
        Route::post('/context', [WhatsAppAgentController::class, 'context']);

        // Frontend dashboard endpoints (storeName = Devaito databaseName, e.g., "mugstroe")
        Route::get('/stats/{storeName}', [WhatsAppAgentController::class, 'stats']);
        Route::get('/conversation/{conversationId}', [WhatsAppAgentController::class, 'conversation'])
            ->where('conversationId', '.*'); // Allow slashes in conversation ID

        // Testing endpoint
        Route::post('/test', [WhatsAppAgentController::class, 'test']);
    });

    // 5. WhatsApp Number Provisioning API (v1)
    Route::prefix('v1/provisioning')->group(function () {
        Route::get('/countries', [WhatsAppProvisioningController::class, 'countries']);
        Route::get('/search', [WhatsAppProvisioningController::class, 'search']);
        Route::post('/buy', [WhatsAppProvisioningController::class, 'buy']);
        Route::get('/status', [WhatsAppProvisioningController::class, 'status']);
        Route::put('/config', [WhatsAppProvisioningController::class, 'updateConfig']);
        Route::delete('/deactivate', [WhatsAppProvisioningController::class, 'deactivate']);

        // Connect user's own Twilio account (new architecture)
        Route::post('/connect-account', [WhatsAppProvisioningController::class, 'connectAccount']);
    });

    // 6. Instagram DM Agent API
    Route::prefix('instagram')->group(function () {
        Route::get('/connect', [InstagramController::class, 'connect']);
        Route::get('/status', [InstagramController::class, 'status']);
        Route::get('/stats', [InstagramController::class, 'stats']);
        Route::put('/config', [InstagramController::class, 'updateConfig']);
        Route::delete('/disconnect', [InstagramController::class, 'disconnect']);
        Route::get('/conversations/{storeName}', [InstagramController::class, 'conversations']);
        Route::get('/conversation/{conversationId}', [InstagramController::class, 'conversationHistory']);
    });

    // 7. Email AI Agent API
    Route::prefix('email')->group(function () {
        Route::post('/connect', [\App\Http\Controllers\EmailAgentController::class, 'connect']);
        Route::get('/status', [\App\Http\Controllers\EmailAgentController::class, 'status']);
        Route::get('/stats', [\App\Http\Controllers\EmailAgentController::class, 'stats']);
        Route::put('/config', [\App\Http\Controllers\EmailAgentController::class, 'updateConfig']);
        Route::delete('/disconnect', [\App\Http\Controllers\EmailAgentController::class, 'disconnect']);
        Route::get('/conversations/{storeName}', [\App\Http\Controllers\EmailAgentController::class, 'conversations']);
        Route::get('/conversation/{conversationId}', [\App\Http\Controllers\EmailAgentController::class, 'conversationHistory']);
        Route::post('/test', [\App\Http\Controllers\EmailAgentController::class, 'testConnection']);
        // Manual Approval Mode: approve and send draft reply
        Route::post('/{id}/approve', [\App\Http\Controllers\EmailAgentController::class, 'approveDraft']);
    });

    Route::get('/debug-config', function () {
        return [
            'disk_from_config' => config('filesystems.default'),
            'bucket_from_config' => config('filesystems.disks.gcs.bucket'),
            'env_disk_value' => env('FILESYSTEM_DISK'),
        ];
    });
    // 8. Cron/Scheduler Triggers (for Cloud Run)
    // Called by Google Cloud Scheduler via HTTP
    Route::prefix('cron')->group(function () {
        Route::post('/poll-emails', function (Request $request) {
            // Verify cron secret to prevent unauthorized access
            $secret = $request->header('X-Cron-Secret');
            if ($secret !== config('services.cron_secret')) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Dispatch the job synchronously (Cloud Run will wait)
            \App\Jobs\PollEmailInboxes::dispatchSync();

            return response()->json(['success' => true, 'message' => 'Email polling completed']);
        });
    });

    // 9. Lead Generation Agent API
    Route::prefix('leads')->group(function () {
        Route::post('/ingest', [LeadController::class, 'ingest']);
        Route::get('/pending', [LeadController::class, 'pending']);
        Route::get('/stats', [LeadController::class, 'stats']);
        Route::post('/{id}/mark-sent', [LeadController::class, 'markSent']);
        Route::post('/{id}/reject', [LeadController::class, 'reject']);
    });

    // 10. Lead Config API (for settings page)
    Route::prefix('lead-config')->group(function () {
        Route::get('/status', [LeadController::class, 'configStatus']);
        Route::post('/save', [LeadController::class, 'saveConfig']);
    });

    // 11. n8n Agent Discovery (legacy, for backward compatibility)
    Route::get('/agents/active', [LeadController::class, 'activeAgents']);

    // 12. AI Prospecting Agent Management API
    Route::prefix('agents')->group(function () {
        Route::get('/', [AgentController::class, 'index']);
        Route::post('/', [AgentController::class, 'store']);
        Route::get('/{id}', [AgentController::class, 'show']);
        Route::put('/{id}', [AgentController::class, 'update']);
        Route::delete('/{id}', [AgentController::class, 'destroy']);
        Route::post('/{id}/run', [AgentController::class, 'run']);
        Route::post('/{id}/toggle', [AgentController::class, 'toggle']);
    });

    // 13. Agent Completion Webhook (called by n8n)
    Route::post('/webhooks/agent-completed', [AgentController::class, 'handleCompletion'])
        ->name('webhooks.agent-completed');
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
