<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlanWebhookService
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('bites.plan_webhooks', []);
    }

    public function notifyPlanChange(string $action, array $plan, array $context = []): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'apps_notified' => 0, 'message' => 'Plan webhooks disabled'];
        }

        $apps = $this->resolveApps();

        if ($apps->isEmpty()) {
            Log::warning('PlanWebhookService: No subscribed apps found');
            return ['success' => false, 'apps_notified' => 0, 'results' => []];
        }

        $payload = $this->buildPayload($action, $plan, $context);
        $results = [];

        foreach ($apps as $app) {
            $results[] = $this->deliverToApp($app, $payload);
        }

        $successCount = collect($results)->where('success', true)->count();

        return [
            'success' => $successCount === count($results),
            'apps_notified' => $successCount,
            'results' => $results,
        ];
    }

    public function notifyApp($appIdentifier, string $action, array $plan, array $context = []): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'Plan webhooks disabled'];
        }

        $app = $this->findApp($appIdentifier);
        if (!$app) {
            return ['success' => false, 'message' => 'App not found'];
        }

        $payload = $this->buildPayload($action, $plan, $context);
        $result = $this->deliverToApp($app, $payload);

        return [
            'success' => $result['success'],
            'apps_notified' => $result['success'] ? 1 : 0,
            'results' => [$result],
        ];
    }

    public function notifyTopic(string $topic, string $action, array $plan, array $context = []): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'message' => 'Plan webhooks disabled'];
        }

        $topic = trim($topic);
        $apps = $this->resolveApps(function (Builder $query) use ($topic) {
            $column = $this->config['topic_column'] ?? 'prefix';
            if ($column) {
                $query->where($column, $topic);
            }
        });

        if ($apps->isEmpty()) {
            Log::info('PlanWebhookService: No apps matched topic', ['topic' => $topic]);
            return ['success' => false, 'apps_notified' => 0, 'results' => []];
        }

        $payload = $this->buildPayload($action, $plan, $context + ['topic' => $topic]);
        $results = [];

        foreach ($apps as $app) {
            $results[] = $this->deliverToApp($app, $payload, ['topic' => $topic]);
        }

        $successCount = collect($results)->where('success', true)->count();

        return [
            'success' => $successCount === count($results),
            'apps_notified' => $successCount,
            'results' => $results,
        ];
    }

    protected function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    protected function resolveApps(?callable $callback = null): Collection
    {
        $modelClass = $this->config['app_model'] ?? null;
        if (!$modelClass || !class_exists($modelClass)) {
            Log::warning('PlanWebhookService: app_model missing or unavailable');
            return collect();
        }

        /** @var Builder $query */
        $query = $modelClass::query();

        if ($this->hasColumn($modelClass, 'is_subscribed')) {
            $query->where('is_subscribed', true);
        }

        if ($callback) {
            $callback($query);
        }

        return $query->get();
    }

    protected function findApp($identifier)
    {
        $modelClass = $this->config['app_model'] ?? null;
        if (!$modelClass || !class_exists($modelClass)) {
            return null;
        }

        /** @var Builder $query */
        $query = $modelClass::query();

        if (is_numeric($identifier)) {
            return $query->where('id', (int) $identifier)->first();
        }

        return $query
            ->where('name', $identifier)
            ->orWhere('prefix', $identifier)
            ->first();
    }

    protected function buildPayload(string $action, array $plan, array $context = []): array
    {
        return [
            'event' => $context['event'] ?? 'plan.changed',
            'action' => $action,
            'plan' => $plan,
            'timestamp' => $context['timestamp'] ?? now()->toIso8601String(),
            'topic' => $context['topic'] ?? null,
        ];
    }

    protected function deliverToApp($app, array $payload, array $options = []): array
    {
        $endpoint = $this->determineEndpoint($app, $options['endpoint'] ?? null);

        if (!$endpoint) {
            return [
                'app' => $this->summarizeApp($app),
                'success' => false,
                'message' => 'Webhook URL missing',
            ];
        }

        try {
            $response = Http::timeout($this->config['timeout'] ?? 10)
                ->acceptJson()
                ->withHeaders($this->buildHeaders($app))
                ->post($endpoint, $payload);

            $success = $response->successful();

            if (!$success) {
                Log::warning('PlanWebhookService: Delivery failed', [
                    'app' => $this->summarizeApp($app),
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return [
                'app' => $this->summarizeApp($app, $endpoint),
                'success' => $success,
                'status' => $response->status(),
                'response' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('PlanWebhookService: Exception while delivering', [
                'app' => $this->summarizeApp($app, $endpoint),
                'error' => $e->getMessage(),
            ]);

            return [
                'app' => $this->summarizeApp($app, $endpoint),
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function determineEndpoint($app, ?string $override = null): ?string
    {
        if ($override) {
            return $override;
        }

        if (!empty($app->webhook_url)) {
            return rtrim((string) $app->webhook_url, '/');
        }

        $baseUrl = $app->api_url ?? null;
        if (!$baseUrl) {
            return null;
        }

        $path = $this->config['webhook_path'] ?? '/api/webhooks/plan-changed';
        $path = Str::start($path, '/');

        return rtrim((string) $baseUrl, '/') . $path;
    }

    protected function buildHeaders($app): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'X-Bites-App' => $app->prefix ?? $app->name ?? 'unknown',
        ];

        if (!empty($app->access_token)) {
            $headers['Authorization'] = 'Bearer ' . $app->access_token;
        }

        return $headers;
    }

    protected function summarizeApp($app, ?string $endpoint = null): array
    {
        return [
            'id' => $app->id ?? null,
            'name' => $app->name ?? null,
            'prefix' => $app->prefix ?? null,
            'endpoint' => $endpoint ?? $this->determineEndpoint($app),
        ];
    }

    protected function hasColumn(string $modelClass, string $column): bool
    {
        try {
            $instance = new $modelClass();
            $table = $instance->getTable();
            return Schema::hasColumn($table, $column);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
