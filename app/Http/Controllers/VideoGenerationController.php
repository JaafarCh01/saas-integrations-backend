<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VideoJob;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\MultipartStream;

class VideoGenerationController extends Controller
{
    // 1. The Mock Adapter (Simulating Client SaaS)
    public function listProducts(Request $request)
    {
        // In production, this would be:
        // $response = Http::withToken($request->input('token'))->get('https://client-saas.com/api/products');
        // return $response->json();

        // Mock Data
        return response()->json([
            'products' => [
                [
                    'id' => 'prod_1',
                    'name' => 'Nike Air Max',
                    'description' => 'Iconic style and comfort.',
                    'image_url' => 'https://static.nike.com/a/images/t_PDP_1280_v1/f_auto,q_auto:eco/99486859-0ff3-46b4-949b-2d16af2ad421/custom-nike-dunk-low-by-you-su23.png',
                ],
                [
                    'id' => 'prod_2',
                    'name' => 'Adidas Ultraboost',
                    'description' => 'High performance running shoes.',
                    'image_url' => 'https://assets.adidas.com/images/h_840,f_auto,q_auto,fl_lossy,c_fill,g_auto/69cbc73d0cb846889f05af2600762602_9366/Ultraboost_Light_Running_Shoes_White_HQ6351_01_standard.jpg',
                ],
                [
                    'id' => 'prod_3',
                    'name' => 'Puma Suede',
                    'description' => 'Classic street style.',
                    'image_url' => 'https://images.puma.com/image/upload/f_auto,q_auto,b_rgb:fafafa,w_2000,h_2000/global/374915/01/sv01/fnd/EEA/fmt/png/Suede-Classic-XXI-Trainers',
                ]
            ]
        ]);
    }

