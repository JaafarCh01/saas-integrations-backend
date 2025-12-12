<?php

namespace App\Jobs;

use App\Models\InstagramConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessInstagramWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    protected array $payload;

    /**
     * Create a new job instance.
     */
    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Extract data from Unipile payload format
        $accountId = $this->payload['account_id'] ?? null;
        $chatId = $this->payload['chat_id'] ?? null;
        $messageText = $this->payload['message'] ?? null;

        // Sender info is nested in 'sender' object
        $sender = $this->payload['sender'] ?? [];
        $senderName = $sender['attendee_name'] ?? 'Customer';

        if (!$accountId) {
            Log::warning('ProcessInstagramWebhook: Missing account_id', $this->payload);
            return;
        }

        // Find the user by account ID
        $config = InstagramConfig::findByUnipileAccountId($accountId);

        if (!$config) {
            Log::warning('ProcessInstagramWebhook: No config found for account', [
                'account_id' => $accountId,
            ]);
            return;
        }

        // Check if AI is active
        if (!$config->shouldRespond()) {
            Log::debug('ProcessInstagramWebhook: AI not active for store', [
                'store_name' => $config->store_name,
            ]);
            return;
        }

        // Build payload for n8n
        $n8nPayload = [
            'user_id' => $config->id,
            'store_name' => $config->store_name,
            'unipile_account_id' => $accountId,
            'unipile_chat_id' => $chatId,
            'message_text' => $messageText,
            'system_prompt' => $config->ai_system_prompt ?? 'You are a helpful sales assistant. Be friendly and concise.',
            'sender_name' => $senderName,
        ];

        // Forward to n8n webhook
        $n8nUrl = config('services.n8n_instagram.webhook_url');

        if (!$n8nUrl) {
            Log::error('ProcessInstagramWebhook: N8N_INSTAGRAM_WEBHOOK_URL not configured');
            return;
        }

        try {
            $response = Http::timeout(30)->post($n8nUrl, $n8nPayload);

            if ($response->successful()) {
                Log::info('ProcessInstagramWebhook: Successfully forwarded to n8n', [
                    'store_name' => $config->store_name,
                    'chat_id' => $chatId,
                ]);
            } else {
                Log::error('ProcessInstagramWebhook: n8n request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('ProcessInstagramWebhook: Exception forwarding to n8n', [
                'error' => $e->getMessage(),
            ]);
            throw $e; // Re-throw to trigger retry
        }
    }
}
