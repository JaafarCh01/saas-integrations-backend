<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoGenerationController;
use App\Http\Controllers\VideoWebhookController;
use App\Http\Controllers\WhatsAppAgentController;

Route::middleware('api')->group(function () {
    // 1. Product Mock (Simulating Client SaaS)
    Route::get('/products', [VideoGenerationController::class, 'listProducts']);

    // 2. Video Generation Flow (UGC Agent)
    Route::post('/ugc/generate', [VideoGenerationController::class, 'generate']);
    Route::get('/ugc/status/{jobId}', [VideoGenerationController::class, 'status']);
    Route::get('/ugc/video/{jobId}', [VideoGenerationController::class, 'proxyVideo'])->name('video.proxy');
    Route::delete('/ugc/video/{jobId}', [VideoGenerationController::class, 'destroy']);
    Route::get('/ugc/history', [VideoGenerationController::class, 'history']);

    // 3. Webhooks (n8n callbacks)
    Route::post('/webhooks/video-completed', [VideoWebhookController::class, 'handle']);
    Route::post('/webhooks/whatsapp', [WhatsAppAgentController::class, 'twilioWebhook']);

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
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
