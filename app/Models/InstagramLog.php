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
}
