<?php

namespace App\Http\Controllers;

use App\Models\InstagramConfig;
use App\Jobs\ProcessInstagramWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UnipileWebhookController extends Controller
{
    /**
     * Handle Unipile webhooks (account connection & messages).
     * 
     * POST /api/webhooks/unipile
     */
    public function handle(Request $request)
    {
        $payload = $request->all();

        // Unipile uses 'event' field, not 'type'
        $eventType = $payload['event'] ?? $payload['type'] ?? null;
        $status = $payload['status'] ?? $payload['data']['status'] ?? null;
        $accountId = $payload['account_id'] ?? null;

        Log::info('Unipile webhook received', [
            'event' => $eventType,
            'status' => $status,
            'account_id' => $accountId,
        ]);

        // Handle message received event - this is the main event type from Unipile
        if ($eventType === 'message_received') {
            // Auto-register the account if we haven't seen it before
            $this->ensureAccountRegistered($payload);
            return $this->handleNewMessage($payload);
        }

        // Handle account connection webhook (multiple possible formats)
        if (
            $eventType === 'account_connected' ||
            $eventType === 'account.connected' ||
            $eventType === 'account' ||
            $status === 'CREATION_SUCCESS' ||
            $status === 'OK' ||
            $status === 'CONNECTED'
        ) {
            return $this->handleAccountConnected($payload);
        }

        // Handle other message events
        if ($eventType === 'new_message' || $eventType === 'message.new' || $eventType === 'message') {
            $this->ensureAccountRegistered($payload);
            return $this->handleNewMessage($payload);
        }

        Log::info('Unipile webhook not matched', ['event' => $eventType, 'status' => $status]);
        return response()->json(['status' => 'ignored', 'event' => $eventType, 'received' => true]);
    }

    /**
     * Auto-register account from message payload if not already stored.
     */
    protected function ensureAccountRegistered(array $payload): void
    {
        $accountId = $payload['account_id'] ?? null;
        $accountInfo = $payload['account_info'] ?? [];
        $username = $accountInfo['username'] ?? null;
        $webhookName = $payload['webhook_name'] ?? null; // This is the store name we passed

        if (!$accountId) {
            return;
        }

        // Check if we already have this account
        $existing = InstagramConfig::findByUnipileAccountId($accountId);
        if ($existing) {
            return;
        }

        // Use webhook_name as store_name, or create a temporary one
        $storeName = $webhookName ?: 'store_' . substr($accountId, 0, 10);

        // Find or create config
        $config = InstagramConfig::firstOrCreate(
            ['store_name' => $storeName],
            ['is_active' => true]
        );

        $config->unipile_account_id = $accountId;
        $config->instagram_username = $username;
        $config->is_active = true;
        $config->save();

        Log::info('Instagram account auto-registered from message', [
            'store_name' => $storeName,
            'account_id' => $accountId,
            'username' => $username,
        ]);
    }

    /**
     * Handle account connected event - store the account ID.
     */
    protected function handleAccountConnected(array $payload): \Illuminate\Http\JsonResponse
    {
        $accountId = $payload['account_id'] ?? $payload['data']['account_id'] ?? null;
        $storeName = $payload['name'] ?? $payload['webhook_name'] ?? $payload['data']['name'] ?? null;
        $accountInfo = $payload['account_info'] ?? [];
        $username = $accountInfo['username'] ?? $payload['username'] ?? $payload['data']['username'] ?? null;

        if (!$accountId) {
            Log::warning('Unipile account connected webhook missing account_id', $payload);
            return response()->json(['error' => 'Missing account_id'], 400);
        }

        // Use a default store name if not provided
        if (!$storeName) {
            $storeName = 'store_' . substr($accountId, 0, 10);
        }

        // Find or create config for this store
        $config = InstagramConfig::firstOrCreate(
            ['store_name' => $storeName],
            ['is_active' => true]
        );

        $config->unipile_account_id = $accountId;
        $config->instagram_username = $username;
        $config->is_active = true;
        $config->save();

        Log::info('Instagram account connected', [
            'store_name' => $storeName,
            'account_id' => $accountId,
            'username' => $username,
        ]);

        return response()->json(['status' => 'connected', 'store_name' => $storeName]);
    }

    /**
     * Handle new message event - dispatch to job for processing.
     */
    protected function handleNewMessage(array $payload): \Illuminate\Http\JsonResponse
    {
        $messageId = $payload['message_id'] ?? $payload['data']['id'] ?? null;
        $isSender = $payload['is_sender'] ?? $payload['data']['is_sender'] ?? false;

        // 1. Duplicate check - cache message ID for 1 minute
        if ($messageId) {
            $cacheKey = "unipile_msg:{$messageId}";
            if (Cache::has($cacheKey)) {
                Log::debug('Duplicate message ignored', ['message_id' => $messageId]);
                return response()->json(['status' => 'duplicate']);
            }
            Cache::put($cacheKey, true, 60);
        }

        // 2. Self-message check - don't reply to our own messages
        if ($isSender === true) {
            Log::debug('Self-message ignored', ['message_id' => $messageId]);
            return response()->json(['status' => 'self_message']);
        }

        // 3. Dispatch job for async processing
        ProcessInstagramWebhook::dispatch($payload);

        Log::info('Message dispatched for processing', ['message_id' => $messageId]);
        return response()->json(['status' => 'processing']);
    }
}

