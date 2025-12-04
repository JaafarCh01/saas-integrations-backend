<?php

namespace App\Http\Controllers;

use App\Models\WhatsAppStoreConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client as TwilioClient;
use Twilio\Exceptions\TwilioException;

class WhatsAppProvisioningController extends Controller
{
    private TwilioClient $twilio;

    public function __construct()
    {
        $this->twilio = new TwilioClient(
            config('services.twilio.account_sid'),
            config('services.twilio.auth_token')
        );
    }

    /**
     * Search for available phone numbers in a given country
     * Implements fallback: Local → Mobile → National
     * 
     * GET /api/v1/provisioning/search?country=US&limit=10
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => 'nullable|string|size:2',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $country = strtoupper($validated['country'] ?? 'US');
        $limit = $validated['limit'] ?? 10;

        // Number types to try in order (fallback mechanism)
        $numberTypes = ['local', 'mobile', 'national'];
        $availableNumbers = [];
        $usedType = null;

        foreach ($numberTypes as $type) {
            try {
                $numbers = $this->searchByType($country, $type, $limit);
                
                if (!empty($numbers)) {
                    $availableNumbers = $numbers;
                    $usedType = $type;
                    break; // Found numbers, stop searching
                }
            } catch (TwilioException $e) {
                // Log and continue to next type (some countries don't support certain types)
                Log::info("Twilio search: $type numbers not available for $country", [
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
        }

        // Format the response
        $numbers = array_map(function ($number) use ($usedType) {
            return [
                'phone_number' => $number->phoneNumber,
                'friendly_name' => $number->friendlyName,
                'locality' => $number->locality ?? null,
                'region' => $number->region ?? null,
                'country' => $number->isoCountry,
                'postal_code' => $number->postalCode ?? null,
                'number_type' => $usedType,
                'capabilities' => [
                    'voice' => $number->capabilities->voice ?? false,
                    'sms' => $number->capabilities->sms ?? false,
                    'mms' => $number->capabilities->mms ?? false,
                ],
            ];
        }, $availableNumbers);

        return response()->json([
            'success' => true,
            'country' => $country,
            'number_type' => $usedType,
            'count' => count($numbers),
            'numbers' => $numbers,
        ]);
    }

    /**
     * Search for numbers of a specific type
     */
    private function searchByType(string $country, string $type, int $limit): array
    {
        $availablePhoneNumbers = $this->twilio->availablePhoneNumbers($country);

        $searchParams = [
            'smsEnabled' => true,
            'voiceEnabled' => true,
        ];

        return match ($type) {
            'local' => $availablePhoneNumbers->local->read($searchParams, $limit),
            'mobile' => $availablePhoneNumbers->mobile->read($searchParams, $limit),
            'national' => $availablePhoneNumbers->national->read($searchParams, $limit),
            default => [],
        };
    }

