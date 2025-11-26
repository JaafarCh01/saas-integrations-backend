<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\VideoGenerationController;
use App\Http\Controllers\VideoWebhookController;

Route::middleware('api')->group(function () {
    // 1. Product Mock (Simulating Client SaaS)
    Route::get('/products', [VideoGenerationController::class, 'listProducts']);

    // 2. Video Generation Flow
    Route::post('/ugc/generate', [VideoGenerationController::class, 'generate']);
    Route::get('/ugc/status/{jobId}', [VideoGenerationController::class, 'status']);
    Route::get('/ugc/video/{jobId}', [VideoGenerationController::class, 'proxyVideo'])->name('video.proxy');
    Route::get('/ugc/history', [VideoGenerationController::class, 'history']);

    // 3. Webhook (n8n callback)
    Route::post('/webhooks/video-completed', [VideoWebhookController::class, 'handle']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
