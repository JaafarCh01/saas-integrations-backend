<?php

namespace App\Http\Controllers;

use App\Models\InstagramConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class InstagramController extends Controller
{
    /**
     * Generate Unipile hosted auth URL for connecting Instagram.
     * 
     * GET /api/instagram/connect
     */
    public function connect(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');

        try {
            // Pre-create or get the config record for this store
            // This ensures we have a record to link the account_id to when OAuth completes
            $config = InstagramConfig::firstOrCreate(
                ['store_name' => $storeName],
                ['is_active' => false] // Will be set to true when connection completes
            );

            // Generate expiration time (1 hour from now) in strict UTC format with milliseconds
            // Unipile requires: YYYY-MM-DDTHH:MM:SS.sssZ
            $expiresOn = Carbon::now()->addHour()->utc()->format('Y-m-d\TH:i:s.v\Z');

            // Call Unipile API to create hosted auth link
            $response = Http::withHeaders([
                'X-API-KEY' => config('services.unipile.api_key'),
                'Content-Type' => 'application/json',
            ])->post(config('services.unipile.api_url') . '/api/v1/hosted/accounts/link', [
                        'type' => 'create',
                        'providers' => ['INSTAGRAM'],
                        'api_url' => config('services.unipile.api_url'),
                        'expiresOn' => $expiresOn,
                        'notify_url' => config('app.url') . '/api/webhooks/unipile',
                        'name' => $storeName, // Pass store name to identify user in webhook
                    ]);

            if (!$response->successful()) {
                Log::error('Unipile connect failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return response()->json([
                    'error' => 'Failed to generate connection URL',
                    'details' => $response->json(),
                ], 500);
            }

            $data = $response->json();

            return response()->json([
                'url' => $data['url'] ?? null,
                'expires_at' => $expiresOn,
            ]);
        } catch (\Exception $e) {
            Log::error('Instagram connect error', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to initiate Instagram connection',
            ], 500);
        }
    }

    /**
     * Get current Instagram connection status.
     * 
     * GET /api/instagram/status
     */
    public function status(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $config = InstagramConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'connected' => false,
                'provisioned' => false,
            ]);
        }

        return response()->json([
            'connected' => !empty($config->unipile_account_id),
            'provisioned' => $config->is_active,
            'instagram_username' => $config->instagram_username,
            'ai_active' => $config->ai_active,
            'ai_system_prompt' => $config->ai_system_prompt,
        ]);
    }

    /**
     * Get stats for Instagram agent.
     * 
     * GET /api/instagram/stats
     */
    public function stats(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');

        // Import the model
        $stats = \App\Models\InstagramLog::getStatsForStore($storeName);
        $recentMessages = \App\Models\InstagramLog::getRecentConversations($storeName, 20);

        return response()->json([
            'success' => true,
            'stats' => $stats,
            'recent_messages' => $recentMessages,
        ]);
    }

    /**
     * Update Instagram AI configuration.
     * 
     * PUT /api/instagram/config
     */
    public function updateConfig(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
            'ai_active' => 'sometimes|boolean',
            'ai_system_prompt' => 'sometimes|nullable|string',
        ]);

        $storeName = $request->input('store_name');
        $config = InstagramConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'error' => 'Instagram not connected for this store',
            ], 404);
        }

        // Update only provided fields
        if ($request->has('ai_active')) {
            $config->ai_active = $request->input('ai_active');
        }
        if ($request->has('ai_system_prompt')) {
            $config->ai_system_prompt = $request->input('ai_system_prompt');
        }

        $config->save();

        return response()->json([
            'success' => true,
            'ai_active' => $config->ai_active,
            'ai_system_prompt' => $config->ai_system_prompt,
        ]);
    }

    /**
     * Disconnect Instagram account.
     * 
     * DELETE /api/instagram/disconnect
     */
    public function disconnect(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $config = InstagramConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'error' => 'Instagram not connected for this store',
            ], 404);
        }

        // Optionally call Unipile to revoke the account
        if ($config->unipile_account_id) {
            try {
                Http::withHeaders([
                    'X-API-KEY' => config('services.unipile.api_key'),
                ])->delete(config('services.unipile.api_url') . '/api/v1/accounts/' . $config->unipile_account_id);
            } catch (\Exception $e) {
                Log::warning('Failed to revoke Unipile account', [
                    'account_id' => $config->unipile_account_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Clear the connection data
        $config->unipile_account_id = null;
        $config->instagram_username = null;
        $config->ai_active = false;
        $config->is_active = false;
        $config->save();

        return response()->json([
            'success' => true,
            'message' => 'Instagram disconnected successfully',
        ]);
    }
}
