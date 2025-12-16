<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class InstagramConfig extends Model
{
    protected $fillable = [
        'store_name',
        'unipile_account_id',
        'instagram_username',
        'ai_active',
        'ai_system_prompt',
        'api_token',
        'is_active',
    ];

    protected $casts = [
        'ai_active' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'api_token',
    ];

    /**
     * Encrypt the API token when setting
     */
    public function setApiTokenAttribute(?string $value): void
    {
        if ($value) {
            $this->attributes['api_token'] = Crypt::encryptString($value);
        } else {
            $this->attributes['api_token'] = null;
        }
    }

    /**
     * Decrypt the API token when getting
     */
    public function getApiTokenAttribute(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

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

    /**
     * Get the Devaito API base URL for this store
     */
    public function getApiBaseUrl(): string
    {
        return "https://{$this->store_name}.devaito.com/api/v1/ai-agent";
    }

    /**
     * Get the store's product page base URL
     */
    public function getStoreBaseUrl(): string
    {
        return "https://{$this->store_name}.devaito.com";
    }
}
