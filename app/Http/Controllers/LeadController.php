<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /**
     * Ingest leads from n8n workflow
     * 
     * POST /api/leads/ingest
     */
    public function ingest(Request $request)
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
            'platform' => ['required', Rule::in(['instagram', 'twitter', 'tiktok'])],
            'external_id' => 'required|string',
            'username' => 'required|string',
            'profile_url' => 'required|url',
            'context' => 'nullable|array',
            'quality_score' => 'nullable|integer|min:0|max:100',
            'draft_message' => 'nullable|string',
        ]);

        try {
            // Use updateOrCreate to prevent duplicates
            $lead = Lead::updateOrCreate(
                [
                    'store_name' => $validated['store_name'],
                    'platform' => $validated['platform'],
                    'external_id' => $validated['external_id'],
                ],
                [
                    'username' => $validated['username'],
                    'profile_url' => $validated['profile_url'],
                    'context' => $validated['context'] ?? null,
                    'quality_score' => $validated['quality_score'] ?? 0,
                    'draft_message' => $validated['draft_message'] ?? null,
                ]
            );

            Log::info('Lead ingested', [
                'id' => $lead->id,
                'platform' => $lead->platform,
                'username' => $lead->username,
                'was_created' => $lead->wasRecentlyCreated,
            ]);

            return response()->json([
                'success' => true,
                'lead_id' => $lead->id,
                'created' => $lead->wasRecentlyCreated,
            ]);
        } catch (\Exception $e) {
            Log::error('Lead ingest error', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to ingest lead',
            ], 500);
        }
    }

    /**
     * Get pending leads for dashboard
     * 
     * GET /api/leads/pending
     */
    public function pending(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
            'platform' => ['nullable', Rule::in(['instagram', 'twitter', 'tiktok'])],
            'min_score' => 'nullable|integer|min:0|max:100',
        ]);

        $storeName = $request->query('store_name');
        $platform = $request->query('platform');
        $minScore = $request->query('min_score', 0);

        $query = Lead::byStore($storeName)
            ->pending()
            ->where('quality_score', '>=', $minScore)
            ->orderByDesc('quality_score')
            ->orderByDesc('created_at');

        if ($platform) {
            $query->byPlatform($platform);
        }

        $leads = $query->get()->map(fn($lead) => [
            'id' => $lead->id,
            'platform' => $lead->platform,
            'external_id' => $lead->external_id,
            'username' => $lead->username,
            'profile_url' => $lead->profile_url,
            'context' => $lead->context,
            'quality_score' => $lead->quality_score,
            'draft_message' => $lead->draft_message,
            'deep_link' => $lead->getDeepLink(),
            'created_at' => $lead->created_at->toISOString(),
        ]);

        $stats = Lead::getStatsForStore($storeName);

        return response()->json([
            'success' => true,
            'leads' => $leads,
            'stats' => $stats,
        ]);
    }

    /**
     * Mark lead as sent
     * 
     * POST /api/leads/{id}/mark-sent
     */
    public function markSent(Request $request, int $id)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $lead = Lead::byStore($request->input('store_name'))->findOrFail($id);
        $lead->markSent();

        Log::info('Lead marked as sent', [
            'id' => $lead->id,
            'username' => $lead->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead marked as sent',
        ]);
    }

    /**
     * Reject lead
     * 
     * POST /api/leads/{id}/reject
     */
    public function reject(Request $request, int $id)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $lead = Lead::byStore($request->input('store_name'))->findOrFail($id);
        $lead->reject();

        Log::info('Lead rejected', [
            'id' => $lead->id,
            'username' => $lead->username,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead rejected',
        ]);
    }

    /**
     * Get stats for dashboard
     * 
     * GET /api/leads/stats
     */
    public function stats(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $stats = Lead::getStatsForStore($storeName);

        return response()->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }

    /**
     * Get active agents for n8n to process
     * 
     * GET /api/agents/active
     */
    public function activeAgents()
    {
        $agents = LeadConfig::getActiveAgents();

        return response()->json([
            'success' => true,
            'agents' => $agents,
        ]);
    }

    /**
     * Get lead config status for a store
     * 
     * GET /api/lead-config/status
     */
    public function configStatus(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $storeName = $request->query('store_name');
        $config = LeadConfig::findByStoreName($storeName);

        if (!$config) {
            return response()->json([
                'configured' => false,
                'is_active' => false,
            ]);
        }

        return response()->json([
            'configured' => true,
            'is_active' => $config->is_active,
            'hashtags' => $config->hashtags ?? [],
            'platforms' => $config->platforms ?? ['tiktok'],
            'ai_system_prompt' => $config->ai_system_prompt,
            'last_scraped_at' => $config->last_scraped_at?->toISOString(),
        ]);
    }

    /**
     * Save lead config for a store
     * 
     * POST /api/lead-config/save
     */
    public function saveConfig(Request $request)
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string',
            'platforms' => 'nullable|array',
            'platforms.*' => Rule::in(['tiktok', 'instagram', 'twitter']),
            'is_active' => 'boolean',
            'ai_system_prompt' => 'nullable|string',
        ]);

        $config = LeadConfig::updateOrCreate(
            ['store_name' => $validated['store_name']],
            [
                'hashtags' => $validated['hashtags'] ?? [],
                'platforms' => $validated['platforms'] ?? ['tiktok'],
                'is_active' => $validated['is_active'] ?? false,
                'ai_system_prompt' => $validated['ai_system_prompt'] ?? null,
            ]
        );

        Log::info('Lead config saved', [
            'store_name' => $config->store_name,
            'hashtags' => $config->hashtags,
            'is_active' => $config->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Configuration saved',
        ]);
    }
}