    // 2. Generate Video
    public function generate(Request $request)
    {
        try {
            $validated = $request->validate([
                'store_id' => 'required',
                'product_id' => 'nullable|string',
                'product_name' => 'required',
                'product_description' => 'nullable',
                'product_image_url' => 'required|url',
                'custom_prompt' => 'nullable|string|max:1000',
                'duration' => 'nullable|integer|in:5,6,7,8',
                'language' => 'nullable|string|in:en,fr,es,de,it,pt,ar',
            ]);

            Log::info('Debug DB Config', [
                'default_connection' => config('database.default'),
                'env_db_connection' => env('DB_CONNECTION'),
                'db_host' => env('DB_HOST'),
            ]);

            $jobId = 'ugc_' . Str::random(10);

            $job = VideoJob::create([
                'job_id' => $jobId,
                'store_id' => $validated['store_id'],
                'product_id' => $validated['product_id'] ?? null,
                'product_name' => $validated['product_name'],
                'product_description' => $validated['product_description'] ?? '',
                'product_image_url' => $validated['product_image_url'],
                'status' => 'pending',
            ]);

            // Send to n8n as multipart/form-data (using Guzzle directly)
            $n8nWebhookUrl = config('services.n8n.webhook_url');

            if ($n8nWebhookUrl) {
                try {
                    $client = new Client();

                    // Prepare multipart form data
                    // n8n's "Validate Input" checks for body.product_image_url
                    $response = $client->post($n8nWebhookUrl, [
                        'multipart' => [
                            ['name' => 'job_id', 'contents' => $jobId],
                            ['name' => 'store_id', 'contents' => $validated['store_id']],
                            ['name' => 'product_name', 'contents' => $validated['product_name']],
                            ['name' => 'product_description', 'contents' => $validated['product_description'] ?? ''],
                            ['name' => 'custom_prompt', 'contents' => $validated['custom_prompt'] ?? ''],
                            ['name' => 'duration', 'contents' => (string) ($validated['duration'] ?? 8)],
                            ['name' => 'language', 'contents' => $validated['language'] ?? 'en'],
                            // Send as both 'data' AND 'product_image_url' to match n8n workflow
                            ['name' => 'data', 'contents' => $validated['product_image_url']],
                            ['name' => 'product_image_url', 'contents' => $validated['product_image_url']],
                        ]
                    ]);

                    Log::info("Sent to n8n successfully", [
                        'job_id' => $jobId,
                        'status_code' => $response->getStatusCode(),
                        'image_url' => $validated['product_image_url']
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to send to n8n: " . $e->getMessage(), [
                        'job_id' => $jobId,
                        'url' => $n8nWebhookUrl
                    ]);
                    // Don't fail the request if n8n fails, just log it
                }
            } else {
                Log::warning("N8N_WEBHOOK_URL not set. Job created but not sent.");
            }

            return response()->json(['job_id' => $jobId, 'status' => 'pending']);
        } catch (\Exception $e) {
            Log::error("Generate Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // 3. Check Status
    public function status($jobId)
    {
        $job = VideoJob::where('job_id', $jobId)->firstOrFail();

        return response()->json([
            'job_id' => $job->job_id,
            'status' => $job->status,
            'video_url' => $job->video_url ? 'https://storage.googleapis.com/' . config('filesystems.disks.gcs.bucket') . '/' . $job->video_url : null,
        ]);
    }

    // 4. Proxy Video
    public function proxyVideo($jobId)
    {
        $job = VideoJob::where('job_id', $jobId)->firstOrFail();

        if (!$job->video_url || !Storage::exists($job->video_url)) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        // Use Storage::response() to handle both Local and GCS correctly
        return Storage::response($job->video_url, null, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    // 5. Get History
    public function history(Request $request)
    {
        $validated = $request->validate([
            'store_id' => 'required|string',
        ]);

        $jobs = VideoJob::where('store_id', $validated['store_id'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($job) {
                return [
                    'job_id' => $job->job_id,
                    'store_id' => $job->store_id,
                    'product_id' => $job->product_id,
                    'product_name' => $job->product_name,
                    'status' => $job->status,
                    'created_at' => $job->created_at,
                    'video_url' => $job->video_url ? 'https://storage.googleapis.com/' . config('filesystems.disks.gcs.bucket') . '/' . $job->video_url : null,
                ];
            });

        return response()->json([
            'jobs' => $jobs
        ]);
    }

    // 6. Delete Video
    public function destroy($jobId)
    {
        try {
            $job = VideoJob::where('job_id', $jobId)->firstOrFail();

            // Delete file from storage if it exists
            if ($job->video_url && Storage::exists($job->video_url)) {
                Storage::delete($job->video_url);
            }

            // Delete record from DB
            $job->delete();

            return response()->json(['message' => 'Video deleted successfully']);
        } catch (\Exception $e) {
            Log::error("Delete Error: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // 7. Generate AI Prompt for Video Description
    public function generatePrompt(Request $request)
    {
        try {
            $validated = $request->validate([
                'product_name' => 'required|string|max:255',
                'product_description' => 'nullable|string|max:1000',
            ]);

            $n8nPromptWebhookUrl = config('services.n8n.prompt_webhook_url');

            if (!$n8nPromptWebhookUrl) {
                return response()->json([
                    'error' => 'AI prompt generation is not configured'
                ], 503);
            }

            $client = new Client(['timeout' => 30]);

            $response = $client->post($n8nPromptWebhookUrl, [
                'json' => [
                    'product_name' => $validated['product_name'],
                    'product_description' => $validated['product_description'] ?? '',
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            // n8n returns { "suggestion": "..." } from UGC Prompt Suggestor workflow
            $prompt = $body['suggestion'] ?? $body['prompt'] ?? $body['output'] ?? $body['text'] ?? null;

            if (!$prompt) {
                Log::warning("n8n prompt response missing expected field", ['body' => $body]);
                return response()->json([
                    'error' => 'AI failed to generate a prompt'
                ], 500);
            }

            return response()->json(['prompt' => $prompt]);

        } catch (\Exception $e) {
            Log::error("Generate Prompt Error: " . $e->getMessage());
            return response()->json([
                'error' => 'Failed to generate AI prompt: ' . $e->getMessage()
            ], 500);
        }
    }
}
