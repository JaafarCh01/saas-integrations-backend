<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramLog extends Model
{
    protected $fillable = [
        'store_name',
        'unipile_account_id',
        'chat_id',
        'sender_name',
        'sender_username',
        'user_message',
        'ai_response',
        'status',
    ];

    /**
     * Get stats for a store.
     */
    public static function getStatsForStore(string $storeName): array
    {
        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();

        return [
            'messages_today' => self::where('store_name', $storeName)
                ->where('created_at', '>=', $today)
                ->count(),
            'messages_this_week' => self::where('store_name', $storeName)
                ->where('created_at', '>=', $thisWeek)
                ->count(),
            'total_messages' => self::where('store_name', $storeName)->count(),
            'conversations' => self::where('store_name', $storeName)
                ->distinct('chat_id')
                ->count('chat_id'),
        ];
    }

    /**
     * Get recent conversations for a store.
     */
    public static function getRecentConversations(string $storeName, int $limit = 10): array
    {
        return self::where('store_name', $storeName)
            ->select('chat_id', 'sender_name', 'sender_username', 'user_message', 'ai_response', 'created_at')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get conversation summaries (grouped by chat_id) for dashboard.
     */
    public static function getConversationSummaries(string $storeName): array
    {
        return self::where('store_name', $storeName)
            ->selectRaw('
                chat_id,
                MAX(sender_name) as sender_name,
                MAX(sender_username) as sender_username,
                COUNT(*) as message_count,
                MAX(created_at) as last_activity
            ')
            ->groupBy('chat_id')
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($conv) use ($storeName) {
                // Get last message for this conversation
                $lastMessage = self::where('store_name', $storeName)
                    ->where('chat_id', $conv->chat_id)
                    ->orderByDesc('created_at')
                    ->first();

                return [
                    'conversation_id' => $conv->chat_id,
                    'sender_name' => $conv->sender_name,
                    'sender_username' => $conv->sender_username,
                    'last_message' => $lastMessage?->user_message ?? $lastMessage?->ai_response ?? '',
                    'message_count' => $conv->message_count,
                    'last_activity' => $conv->last_activity,
                ];
            })
            ->toArray();
    }

    /**
     * Get full conversation history by chat_id.
     * Queries agent_logs which has both user_message and ai_response.
     */
    public static function getConversationHistory(string $chatId): array
    {
        // Query agent_logs which stores both user messages and AI responses
        $messages = \App\Models\AgentLog::where('conversation_id', $chatId)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return [];
        }

        // Get sender info from instagram_logs
        $instagramLog = self::where('chat_id', $chatId)->first();

        return [
            'conversation_id' => $chatId,
            'store_name' => $messages->first()->store_name,
            'sender_name' => $instagramLog?->sender_name,
            'sender_username' => $instagramLog?->sender_username,
            'messages' => $messages->map(fn($m) => [
                'id' => $m->id,
                'user_message' => $m->user_message,
                'ai_response' => $m->ai_response,
                'status' => $m->status ?? 'success',
                'created_at' => $m->created_at->toISOString(),
            ])->toArray(),
            'total_messages' => $messages->count(),
        ];
    }
}

