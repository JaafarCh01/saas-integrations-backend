<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Twilio\Rest\Client as TwilioClient;

class WhatsAppStoreConfig extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'whatsapp_store_configs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'store_name',
        'twilio_sid',
        'twilio_token',
        'twilio_phone_number',
        'api_token',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'api_token',
        'twilio_token',
        'twilio_sid',
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
     * Encrypt the Twilio token when setting
     */
    public function setTwilioTokenAttribute(?string $value): void
    {
        if ($value) {
            $this->attributes['twilio_token'] = Crypt::encryptString($value);
        } else {
            $this->attributes['twilio_token'] = null;
        }
    }

    /**
     * Decrypt the Twilio token when getting
     */
    public function getTwilioTokenAttribute(?string $value): ?string
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
     * Check if this config has user-provided Twilio credentials
     */
    public function hasUserCredentials(): bool
    {
        return !empty($this->twilio_sid) && !empty($this->attributes['twilio_token']);
    }

    /**
     * Get a Twilio client using this store's credentials
     */
    public function getTwilioClient(): ?TwilioClient
    {
        if (!$this->hasUserCredentials()) {
            return null;
        }

        return new TwilioClient($this->twilio_sid, $this->twilio_token);
    }

    /**
     * Find config by Twilio phone number
     */
    public static function findByTwilioNumber(string $phoneNumber): ?self
    {
        // Normalize phone number (remove whatsapp: prefix if present)
        $normalizedPhone = str_replace('whatsapp:', '', $phoneNumber);
        
        return self::where('twilio_phone_number', $normalizedPhone)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Find config by store name
     */
    public static function findByStoreName(string $storeName): ?self
    {
        return self::where('store_name', $storeName)
            ->where('is_active', true)
            ->first();
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

