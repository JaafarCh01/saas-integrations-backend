<?php

namespace App\Http\Controllers;

use App\Models\EmailConfig;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
class EmailAgentController extends Controller
{
    /**
     * Connect email account with App Password
     * 
     * POST /api/email/connect
     */
    public function connect(Request $request)
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
            'email_address' => 'required|email',
            'app_password' => 'required|string',
            'provider' => 'required|in:gmail,yahoo,outlook,custom',
            // Custom provider settings (optional)
            'imap_host' => 'required_if:provider,custom|string|nullable',
            'imap_port' => 'nullable|integer',
            'smtp_host' => 'required_if:provider,custom|string|nullable',
            'smtp_port' => 'nullable|integer',
        ]);

        try {
            // Get provider preset or use custom settings
            $preset = EmailConfig::getProviderPreset($validated['provider']);

            $configData = [
                'store_name' => $validated['store_name'],
                'email_address' => $validated['email_address'],
                'app_password' => $validated['app_password'],
                'provider' => $validated['provider'],
                'imap_host' => $validated['imap_host'] ?? $preset['imap_host'] ?? '',
                'imap_port' => $validated['imap_port'] ?? $preset['imap_port'] ?? 993,
                'imap_encryption' => $preset['imap_encryption'] ?? 'ssl',
                'smtp_host' => $validated['smtp_host'] ?? $preset['smtp_host'] ?? '',
                'smtp_port' => $validated['smtp_port'] ?? $preset['smtp_port'] ?? 587,
                'smtp_encryption' => $preset['smtp_encryption'] ?? 'tls',
                'is_active' => true,
            ];

            // Capture Devaito API token if present
            $apiToken = $request->bearerToken();
            if ($apiToken) {
                $configData['api_token'] = $apiToken;
            }

            // Create or update config
            $config = EmailConfig::updateOrCreate(
                ['store_name' => $validated['store_name']],
                $configData
            );

            Log::info('Email account connected', [
                'store_name' => $validated['store_name'],
                'email' => $validated['email_address'],
                'provider' => $validated['provider'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email account connected successfully',
                'email_address' => $config->email_address,
                'provider' => $config->provider,
            ]);
        } catch (\Exception $e) {
            Log::error('Email connect error', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to connect email account',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get email connection status
     * 
     * GET /api/email/status
     */
    public function status(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $config = EmailConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'connected' => false,
                'provisioned' => false,
            ]);
        }

        return response()->json([
            'connected' => true,
            'provisioned' => $config->is_active,
            'email_address' => $config->email_address,
            'provider' => $config->provider,
            'ai_active' => $config->ai_active,
            'ai_system_prompt' => $config->ai_system_prompt,
            'last_polled_at' => $config->last_polled_at?->toISOString(),
            'last_error' => $config->last_error,
        ]);
    }

    /**
     * Update email AI configuration
     * 
     * PUT /api/email/config
     */
    public function updateConfig(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
            'ai_active' => 'sometimes|boolean',
            'ai_system_prompt' => 'sometimes|nullable|string',
        ]);

        $storeName = $request->input('store_name');
        $config = EmailConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'error' => 'Email not connected for this store',
            ], 404);
        }

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
     * Disconnect email account
     * 
     * DELETE /api/email/disconnect
     */
    public function disconnect(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $config = EmailConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'error' => 'Email not connected for this store',
            ], 404);
        }

        $config->delete();

        Log::info('Email account disconnected', ['store_name' => $storeName]);

        return response()->json([
            'success' => true,
            'message' => 'Email account disconnected successfully',
        ]);
    }

    /**
     * Get email stats for dashboard
     * 
     * GET /api/email/stats
     */
    public function stats(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $stats = EmailLog::getStatsForStore($storeName);

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Get list of conversations for a store
     * 
     * GET /api/email/conversations/{storeName}
     */
    public function conversations(string $storeName)
    {
        $conversations = EmailLog::getConversationSummaries($storeName);

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
        ]);
    }

    /**
     * Get full conversation history
     * 
     * GET /api/email/conversation/{conversationId}
     */
    public function conversationHistory(string $conversationId)
    {
        $history = EmailLog::getConversationHistory($conversationId);

        if (empty($history)) {
            return response()->json([
                'error' => 'Conversation not found',
            ], 404);
        }

        return response()->json($history);
    }

    /**
     * Test email connection (IMAP)
     * 
     * POST /api/email/test
     */
    public function testConnection(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->input('store_name');
        $config = EmailConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'error' => 'Email not connected for this store',
            ], 404);
        }

        try {
            // Try to connect via IMAP
            $cm = new ClientManager();
            $client = $cm->make($config->getImapConfig());
            $client->connect();
            $client->disconnect();

            return response()->json([
                'success' => true,
                'message' => 'Connection successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Connection failed: ' . $e->getMessage(),
            ], 400);
        }
    }
}
