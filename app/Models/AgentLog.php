<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class AgentLog extends Model
{
    /**
     * Cost per million tokens (Gemini API pricing)
     */
    const COST_PER_MILLION_TOKENS = 0.50;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'store_name',
        'conversation_id',
        'customer_phone',
        'user_message',
        'ai_response',
        'tokens_used',
        'cost_estimate_usd',
        'status',
        'action',           // 'replied', 'draft_generated'
        'draft_reply',      // AI draft text for manual approval
        'approval_status',  // 'pending_approval', 'approved', 'rejected'
        'reply_to_email',   // Customer email for SMTP reply
        'reply_subject',    // Email subject for reply
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'tokens_used' => 'integer',
        'cost_estimate_usd' => 'decimal:5',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Scope: Filter logs by store name (Devaito databaseName)
     */
    public function scopeForStore(Builder $query, string $storeName): Builder
    {
        return $query->where('store_name', $storeName);
    }

    /**
     * Scope: Filter logs by conversation
     */
    public function scopeForConversation(Builder $query, string $conversationId): Builder
    {
        return $query->where('conversation_id', $conversationId);
    }

    /**
     * Scope: Filter logs from the last N days
     */
    public function scopeLastDays(Builder $query, int $days): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope: Only successful logs
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    /**
     * Calculate cost from tokens
     */
    public static function calculateCost(int $tokens): float
    {
        return ($tokens / 1_000_000) * self::COST_PER_MILLION_TOKENS;
    }

    /**
     * Generate conversation ID from store name and phone
     */
    public static function generateConversationId(string $storeName, string $phone): string
    {
        // Normalize phone number (remove whatsapp: prefix if present)
        $normalizedPhone = str_replace('whatsapp:', '', $phone);
        return "{$storeName}_{$normalizedPhone}";
    }

    /**
     * Extract store name from conversation ID
     */
    public static function extractStoreName(string $conversationId): ?string
    {
        $parts = explode('_', $conversationId, 2);
        return $parts[0] ?? null;
    }

    /**
     * Extract phone from conversation ID
     */
    public static function extractPhone(string $conversationId): ?string
    {
        $parts = explode('_', $conversationId, 2);
        return $parts[1] ?? null;
    }
}

