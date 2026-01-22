<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $fillable = [
        'store_name',
        'name',
        'product_name',
        'product_url',
        'product_image',
        'mode',
        'config_type',
        'status',
        'is_active',
        'platforms',
        'platform_sub_options',
        'hashtags',
        'targeting',
        'prospect_count',
        'search_rate',
        'last_run',
        'last_error',
    ];

    protected $casts = [
        'platforms' => 'array',
        'platform_sub_options' => 'array',
        'hashtags' => 'array',
        'targeting' => 'array',
        'is_active' => 'boolean',
        'last_run' => 'datetime',
    ];

    /**
     * Get leads found by this agent
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Scope: Filter by store
     */
    public function scopeByStore($query, string $storeName)
    {
        return $query->where('store_name', $storeName);
    }

    /**
     * Scope: Get active agents
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Mark agent as running
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => 'running',
            'last_error' => null,
        ]);
    }

    /**
     * Mark agent as completed
     */
    public function markCompleted(int $prospectsFound = 0): void
    {
        $this->update([
            'status' => 'completed',
            'last_run' => now(),
            'prospect_count' => $this->prospect_count + $prospectsFound,
        ]);
    }

    /**
     * Mark agent as error
     */
    public function markError(string $error): void
    {
        $this->update([
            'status' => 'error',
            'last_error' => $error,
        ]);
    }

    /**
     * Toggle active status
     */
    public function toggleActive(): void
    {
        $this->update(['is_active' => !$this->is_active]);
    }

    /**
     * Build payload for n8n workflow
     */
    public function toN8nPayload(): array
    {
        return [
            'agent_id' => $this->id,
            'store_name' => $this->store_name,
            'product_name' => $this->product_name,
            'product_url' => $this->product_url,
            'mode' => $this->mode,
            'platforms' => $this->platforms ?? [],
            'platform_sub_options' => $this->platform_sub_options ?? [],
            'hashtags' => $this->hashtags ?? [],
            'targeting' => $this->targeting ?? [
                'minFollowers' => 500,
                'maxFollowers' => 100000,
                'excludeVerified' => true,
            ],
        ];
    }

    /**
     * Format for frontend API response
     */
    public function toFrontendFormat(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'productName' => $this->product_name,
            'productImage' => $this->product_image,
            'mode' => $this->mode,
            'configType' => $this->config_type,
            'status' => $this->status,
            'isActive' => $this->is_active,
            'prospectCount' => $this->prospect_count,
            'lastRun' => $this->last_run?->toISOString(),
            'searchRate' => $this->search_rate,
            'platforms' => $this->platforms ?? [],
            'platformSubOptions' => $this->platform_sub_options ?? [],
            'hashtags' => $this->hashtags ?? [],
            'targeting' => $this->targeting ?? [],
        ];
    }
}
