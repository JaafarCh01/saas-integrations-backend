<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLog extends Model
{
    protected $fillable = [
        'store_name',
        'conversation_id',
        'from_email',
        'from_name',
        'subject',
        'user_message',
        'ai_response',
        'message_id',
        'status',
    ];

    /**
     * Get stats for a store
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
                ->distinct('conversation_id')
                ->count('conversation_id'),
        ];
    }

    /**
     * Get conversation summaries for dashboard
     */
    public static function getConversationSummaries(string $storeName): array
    {
        return self::where('store_name', $storeName)
            ->selectRaw('
                conversation_id,
                MAX(from_name) as from_name,
                MAX(from_email) as from_email,
                MAX(subject) as subject,
                COUNT(*) as message_count,
                MAX(created_at) as last_activity
            ')
            ->groupBy('conversation_id')
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($conv) use ($storeName) {
                // Get last message for this conversation
                $lastMessage = self::where('store_name', $storeName)
                    ->where('conversation_id', $conv->conversation_id)
                    ->orderByDesc('created_at')
                    ->first();

                return [
                    'conversation_id' => $conv->conversation_id,
                    'from_name' => $conv->from_name,
                    'from_email' => $conv->from_email,
                    'subject' => $conv->subject,
                    'last_message' => $lastMessage?->user_message ?? $lastMessage?->ai_response ?? '',
                    'message_count' => $conv->message_count,
                    'last_activity' => $conv->last_activity,
                ];
            })
            ->toArray();
    }

    /**
     * Get full conversation history by conversation_id
     */
    public static function getConversationHistory(string $conversationId): array
    {
        $messages = self::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            return [];
        }

        $first = $messages->first();

        return [
            'conversation_id' => $conversationId,
            'store_name' => $first->store_name,
            'from_name' => $first->from_name,
            'from_email' => $first->from_email,
            'subject' => $first->subject,
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

    /**
     * Check if a message has already been processed
     */
    public static function isMessageProcessed(string $messageId): bool
    {
        return self::where('message_id', $messageId)->exists();
    }

    /**
     * Generate conversation ID from email thread
     * Uses In-Reply-To or References header, or falls back to subject hash
     */
    public static function generateConversationId(string $storeName, ?string $references, string $subject): string
    {
        if ($references) {
            // Use first reference as thread ID
            $threadId = trim(explode(' ', $references)[0], '<>');
            return md5($storeName . '_' . $threadId);
        }

        // Fallback: use subject hash (strip Re:/Fwd: prefixes)
        $cleanSubject = preg_replace('/^(Re:|Fwd:|Fw:)\s*/i', '', $subject);
        return md5($storeName . '_' . strtolower(trim($cleanSubject)));
    }
}
