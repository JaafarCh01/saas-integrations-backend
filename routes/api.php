<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoGenerationController;
use App\Http\Controllers\VideoWebhookController;
use App\Http\Controllers\WhatsAppAgentController;
use App\Http\Controllers\WhatsAppProvisioningController;
use App\Http\Controllers\InstagramController;
use App\Http\Controllers\UnipileWebhookController;

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
        Route::put('/config', [InstagramController::class, 'updateConfig']);
        Route::delete('/disconnect', [InstagramController::class, 'disconnect']);
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
