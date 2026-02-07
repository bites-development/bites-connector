<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PlanWebhookController extends Controller
{
    public function handlePlanChanged(Request $request): JsonResponse
    {
        if (!config('bites.plan_webhooks.enabled', true)) {
            return response()->json([
                'success' => false,
                'message' => 'Plan webhooks disabled',
            ], 403);
        }

        $validated = $request->validate([
            'event' => 'required|string',
            'action' => 'required|in:created,updated,deleted',
            'plan' => 'required|array',
            'plan.id' => 'nullable',
            'plan.slug' => 'nullable|string',
            'plan.name' => 'nullable|string',
            'plan.metadata' => 'nullable|array',
            'timestamp' => 'nullable|date',
            'topic' => 'nullable|string',
        ]);

        $payload = [
            'event' => $validated['event'],
            'action' => $validated['action'],
            'plan' => $validated['plan'],
            'timestamp' => $validated['timestamp'] ?? now()->toIso8601String(),
            'topic' => $validated['topic'] ?? null,
        ];

        $this->persistPlan($payload);

        event('plan.changed', [$payload]);

        Log::info('Plan webhook received', [
            'action' => $payload['action'],
            'plan' => $payload['plan']['slug'] ?? $payload['plan']['id'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Plan webhook processed',
        ]);
    }

    protected function persistPlan(array $payload): void
    {
        $planModel = config('bites.plan_webhooks.plan_model');
        if (!$planModel || !class_exists($planModel)) {
            return;
        }

        $planData = $payload['plan'];
        $slugColumn = config('bites.plan_webhooks.slug_column', 'slug');
        $nameColumn = config('bites.plan_webhooks.name_column', 'name');
        $metadataColumn = config('bites.plan_webhooks.metadata_column', 'metadata');
        $statusColumn = config('bites.plan_webhooks.status_column', 'active');

        $identifier = $planData[$slugColumn] ?? $planData['slug'] ?? $planData['id'] ?? null;
        if (!$identifier) {
            return;
        }

        $attributes = [];
        if ($nameColumn && array_key_exists('name', $planData)) {
            $attributes[$nameColumn] = $planData['name'];
        }
        if ($metadataColumn && array_key_exists('metadata', $planData)) {
            $attributes[$metadataColumn] = $planData['metadata'];
        }
        if ($statusColumn) {
            $attributes[$statusColumn] = $payload['action'] === 'deleted' ? false : true;
        }

        try {
            $planModel::updateOrCreate(
                [$slugColumn => $identifier],
                $attributes
            );
        } catch (\Throwable $e) {
            Log::warning('Plan webhook persistence failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
