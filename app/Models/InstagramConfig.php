<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstagramConfig extends Model
{
    protected $fillable = [
        'store_name',
        'unipile_account_id',
        'instagram_username',
        'ai_active',
        'ai_system_prompt',
        'is_active',
    ];

    protected $casts = [
        'ai_active' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Find config by Unipile account ID.
     */
    public static function findByUnipileAccountId(string $accountId): ?self
    {
        return self::where('unipile_account_id', $accountId)->first();
    }

    /**
     * Find config by store name.
     */
    public static function findByStoreName(string $storeName): ?self
    {
        return self::where('store_name', $storeName)->first();
    }

    /**
     * Check if the AI agent should respond.
     */
    public function shouldRespond(): bool
    {
        return $this->is_active && $this->ai_active && !empty($this->unipile_account_id);
    }
}
