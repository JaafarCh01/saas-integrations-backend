<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VideoJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;

class VideoWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // 1. Security Check
        // In production, check request signature or secret
        // if ($request->header('X-Webhook-Secret') !== config('services.n8n.webhook_secret')) {
        //    abort(403, 'Unauthorized');
        // }

        $jobId = $request->input('job_id');
        $status = $request->input('status');
        $videoUrl = $request->input('video_url'); // Google URL

        Log::info("Webhook received for Job: {$jobId}", $request->all());

        $job = VideoJob::where('job_id', $jobId)->first();

        if (!$job) {
            Log::error("Job not found: {$jobId}");
            return response()->json(['error' => 'Job not found'], 404);
        }

        if ($status === 'completed' && $videoUrl) {
            // Download the video immediately
            try {
                // Append API Key if it's a Google URL and we have a key
                if (str_contains($videoUrl, 'googleapis.com') && config('services.google.api_key')) {
                    $separator = str_contains($videoUrl, '?') ? '&' : '?';
                    $videoUrl .= $separator . 'key=' . config('services.google.api_key');
                }

                $response = Http::timeout(120)->get($videoUrl);

                if ($response->failed()) {
                    throw new \Exception("Failed to download video. Status: " . $response->status());
                }

                $contentType = $response->header('Content-Type');
                if (strpos($contentType, 'application/json') !== false) {
                    $error = $response->json();
                    $errorMessage = $error['error']['message'] ?? 'Unknown API error';
                    throw new \Exception("API Error: " . $errorMessage);
                }

                $videoContent = $response->body();
                $filename = "videos/{$jobId}.mp4";

                Log::info("Attempting to store video", [
                    'filename' => $filename,
                    'disk' => config('filesystems.default'),
                    'bucket' => config('filesystems.disks.gcs.bucket'),
                    'content_length' => strlen($videoContent),
                ]);

                // Don't use 'public' visibility - bucket uses uniform bucket-level access
                try {
                    $stored = Storage::put($filename, $videoContent);
                    Log::info("Storage::put returned", ['stored' => $stored]);
                } catch (\Exception $storageError) {
                    // Get the root cause - Flysystem wraps the real error
                    $previousError = $storageError->getPrevious();
                    Log::error("Storage::put threw exception", [
                        'error' => $storageError->getMessage(),
                        'previous_error' => $previousError ? $previousError->getMessage() : 'none',
                        'previous_class' => $previousError ? get_class($previousError) : 'none',
                        'trace' => $storageError->getTraceAsString(),
                    ]);
                    throw $storageError;
                }

                // Verify the file was actually written
                $exists = Storage::exists($filename);
                Log::info("Storage::exists check", ['exists' => $exists]);

                if (!$stored || !$exists) {
                    throw new \Exception("Failed to write video to storage. stored={$stored}, exists={$exists}");
                }

                $job->update([
                    'status' => 'completed',
                    'video_url' => $filename, // Local path
                    'external_video_url' => $videoUrl,
                    'motion_prompt' => $request->input('motion_prompt'),
                ]);

                Log::info("Video downloaded and saved: {$filename}");

            } catch (\Exception $e) {
                Log::error("Failed to download video: " . $e->getMessage());
                $job->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            }
        } elseif ($status === 'failed') {
            $job->update([
                'status' => 'failed',
                'error_message' => $request->input('error_message')
            ]);
        }

        return response()->json(['message' => 'Webhook processed']);
    }
}
