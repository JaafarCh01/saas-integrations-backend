<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class EmailConfig extends Model
{
    /**
     * Provider presets for common email services
     */
    public const PROVIDER_PRESETS = [
        'gmail' => [
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ],
        'yahoo' => [
            'imap_host' => 'imap.mail.yahoo.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp.mail.yahoo.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ],
        'outlook' => [
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'smtp_host' => 'smtp-mail.outlook.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
        ],
    ];

    protected $fillable = [
        'store_name',
        'email_address',
        'provider',
        'app_password',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'ai_active',
        'ai_system_prompt',
        'manual_approval',
        'api_token',
        'is_active',
        'last_polled_at',
        'last_error',
    ];

    protected $casts = [
        'ai_active' => 'boolean',
        'is_active' => 'boolean',
        'manual_approval' => 'boolean',
        'imap_port' => 'integer',
        'smtp_port' => 'integer',
        'last_polled_at' => 'datetime',
    ];

    protected $hidden = [
        'app_password',
        'api_token',
    ];

    /**
     * Encrypt the app password when setting
     */
    public function setAppPasswordAttribute(?string $value): void
    {
        if ($value) {
            $this->attributes['app_password'] = Crypt::encryptString($value);
        } else {
            $this->attributes['app_password'] = null;
        }
    }

    /**
     * Decrypt the app password when getting
     */
    public function getAppPasswordAttribute(?string $value): ?string
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
     * Find config by store name
     */
    public static function findByStoreName(string $storeName): ?self
    {
        return self::where('store_name', $storeName)->first();
    }

    /**
     * Get all active configs for polling
     */
    public static function getActiveConfigs()
    {
        return self::where('is_active', true)
            ->where('ai_active', true)
            ->get();
    }

    /**
     * Check if the AI agent should respond
     */
    public function shouldRespond(): bool
    {
        return $this->is_active && $this->ai_active;
    }

    /**
     * Get provider preset settings
     */
    public static function getProviderPreset(string $provider): ?array
    {
        return self::PROVIDER_PRESETS[$provider] ?? null;
    }

    /**
     * Get IMAP configuration array
     */
    public function getImapConfig(): array
    {
        return [
            'host' => $this->imap_host,
            'port' => $this->imap_port,
            'encryption' => $this->imap_encryption,
            'username' => $this->email_address,
            'password' => $this->app_password,
            'validate_cert' => true,
        ];
    }

    /**
     * Get SMTP configuration array for dynamic mailer
     */
    public function getSmtpConfig(): array
    {
        return [
            'transport' => 'smtp',
            'host' => $this->smtp_host,
            'port' => $this->smtp_port,
            'encryption' => $this->smtp_encryption,
            'username' => $this->email_address,
            'password' => $this->app_password,
            'from' => [
                'address' => $this->email_address,
                'name' => $this->store_name,
            ],
        ];
    }

    /**
     * Get the Devaito API base URL for this store
     */
    public function getApiBaseUrl(): string
    {
        return "https://{$this->store_name}.devaito.com/api/v1/ai-agent";
    }

    /**
     * Update last polled timestamp
     */
    public function markPolled(): void
    {
        $this->update([
            'last_polled_at' => now(),
            'last_error' => null,
        ]);
    }

    /**
     * Record polling error
     */
    public function recordError(string $error): void
    {
        $this->update([
            'last_polled_at' => now(),
            'last_error' => substr($error, 0, 255),
        ]);
    }
}
