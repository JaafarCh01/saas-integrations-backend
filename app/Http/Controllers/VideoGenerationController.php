<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\VideoJob;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        $validated = $request->validate([
            'store_id' => 'required',
            'product_name' => 'required',
            'product_description' => 'nullable',
            'product_image_url' => 'required|url',
            'ugc_style' => 'required',
            // ... other settings
        ]);

        $jobId = 'ugc_' . Str::random(10);

        $job = VideoJob::create([
            'job_id' => $jobId,
            'store_id' => $validated['store_id'],
            'product_name' => $validated['product_name'],
            'product_description' => $validated['product_description'] ?? '',
            'product_image_url' => $validated['product_image_url'],
            'status' => 'pending',
        ]);

        // Send to n8n
        $n8nWebhookUrl = config('services.n8n.webhook_url');
        
        if ($n8nWebhookUrl) {
             try {
                Http::post($n8nWebhookUrl, array_merge($validated, ['job_id' => $jobId]));
             } catch (\Exception $e) {
                 Log::error("Failed to send to n8n: " . $e->getMessage());
             }
        } else {
            Log::warning("N8N_WEBHOOK_URL not set. Job created but not sent.");
        }

        return response()->json(['job_id' => $jobId, 'status' => 'pending']);
    }

    // 3. Check Status
    public function status($jobId)
    {
        $job = VideoJob::where('job_id', $jobId)->firstOrFail();

        return response()->json([
            'job_id' => $job->job_id,
            'status' => $job->status,
            'video_url' => $job->video_url ? route('video.proxy', ['jobId' => $job->job_id]) : null,
        ]);
    }

    // 4. Proxy Video
    public function proxyVideo($jobId)
    {
        $job = VideoJob::where('job_id', $jobId)->firstOrFail();

        if (!$job->video_url || !Storage::exists($job->video_url)) {
            return response()->json(['error' => 'Video not found'], 404);
        }

        $path = Storage::path($job->video_url);
        
        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => 'bytes',
        ]);
    }
}
