<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AgentController extends Controller
{
    /**
     * List all agents for a store
     * 
     * GET /api/agents?store_name=demo
     */
    public function index(Request $request)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $agents = Agent::byStore($request->query('store_name'))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn($agent) => $agent->toFrontendFormat());

        return response()->json([
            'success' => true,
            'agents' => $agents,
        ]);
    }

    /**
     * Create a new agent
     * 
     * POST /api/agents
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
            'name' => 'required|string|max:255',
            'product_name' => 'required|string|max:255',
            'product_url' => 'nullable|url',
            'product_image' => 'nullable|url',
            'mode' => ['required', Rule::in(['b2c', 'b2b', 'both'])],
            'config_type' => ['nullable', Rule::in(['auto', 'advanced'])],
            'platforms' => 'nullable|array',
            'platforms.*' => Rule::in(['instagram', 'tiktok', 'twitter', 'linkedin']),
            'platform_sub_options' => 'nullable|array',
            'hashtags' => 'nullable|array',
            'hashtags.*' => 'string',
            'targeting' => 'nullable|array',
            'targeting.minFollowers' => 'nullable|integer|min:0',
            'targeting.maxFollowers' => 'nullable|integer|min:0',
            'targeting.excludeVerified' => 'nullable|boolean',
        ]);

        $agent = Agent::create([
            'store_name' => $validated['store_name'],
            'name' => $validated['name'],
            'product_name' => $validated['product_name'],
            'product_url' => $validated['product_url'] ?? null,
            'product_image' => $validated['product_image'] ?? null,
            'mode' => $validated['mode'],
            'config_type' => $validated['config_type'] ?? 'auto',
            'platforms' => $validated['platforms'] ?? [],
            'platform_sub_options' => $validated['platform_sub_options'] ?? [],
            'hashtags' => $validated['hashtags'] ?? [],
            'targeting' => $validated['targeting'] ?? [
                'minFollowers' => 500,
                'maxFollowers' => 100000,
                'excludeVerified' => true,
            ],
            'status' => 'idle',
            'is_active' => false,
        ]);

        Log::info('Agent created', [
            'id' => $agent->id,
            'name' => $agent->name,
            'store_name' => $agent->store_name,
        ]);

        return response()->json([
            'success' => true,
            'agent' => $agent->toFrontendFormat(),
        ], 201);
    }

    /**
     * Get a single agent
     * 
     * GET /api/agents/{id}
     */
    public function show(Request $request, int $id)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $agent = Agent::byStore($request->query('store_name'))->findOrFail($id);

        return response()->json([
            'success' => true,
            'agent' => $agent->toFrontendFormat(),
        ]);
    }

    /**
     * Update an agent
     * 
     * PUT /api/agents/{id}
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'store_name' => 'required|string',
            'name' => 'sometimes|string|max:255',
            'product_name' => 'sometimes|string|max:255',
            'product_url' => 'nullable|url',
            'product_image' => 'nullable|url',
            'mode' => ['sometimes', Rule::in(['b2c', 'b2b', 'both'])],
            'config_type' => ['sometimes', Rule::in(['auto', 'advanced'])],
            'platforms' => 'sometimes|array',
            'platforms.*' => Rule::in(['instagram', 'tiktok', 'twitter', 'linkedin']),
            'platform_sub_options' => 'sometimes|array',
            'hashtags' => 'sometimes|array',
            'hashtags.*' => 'string',
            'targeting' => 'sometimes|array',
        ]);

        $agent = Agent::byStore($validated['store_name'])->findOrFail($id);

        // Remove store_name from update data (it's used for scoping only)
        unset($validated['store_name']);

        $agent->update($validated);

        Log::info('Agent updated', ['id' => $agent->id, 'name' => $agent->name]);

        return response()->json([
            'success' => true,
            'agent' => $agent->fresh()->toFrontendFormat(),
        ]);
    }

    /**
     * Delete an agent
     * 
     * DELETE /api/agents/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $agent = Agent::byStore($request->input('store_name'))->findOrFail($id);
        $agent->delete();

        Log::info('Agent deleted', ['id' => $id]);

        return response()->json([
            'success' => true,
            'message' => 'Agent deleted',
        ]);
    }

    /**
     * Toggle agent active status
     * 
     * POST /api/agents/{id}/toggle
     */
    public function toggle(Request $request, int $id)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $agent = Agent::byStore($request->input('store_name'))->findOrFail($id);
        $agent->toggleActive();

        return response()->json([
            'success' => true,
            'is_active' => $agent->is_active,
        ]);
    }

    /**
     * Stop a running agent
     * 
     * POST /api/agents/{id}/stop
     */
    public function stop(Request $request, int $id)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $agent = Agent::byStore($request->input('store_name'))->findOrFail($id);

        // Only can stop if running
        if ($agent->status !== 'running') {
            return response()->json([
                'success' => false,
                'error' => 'Agent is not running',
            ], 400);
        }

        // Mark as completed (or idle)
        $agent->update([
            'status' => 'completed',
            'last_run' => now(),
        ]);

        Log::info('Agent stopped', ['id' => $agent->id, 'name' => $agent->name]);

        return response()->json([
            'success' => true,
            'status' => 'completed',
            'message' => 'Agent stopped successfully',
        ]);
    }

    /**
     * Run agent - trigger n8n workflow
     * 
     * POST /api/agents/{id}/run
     */
    public function run(Request $request, int $id)
    {
        $request->validate([
            'store_name' => 'required|string',
        ]);

        $agent = Agent::byStore($request->input('store_name'))->findOrFail($id);

        // Prevent running if already running
        if ($agent->status === 'running') {
            return response()->json([
                'success' => false,
                'error' => 'Agent is already running',
            ], 400);
        }

        $agent->markRunning();

        // Build n8n payload
        $payload = $agent->toN8nPayload();
        $payload['callback_url'] = url('/api/leads/ingest');

        // Get n8n webhook URL from config
        $webhookUrl = config('services.n8n.prospect_webhook_url');

        if (!$webhookUrl) {
            Log::warning('n8n webhook URL not configured, skipping trigger', [
                'agent_id' => $agent->id,
            ]);

            return response()->json([
                'success' => true,
                'status' => 'running',
                'message' => 'Agent marked as running (n8n webhook not configured)',
            ]);
        }

        // Fire-and-forget: Use very short timeout, we don't wait for workflow to complete
        // n8n will call back via the callback_url when done
        try {
            // Use pool to send request without blocking
            $responses = Http::pool(fn($pool) => [
                $pool->timeout(5)->connectTimeout(3)->post($webhookUrl, $payload),
            ]);

            // Don't check response - it will timeout but request is sent
            Log::info('Agent run triggered', [
                'agent_id' => $agent->id,
                'webhook_url' => $webhookUrl,
            ]);

            return response()->json([
                'success' => true,
                'status' => 'running',
                'message' => 'Workflow triggered successfully',
            ]);
        } catch (\Exception $e) {
            // Even if request times out, the workflow may still be running
            Log::info('Agent trigger sent (request may have timed out but workflow could still be running)', [
                'agent_id' => $agent->id,
                'message' => $e->getMessage(),
            ]);

            // Don't mark as error - the workflow is likely still running
            return response()->json([
                'success' => true,
                'status' => 'running',
                'message' => 'Workflow triggered',
            ]);
        }
    }

    /**
     * Handle completion webhook from n8n
     * 
     * POST /api/webhooks/agent-completed
     */
    public function handleCompletion(Request $request)
    {
        $validated = $request->validate([
            'agent_id' => 'required|exists:agents,id',
            'success' => 'required|boolean',
            'prospects_found' => 'nullable|integer|min:0',
            'error' => 'nullable|string',
        ]);

        $agent = Agent::findOrFail($validated['agent_id']);

        if ($validated['success']) {
            $agent->markCompleted($validated['prospects_found'] ?? 0);

            Log::info('Agent completed successfully', [
                'agent_id' => $agent->id,
                'prospects_found' => $validated['prospects_found'] ?? 0,
            ]);
        } else {
            $agent->markError($validated['error'] ?? 'Unknown error');

            Log::error('Agent completed with error', [
                'agent_id' => $agent->id,
                'error' => $validated['error'],
            ]);
        }

        return response()->json(['success' => true]);
    }
}
