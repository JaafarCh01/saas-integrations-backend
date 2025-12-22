<?php

namespace App\Jobs;

use App\Models\EmailConfig;
use App\Models\EmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;

class PollEmailInboxes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job - Hybrid Polling Strategy
     * 
     * Laravel fetches emails via IMAP, forwards to n8n for AI processing.
     * Only marks as read if n8n returns 200 OK.
     */
    public function handle(): void
    {
        // Memory safety
        ini_set('memory_limit', '2048M');
        set_time_limit(300);

        $webhookUrl = config('services.n8n_email.webhook_url');
        if (!$webhookUrl) {
            Log::error('PollEmailInboxes: N8N_EMAIL_WEBHOOK_URL not configured');
            return;
        }

        $configs = EmailConfig::getActiveConfigs();
        Log::info('PollEmailInboxes: Starting hybrid poll', ['config_count' => $configs->count()]);

        foreach ($configs as $config) {
            try {
                $this->pollInbox($config, $webhookUrl);
                $config->markPolled();
            } catch (\Exception $e) {
                Log::error('PollEmailInboxes: Error polling inbox', [
                    'store_name' => $config->store_name,
                    'error' => $e->getMessage(),
                ]);
                $config->recordError($e->getMessage());
            }
        }
    }

    /**
     * Poll a single email inbox and forward to n8n
     */
    protected function pollInbox(EmailConfig $config, string $webhookUrl): void
    {
        Log::info('PollEmailInboxes: Polling inbox', [
            'store_name' => $config->store_name,
            'email' => $config->email_address,
        ]);

        // Create IMAP client
        $cm = new ClientManager();
        $client = $cm->make($config->getImapConfig());
        $client->connect();

        // Get INBOX folder
        $folder = $client->getFolder('INBOX');

        // Fetch 5 unseen emails (no sort - avoids server crash)
        $messages = $folder->query()->unseen()->limit(5)->get();

        Log::info('PollEmailInboxes: Found unread emails', [
            'store_name' => $config->store_name,
            'count' => $messages->count(),
        ]);

        // Reverse in PHP to process newest first
        foreach ($messages->reverse() as $message) {
            try {
                $success = $this->forwardToN8n($config, $message, $webhookUrl);

                // Only mark as read if n8n succeeded
                if ($success) {
                    $message->setFlag('Seen');
                    Log::info('PollEmailInboxes: Email forwarded and marked as read', [
                        'subject' => $message->getSubject(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('PollEmailInboxes: Error forwarding email', [
                    'store_name' => $config->store_name,
                    'subject' => $message->getSubject(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $client->disconnect();
    }

    /**
     * Forward email to n8n webhook for AI processing
     * Returns true if n8n returns 200 OK
     */
    protected function forwardToN8n(EmailConfig $config, $message, string $webhookUrl): bool
    {
        $fromEmail = $message->getFrom()[0]->mail ?? 'unknown';
        $fromName = $message->getFrom()[0]->personal ?? null;

        // Skip our own replies (from the same email address)
        if (strtolower($fromEmail) === strtolower($config->email_address)) {
            Log::debug('PollEmailInboxes: Skipping own email');
            return true; // Mark as read anyway
        }

        // Get message ID - IMAP library returns object, cast to string
        $messageId = $message->getMessageId();
        $messageIdStr = is_object($messageId) ? (string) $messageId : ($messageId ?? '');

        if (EmailLog::isMessageProcessed($messageIdStr)) {
            Log::debug('PollEmailInboxes: Skipping already processed email', [
                'message_id' => $messageIdStr,
            ]);
            return true; // Mark as read anyway
        }

        // Get subject - IMAP library returns object, cast to string
        $subject = $message->getSubject();
        $subjectStr = is_object($subject) ? (string) $subject : ($subject ?? '');

        // Get references - IMAP library returns object or array
        $references = $message->getReferences() ?? $message->getInReplyTo();
        $referencesStr = '';
        if (is_object($references)) {
            $referencesStr = (string) $references;
        } elseif (is_array($references)) {
            $referencesStr = implode(' ', $references);
        } elseif (is_string($references)) {
            $referencesStr = $references;
        }

        // Generate conversation ID (same logic used when logging)
        $conversationId = EmailLog::generateConversationId(
            $config->store_name,
            $referencesStr,
            $subjectStr
        );

        // Build payload with email content + SMTP credentials
        $payload = [
            // Account identifier
            'config_id' => $config->id,

            // Conversation ID for n8n to use when logging AI response
            'conversation_id' => $conversationId,

            // Email content (all cast to strings)
            'subject' => $subjectStr,
            'body' => $message->getTextBody() ?? strip_tags($message->getHTMLBody() ?? ''),
            'from_email' => $fromEmail,
            'from_name' => $fromName,
            'message_id' => $messageIdStr,
            'references' => $referencesStr,

            // SMTP credentials for n8n to reply
            'smtp_host' => $config->smtp_host,
            'smtp_port' => $config->smtp_port,
            'smtp_user' => $config->email_address,
            'smtp_password' => $config->app_password,

            // Store context
            'store_name' => $config->store_name,
            'ai_system_prompt' => $config->ai_system_prompt,
        ];

        Log::info('PollEmailInboxes: Forwarding to n8n', [
            'store_name' => $config->store_name,
            'from' => $fromEmail,
            'subject' => $subjectStr,
        ]);

        // Log full payload for debugging (exclude password)
        Log::debug('PollEmailInboxes: Payload', [
            'config_id' => $payload['config_id'],
            'subject' => $payload['subject'],
            'body_length' => strlen($payload['body']),
            'body_preview' => substr($payload['body'], 0, 200),
            'from_email' => $payload['from_email'],
            'from_name' => $payload['from_name'],
            'message_id' => $payload['message_id'],
            'references' => $payload['references'],
            'smtp_host' => $payload['smtp_host'],
            'smtp_port' => $payload['smtp_port'],
            'smtp_user' => $payload['smtp_user'],
            'store_name' => $payload['store_name'],
            'ai_system_prompt_length' => strlen($payload['ai_system_prompt'] ?? ''),
        ]);

        // Send to n8n
        $response = Http::timeout(60)->post($webhookUrl, $payload);

        if ($response->successful()) {
            // Log the conversation (n8n will also log via /api/v1/agent/log)
            EmailLog::create([
                'store_name' => $config->store_name,
                'conversation_id' => EmailLog::generateConversationId(
                    $config->store_name,
                    $referencesStr,
                    $subjectStr
                ),
                'from_email' => $fromEmail,
                'from_name' => $fromName,
                'subject' => $subjectStr,
                'user_message' => $payload['body'],
                'message_id' => $messageIdStr,
                'status' => 'forwarded',
            ]);
            return true;
        }

        Log::warning('PollEmailInboxes: n8n webhook failed', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        return false;
    }
}
