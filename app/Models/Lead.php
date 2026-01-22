<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $fillable = [
        'store_name',
        'agent_id',
        'platform',
        'external_id',
        'username',
        'profile_url',
        'context',
        'quality_score',
        'draft_message',
        'status',
        'action_taken_at',
    ];

    protected $casts = [
        'context' => 'array',
        'quality_score' => 'integer',
        'action_taken_at' => 'datetime',
    ];

    /**
     * Get the agent that found this lead
     */
    public function agent()
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Scope: Get pending leads
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: Filter by store
     */
    public function scopeByStore($query, string $storeName)
    {
        return $query->where('store_name', $storeName);
    }

    /**
     * Scope: Filter by platform
     */
    public function scopeByPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    /**
     * Mark lead as sent
     */
    public function markSent(): void
    {
        $this->update([
            'status' => 'sent',
            'action_taken_at' => now(),
        ]);
    }

    /**
     * Reject lead
     */
    public function reject(): void
    {
        $this->update([
            'status' => 'rejected',
            'action_taken_at' => now(),
        ]);
    }

    /**
     * Get deep link URL for this lead
     */
    public function getDeepLink(): string
    {
        if ($this->platform === 'instagram') {
            return "https://ig.me/m/{$this->username}";
        }

        if ($this->platform === 'twitter') {
            $text = urlencode($this->draft_message ?? '');
            return "https://twitter.com/intent/tweet?in_reply_to={$this->external_id}&text={$text}";
        }

        if ($this->platform === 'tiktok') {
            // TikTok doesn't have a DM deep link, link to profile
            return "https://www.tiktok.com/@{$this->username}";
        }

        return $this->profile_url;
    }

    /**
     * Get stats for a store
     */
    public static function getStatsForStore(string $storeName): array
    {
        $query = self::byStore($storeName);

        return [
            'total_pending' => (clone $query)->pending()->count(),
            'total_sent' => (clone $query)->where('status', 'sent')->count(),
            'total_rejected' => (clone $query)->where('status', 'rejected')->count(),
            'instagram_pending' => (clone $query)->pending()->byPlatform('instagram')->count(),
            'twitter_pending' => (clone $query)->pending()->byPlatform('twitter')->count(),
            'tiktok_pending' => (clone $query)->pending()->byPlatform('tiktok')->count(),
        ];
    }
}
