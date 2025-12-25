<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadConfig extends Model
{
    protected $fillable = [
        'store_name',
        'hashtags',
        'platforms',
        'is_active',
        'ai_system_prompt',
        'last_scraped_at',
    ];

    protected $casts = [
        'hashtags' => 'array',
        'platforms' => 'array',
        'is_active' => 'boolean',
        'last_scraped_at' => 'datetime',
    ];

    /**
     * Find config by store name
     */
    public static function findByStoreName(string $storeName): ?self
    {
        return self::where('store_name', $storeName)->first();
    }

    /**
     * Get all active configs for n8n to process
     */
    public static function getActiveAgents(): array
    {
        return self::where('is_active', true)
            ->whereNotNull('hashtags')
            ->get()
            ->map(fn($config) => [
                'store_name' => $config->store_name,
                'hashtags' => $config->hashtags ?? [],
                'platforms' => $config->platforms ?? ['tiktok'],
                'ai_system_prompt' => $config->ai_system_prompt,
            ])
            ->toArray();
    }

    /**
     * Mark as scraped
     */
    public function markScraped(): void
    {
        $this->update(['last_scraped_at' => now()]);
    }
}
