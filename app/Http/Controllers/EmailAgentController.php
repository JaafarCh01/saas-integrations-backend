<?php

namespace App\Http\Controllers;

use App\Models\AgentLog;
use App\Models\EmailConfig;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Webklex\PHPIMAP\ClientManager;
class EmailAgentController extends Controller
{
    /**
     * Connect email account with App Password
     * 
     * POST /api/email/connect
     */
    public function connect(Request $request)
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
            'email_address' => 'required|email',
            'app_password' => 'required|string',
            'provider' => 'required|in:gmail,yahoo,outlook,custom',
            // Custom provider settings (optional)
            'imap_host' => 'required_if:provider,custom|string|nullable',
            'imap_port' => 'nullable|integer',
            'smtp_host' => 'required_if:provider,custom|string|nullable',
            'smtp_port' => 'nullable|integer',
        ]);

        try {
            // Get provider preset or use custom settings
            $preset = EmailConfig::getProviderPreset($validated['provider']);

            $configData = [
                'store_name' => $validated['store_name'],
                'email_address' => $validated['email_address'],
                'app_password' => $validated['app_password'],
                'provider' => $validated['provider'],
                'imap_host' => $validated['imap_host'] ?? $preset['imap_host'] ?? '',
                'imap_port' => $validated['imap_port'] ?? $preset['imap_port'] ?? 993,
                'imap_encryption' => $preset['imap_encryption'] ?? 'ssl',
                'smtp_host' => $validated['smtp_host'] ?? $preset['smtp_host'] ?? '',
                'smtp_port' => $validated['smtp_port'] ?? $preset['smtp_port'] ?? 587,
                'smtp_encryption' => $preset['smtp_encryption'] ?? 'tls',
                'is_active' => true,
            ];

            // Capture Devaito API token if present
            $apiToken = $request->bearerToken();
            if ($apiToken) {
                $configData['api_token'] = $apiToken;
            }

            // Create or update config
            $config = EmailConfig::updateOrCreate(
                ['store_name' => $validated['store_name']],
                $configData
            );

            Log::info('Email account connected', [
                'store_name' => $validated['store_name'],
                'email' => $validated['email_address'],
                'provider' => $validated['provider'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email account connected successfully',
                'email_address' => $config->email_address,
                'provider' => $config->provider,
            ]);
        } catch (\Exception $e) {
            Log::error('Email connect error', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => 'Failed to connect email account',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get email connection status
     * 
     * GET /api/email/status
     */
    public function status(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $config = EmailConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'connected' => false,
                'provisioned' => false,
            ]);
        }

        return response()->json([
            'connected' => true,
            'provisioned' => $config->is_active,
            'email_address' => $config->email_address,
            'provider' => $config->provider,
            'ai_active' => $config->ai_active,
            'ai_system_prompt' => $config->ai_system_prompt,
            'manual_approval' => $config->manual_approval,
            'last_polled_at' => $config->last_polled_at?->toISOString(),
            'last_error' => $config->last_error,
        ]);
    }

    /**
     * Update email AI configuration
     * 
     * PUT /api/email/config
     */
    public function updateConfig(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
            'ai_active' => 'sometimes|boolean',
            'ai_system_prompt' => 'sometimes|nullable|string',
            'manual_approval' => 'sometimes|boolean',
        ]);

        $storeName = $request->input('store_name');
        $config = EmailConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'error' => 'Email not connected for this store',
            ], 404);
        }

        if ($request->has('ai_active')) {
            $config->ai_active = $request->input('ai_active');
        }
        if ($request->has('ai_system_prompt')) {
            $config->ai_system_prompt = $request->input('ai_system_prompt');
        }
        if ($request->has('manual_approval')) {
            $config->manual_approval = $request->input('manual_approval');
        }

        $config->save();

        return response()->json([
            'success' => true,
            'ai_active' => $config->ai_active,
            'ai_system_prompt' => $config->ai_system_prompt,
            'manual_approval' => $config->manual_approval,
        ]);
    }

    /**
     * Disconnect email account
     * 
     * DELETE /api/email/disconnect
     */
    public function disconnect(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $config = EmailConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'error' => 'Email not connected for this store',
            ], 404);
        }

        $config->delete();

        Log::info('Email account disconnected', ['store_name' => $storeName]);

        return response()->json([
            'success' => true,
            'message' => 'Email account disconnected successfully',
        ]);
    }

    /**
     * Get email stats for dashboard
     * 
     * GET /api/email/stats
     */
    public function stats(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $stats = EmailLog::getStatsForStore($storeName);

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Get list of conversations for a store
     * 
     * GET /api/email/conversations/{storeName}
     */
    public function conversations(string $storeName)
    {
        $conversations = EmailLog::getConversationSummaries($storeName);

        return response()->json([
            'success' => true,
            'conversations' => $conversations,
        ]);
    }

    /**
     * Get full conversation history
     * 
     * GET /api/email/conversation/{conversationId}
     */
    public function conversationHistory(string $conversationId)
    {
        $history = EmailLog::getConversationHistory($conversationId);

        if (empty($history)) {
            return response()->json([
                'error' => 'Conversation not found',
            ], 404);
        }

        return response()->json($history);
    }

    /**
     * Test email connection (IMAP)
     * 
     * POST /api/email/test
     */
    public function testConnection(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->input('store_name');
        $config = EmailConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'error' => 'Email not connected for this store',
            ], 404);
        }

        try {
            // Try to connect via IMAP
            $cm = new ClientManager();
            $client = $cm->make($config->getImapConfig());
            $client->connect();
            $client->disconnect();

            return response()->json([
                'success' => true,
                'message' => 'Connection successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Connection failed: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Approve a draft reply and send via SMTP
     * 
     * POST /api/email/{id}/approve
     */
    public function approveDraft(int $id)
    {
        try {
            $log = AgentLog::findOrFail($id);

            // Verify this is a pending draft
            if ($log->approval_status !== 'pending_approval') {
                return response()->json([
                    'error' => 'This message is not pending approval',
                    'current_status' => $log->approval_status,
                ], 400);
            }

            // Verify we have draft content
            if (empty($log->draft_reply)) {
                return response()->json([
                    'error' => 'No draft reply found for this message',
                ], 400);
            }

            // Get email config for SMTP credentials
            $config = EmailConfig::findByStoreName($log->store_name);
            if (!$config) {
                return response()->json([
                    'error' => 'Email configuration not found for store: ' . $log->store_name,
                ], 404);
            }

            // Configure dynamic mailer with store's SMTP settings
            config(['mail.mailers.dynamic' => $config->getSmtpConfig()]);

            // Determine recipient email
            $recipientEmail = $log->reply_to_email ?? $log->customer_phone; // customer_phone may contain email for email agent
            if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                return response()->json([
                    'error' => 'No valid recipient email address found',
                ], 400);
            }

            // Build subject line
            $subject = $log->reply_subject
                ? 'Re: ' . preg_replace('/^Re:\s*/i', '', $log->reply_subject)
                : 'Re: Your inquiry';

            // Send the email via Laravel Mailer
            Mail::mailer('dynamic')->raw($log->draft_reply, function ($message) use ($recipientEmail, $subject, $config) {
                $message->to($recipientEmail)
                    ->subject($subject)
                    ->from($config->email_address, $config->store_name);
            });

            // Update the log record
            $log->update([
                'approval_status' => 'approved',
                'ai_response' => $log->draft_reply,  // Copy draft to ai_response
                'status' => 'success',
            ]);

            Log::info('Email draft approved and sent', [
                'log_id' => $log->id,
                'store_name' => $log->store_name,
                'recipient' => $recipientEmail,
                'subject' => $subject,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Email sent successfully',
                'recipient' => $recipientEmail,
                'subject' => $subject,
            ]);

        } catch (\Exception $e) {
            Log::error('Email approve/send error', [
                'log_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to send email: ' . $e->getMessage(),
            ], 500);
        }
    }
}

