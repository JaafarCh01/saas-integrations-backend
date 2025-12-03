<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\AgentLog;
use App\Models\WhatsAppStoreConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class WhatsAppAgentController extends Controller
{
    /**
     * POST /api/v1/agent/log
     * Called by n8n to log each conversation turn
     */
    public function log(Request $request)
    {
        try {
            $validated = $request->validate([
                'conversation_id' => 'required|string',
                'user_message' => 'nullable|string',
                'ai_response' => 'nullable|string',
                'cost_tokens' => 'required|integer|min:0',
            ]);

            // Extract store_name and phone from conversation_id
            $storeName = AgentLog::extractStoreName($validated['conversation_id']);
            $phone = AgentLog::extractPhone($validated['conversation_id']);

            if (!$storeName || !$phone) {
                return response()->json([
                    'error' => 'Invalid conversation_id format. Expected: {store_name}_{phone}'
                ], 400);
            }

            // Calculate cost
            $cost = AgentLog::calculateCost($validated['cost_tokens']);

            // Create log entry
            $log = AgentLog::create([
                'store_name' => $storeName,
                'conversation_id' => $validated['conversation_id'],
                'customer_phone' => $phone,
                'user_message' => $validated['user_message'],
                'ai_response' => $validated['ai_response'],
                'tokens_used' => $validated['cost_tokens'],
                'cost_estimate_usd' => $cost,
                'status' => 'success',
            ]);

            Log::info('WhatsApp agent log created', [
                'log_id' => $log->id,
                'conversation_id' => $validated['conversation_id'],
                'tokens' => $validated['cost_tokens'],
                'cost' => $cost,
            ]);

            return response()->json([
                'success' => true,
                'log_id' => $log->id,
                'cost_usd' => $cost,
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp agent log error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/v1/agent/context
     * Called by n8n to get store context and conversation history
     */
    public function context(Request $request)
    {
        try {
            $validated = $request->validate([
                'store_name' => 'required|string',
                'phone' => 'required|string',
                'message' => 'required|string',
            ]);

            $storeName = $validated['store_name'];
            $conversationId = AgentLog::generateConversationId($storeName, $validated['phone']);

            // Get store config to fetch from Devaito API
            $storeConfig = WhatsAppStoreConfig::findByStoreName($storeName);

            // Fetch store info and products from Devaito API
            $storeInfo = null;
            $products = [];

            if ($storeConfig) {
                $storeInfo = $this->fetchStoreInfo($storeConfig);
                $products = $this->fetchProducts($storeConfig);
            }

            // Fetch last 5 messages for conversation history
            $history = AgentLog::forConversation($conversationId)
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get()
                ->reverse()
                ->values();

            // Format history for Gemini API
            $historyFormatted = [];
            foreach ($history as $log) {
                if ($log->user_message) {
                    $historyFormatted[] = [
                        'role' => 'user',
                        'parts' => [['text' => $log->user_message]]
                    ];
                }
                if ($log->ai_response) {
                    $historyFormatted[] = [
                        'role' => 'model',
                        'parts' => [['text' => $log->ai_response]]
                    ];
                }
            }

            // Add current message to history
            $historyFormatted[] = [
                'role' => 'user',
                'parts' => [['text' => $validated['message']]]
            ];

            // Build system prompt with real store context
            $systemPrompt = $this->buildSystemPrompt($storeName, $storeInfo, $products);

            return response()->json([
                'conversation_id' => $conversationId,
                'store_name' => $storeName,
                'phone' => $validated['phone'],
                'history_formatted' => json_encode($historyFormatted),
                'system_prompt' => json_encode($systemPrompt),
                'message_count' => count($history),
                'products_count' => count($products),
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp agent context error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/agent/stats/{storeName}
     * Dashboard analytics endpoint
     */
    public function stats(string $storeName)
    {
        try {
            // Total messages count
            $totalMessages = AgentLog::forStore($storeName)->count();

            // Total spend
            $totalSpend = AgentLog::forStore($storeName)->sum('cost_estimate_usd');

            // Activity chart (last 7 days)
            $activityChart = AgentLog::forStore($storeName)
                ->lastDays(7)
                ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
                ->groupBy('date')
                ->orderBy('date', 'asc')
                ->get()
                ->map(fn($row) => [
                    'date' => $row->date,
                    'count' => (int) $row->count,
                ]);

            // Fill in missing dates with zero counts
            $activityChart = $this->fillMissingDates($activityChart, 7);

            // Recent conversations summary
            $conversations = AgentLog::forStore($storeName)
                ->select(
                    'conversation_id',
                    'customer_phone',
                    DB::raw('MAX(created_at) as last_activity'),
                    DB::raw('COUNT(*) as message_count')
                )
                ->groupBy('conversation_id', 'customer_phone')
                ->orderBy('last_activity', 'desc')
                ->limit(20)
                ->get()
                ->map(function ($conv) {
                    // Get last message for this conversation
                    $lastLog = AgentLog::forConversation($conv->conversation_id)
                        ->orderBy('created_at', 'desc')
                        ->first();

                    return [
                        'conversation_id' => $conv->conversation_id,
                        'customer_phone' => $conv->customer_phone,
                        'last_message' => $lastLog?->ai_response ?? $lastLog?->user_message ?? '',
                        'message_count' => (int) $conv->message_count,
                        'last_activity' => $conv->last_activity,
                    ];
                });

            return response()->json([
                'store_name' => $storeName,
                'total_messages' => $totalMessages,
                'total_spend' => round((float) $totalSpend, 5),
                'activity_chart' => $activityChart,
                'conversations' => $conversations,
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp agent stats error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/v1/agent/conversation/{conversation_id}
     * Get full conversation history
     */
    public function conversation(string $conversationId)
    {
        try {
            $messages = AgentLog::forConversation($conversationId)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($log) => [
                    'id' => $log->id,
                    'user_message' => $log->user_message,
                    'ai_response' => $log->ai_response,
                    'tokens_used' => $log->tokens_used,
                    'cost_usd' => (float) $log->cost_estimate_usd,
                    'status' => $log->status,
                    'created_at' => $log->created_at->toISOString(),
                ]);

            $storeName = AgentLog::extractStoreName($conversationId);
            $phone = AgentLog::extractPhone($conversationId);

            return response()->json([
                'conversation_id' => $conversationId,
                'store_name' => $storeName,
                'customer_phone' => $phone,
                'messages' => $messages,
                'total_messages' => $messages->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp agent conversation error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * POST /api/webhooks/whatsapp
     * Twilio webhook handler for incoming WhatsApp messages
     */
    public function twilioWebhook(Request $request)
    {
        try {
            Log::info('Twilio webhook received', $request->all());

            // Extract Twilio webhook data
            $from = $request->input('From'); // Format: whatsapp:+1234567890
            $to = $request->input('To'); // The Twilio number that received the message
            $body = $request->input('Body');

            if (!$from || !$body) {
                return response('', 200); // Twilio expects 200 even on errors
            }

            // Normalize phone numbers
            $customerPhone = str_replace('whatsapp:', '', $from);
            $twilioNumber = str_replace('whatsapp:', '', $to);

            // Look up store by Twilio number
            $storeConfig = WhatsAppStoreConfig::findByTwilioNumber($twilioNumber);

            if (!$storeConfig) {
                Log::warning('No store config found for Twilio number', ['to' => $twilioNumber]);
                return response('', 200);
            }

            // Forward to n8n webhook
            $n8nWebhookUrl = config('services.n8n.whatsapp_webhook_url');

            if ($n8nWebhookUrl) {
                Http::timeout(30)->post($n8nWebhookUrl, [
                    'store_name' => $storeConfig->store_name,
                    'phone' => $customerPhone,
                    'message' => $body,
                ]);

                Log::info('Forwarded to n8n', [
                    'store_name' => $storeConfig->store_name,
                    'phone' => $customerPhone,
                ]);
            } else {
                Log::warning('N8N_WHATSAPP_WEBHOOK_URL not configured');
            }

            // Twilio expects empty 200 response
            return response('', 200);
        } catch (\Exception $e) {
            Log::error('Twilio webhook error: ' . $e->getMessage());
            return response('', 200); // Still return 200 to Twilio
        }
    }

    /**
     * POST /api/v1/agent/test
     * Manual test endpoint for triggering the agent
     */
    public function test(Request $request)
    {
        try {
            $validated = $request->validate([
                'store_name' => 'required|string',
                'phone' => 'required|string',
                'message' => 'required|string',
            ]);

            // Forward to n8n webhook
            $n8nWebhookUrl = config('services.n8n.whatsapp_webhook_url');

            if (!$n8nWebhookUrl) {
                return response()->json([
                    'error' => 'N8N_WHATSAPP_WEBHOOK_URL not configured'
                ], 500);
            }

            $response = Http::timeout(60)->post($n8nWebhookUrl, [
                'store_name' => $validated['store_name'],
                'phone' => $validated['phone'],
                'message' => $validated['message'],
            ]);

            Log::info('Test message sent to n8n', [
                'store_name' => $validated['store_name'],
                'phone' => $validated['phone'],
                'status' => $response->status(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Test message sent to n8n',
                'n8n_status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::error('Test endpoint error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fetch store info from Devaito API
     */
    private function fetchStoreInfo(WhatsAppStoreConfig $config): ?array
    {
        $cacheKey = "store_info_{$config->store_name}";

        return Cache::remember($cacheKey, 300, function () use ($config) {
            try {
                $response = Http::withToken($config->api_token)
                    ->timeout(10)
                    ->get("{$config->getApiBaseUrl()}/user");

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['data'] ?? null;
                }
            } catch (\Exception $e) {
                Log::error("Failed to fetch store info for {$config->store_name}: " . $e->getMessage());
            }

            return null;
        });
    }

    /**
     * Fetch products from Devaito API
     */
    private function fetchProducts(WhatsAppStoreConfig $config, int $limit = 50): array
    {
        $cacheKey = "store_products_{$config->store_name}";

        return Cache::remember($cacheKey, 300, function () use ($config, $limit) {
            try {
                $response = Http::withToken($config->api_token)
                    ->timeout(15)
                    ->get("{$config->getApiBaseUrl()}/fetch-all-products", [
                        'limit' => $limit,
                        'page' => 1,
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['products'] ?? [];
                }
            } catch (\Exception $e) {
                Log::error("Failed to fetch products for {$config->store_name}: " . $e->getMessage());
            }

            return [];
        });
    }

    /**
     * Build system prompt with real store context
     */
    private function buildSystemPrompt(string $storeName, ?array $storeInfo, array $products): string
    {
        $ownerName = $storeInfo['name'] ?? 'the store owner';
        $storeUrl = "https://{$storeName}.devaito.com";

        // Build product catalog section
        $productCatalog = "";
        if (!empty($products)) {
            $productCatalog = "\n\nAvailable Products:\n";
            foreach (array_slice($products, 0, 20) as $product) {
                $price = $product['price'] ?? 0;
                $devise = $product['devise'] ?? 'MAD';
                $name = $product['name'] ?? 'Unknown';
                $url = $product['url'] ?? '';
                
                $productCatalog .= "- {$name}: {$price} {$devise}";
                if ($url) {
                    $productCatalog .= " - {$url}";
                }
                $productCatalog .= "\n";
            }
        }

        return "You are a friendly and helpful WhatsApp sales assistant for {$storeName}. " .
            "The store is owned by {$ownerName}. Store website: {$storeUrl}\n\n" .
            "Your role is to:\n" .
            "1. Help customers find products they're looking for\n" .
            "2. Answer questions about product availability, prices, and details\n" .
            "3. Provide product links when relevant\n" .
            "4. Be friendly, concise, and professional\n" .
            "5. Keep responses under 300 characters when possible for WhatsApp readability\n" .
            "6. If you don't know something, say so honestly and offer to help find the answer\n" .
            "7. Always be courteous and thank customers for their interest\n" .
            $productCatalog;
    }

    /**
     * Fill missing dates in activity chart with zero counts
     */
    private function fillMissingDates($data, int $days): array
    {
        $result = [];
        $dataByDate = $data->keyBy('date');

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $result[] = [
                'date' => $date,
                'count' => $dataByDate->has($date) ? $dataByDate[$date]['count'] : 0,
            ];
        }

        return $result;
    }
}