    /**
     * Purchase a phone number and configure it for a store
     * 
     * POST /api/v1/provisioning/buy
     * { "phone_number": "+15551234567", "store_name": "mugstroe" }
     */
    public function buy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone_number' => 'required|string',
            'store_name' => 'required|string|max:100',
            'api_token' => 'nullable|string', // Optional: Devaito API token
        ]);

        $phoneNumber = $validated['phone_number'];
        $storeName = $validated['store_name'];
        $apiToken = $validated['api_token'] ?? null;

        // Check if store already has an active number
        $existingConfig = WhatsAppStoreConfig::where('store_name', $storeName)
            ->where('is_active', true)
            ->first();

        if ($existingConfig) {
            return response()->json([
                'success' => false,
                'error' => 'Store already has an active WhatsApp number: ' . $existingConfig->twilio_phone_number,
            ], 400);
        }

        // Check if phone number is already in use
        $numberInUse = WhatsAppStoreConfig::where('twilio_phone_number', $phoneNumber)
            ->where('is_active', true)
            ->exists();

        if ($numberInUse) {
            return response()->json([
                'success' => false,
                'error' => 'This phone number is already assigned to another store.',
            ], 400);
        }

        try {
            // Purchase the number from Twilio
            $purchasedNumber = $this->twilio->incomingPhoneNumbers->create([
                'phoneNumber' => $phoneNumber,
                'smsUrl' => config('services.twilio.whatsapp_webhook_url'),
                'smsMethod' => 'POST',
                'voiceUrl' => config('services.twilio.whatsapp_webhook_url'),
                'voiceMethod' => 'POST',
            ]);

            Log::info('Twilio number purchased', [
                'phone_number' => $purchasedNumber->phoneNumber,
                'sid' => $purchasedNumber->sid,
                'store_name' => $storeName,
            ]);

            // Create/update the store config
            $storeConfig = WhatsAppStoreConfig::updateOrCreate(
                ['store_name' => $storeName],
                [
                    'twilio_phone_number' => $purchasedNumber->phoneNumber,
                    'api_token' => $apiToken ?? '',
                    'is_active' => true,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Phone number purchased and configured successfully',
                'data' => [
                    'phone_number' => $purchasedNumber->phoneNumber,
                    'store_name' => $storeName,
                    'twilio_sid' => $purchasedNumber->sid,
                    'webhook_url' => config('services.twilio.whatsapp_webhook_url'),
                    'is_active' => $storeConfig->is_active,
                ],
            ]);

        } catch (TwilioException $e) {
            Log::error('Twilio purchase failed', [
                'phone_number' => $phoneNumber,
                'store_name' => $storeName,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to purchase phone number: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get provisioning status for a store
     * 
     * GET /api/v1/provisioning/status?store_name=mugstroe
     */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $validated['store_name'];

        $config = WhatsAppStoreConfig::where('store_name', $storeName)->first();

        if (!$config) {
            return response()->json([
                'success' => true,
                'provisioned' => false,
                'store_name' => $storeName,
                'message' => 'No WhatsApp number configured for this store',
            ]);
        }

        return response()->json([
            'success' => true,
            'provisioned' => true,
            'store_name' => $config->store_name,
            'phone_number' => $config->twilio_phone_number,
            'is_active' => $config->is_active,
            'has_api_token' => !empty($config->api_token),
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Update store configuration (API token)
     * 
     * PUT /api/v1/provisioning/config
     * { "store_name": "mugstroe", "api_token": "Bearer xxx" }
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
            'api_token' => 'required|string',
        ]);

        $config = WhatsAppStoreConfig::where('store_name', $validated['store_name'])->first();

        if (!$config) {
            return response()->json([
                'success' => false,
                'error' => 'No configuration found for this store',
            ], 404);
        }

        $config->update([
            'api_token' => $validated['api_token'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Configuration updated successfully',
            'store_name' => $config->store_name,
        ]);
    }

    /**
     * Deactivate a store's WhatsApp number
     * 
     * DELETE /api/v1/provisioning/deactivate
     * { "store_name": "mugstroe" }
     */
    public function deactivate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
        ]);

        $config = WhatsAppStoreConfig::where('store_name', $validated['store_name'])->first();

        if (!$config) {
            return response()->json([
                'success' => false,
                'error' => 'No configuration found for this store',
            ], 404);
        }

        $config->update(['is_active' => false]);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp number deactivated for store',
            'store_name' => $config->store_name,
            'phone_number' => $config->twilio_phone_number,
        ]);
    }

    /**
     * Get list of supported countries for number search
     * 
     * GET /api/v1/provisioning/countries
     */
    public function countries(): JsonResponse
    {
        // Most commonly used countries for WhatsApp Business
        $countries = [
            ['code' => 'US', 'name' => 'United States', 'flag' => '🇺🇸'],
            ['code' => 'CA', 'name' => 'Canada', 'flag' => '🇨🇦'],
            ['code' => 'GB', 'name' => 'United Kingdom', 'flag' => '🇬🇧'],
            ['code' => 'DE', 'name' => 'Germany', 'flag' => '🇩🇪'],
            ['code' => 'FR', 'name' => 'France', 'flag' => '🇫🇷'],
            ['code' => 'ES', 'name' => 'Spain', 'flag' => '🇪🇸'],
            ['code' => 'IT', 'name' => 'Italy', 'flag' => '🇮🇹'],
            ['code' => 'NL', 'name' => 'Netherlands', 'flag' => '🇳🇱'],
            ['code' => 'AU', 'name' => 'Australia', 'flag' => '🇦🇺'],
            ['code' => 'BR', 'name' => 'Brazil', 'flag' => '🇧🇷'],
            ['code' => 'MX', 'name' => 'Mexico', 'flag' => '🇲🇽'],
            ['code' => 'IN', 'name' => 'India', 'flag' => '🇮🇳'],
        ];

        return response()->json([
            'success' => true,
            'countries' => $countries,
        ]);
    }
}

