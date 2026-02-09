<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlanSchemaSyncService
{
    protected int $filesScanned = 0;

    public function sync(array $options = []): array
    {
        $enabled = (bool) config('bites.plan_schema_sync.enabled', true);
        if (!$enabled) {
            return [
                'success' => false,
                'message' => 'Plan schema sync is disabled',
            ];
        }

        $payload = $this->buildPayload($options);
        $dryRun = (bool) ($options['dry_run'] ?? false);

        if ($dryRun) {
            return [
                'success' => true,
                'dry_run' => true,
                'payload' => $payload,
            ];
        }

        return $this->pushPayload($payload, $options);
    }

    public function buildPayload(array $options = []): array
    {
        $tableName = (string) ($options['table_name'] ?? $this->detectPlanTable());
        $snippets = $this->collectSnippets($options);

        return [
            'app_id' => $this->resolveNullableInt(env('BITES_APP_ID')),
            'app_prefix' => (string) ($options['app_prefix'] ?? $this->resolveAppPrefix()),
            'app_name' => (string) config('app.name'),
            'table_name' => $tableName !== '' ? $tableName : null,
            'integration_profile' => (string) env('BITES_INTEGRATION_PROFILE', ''),
            'snippets' => $snippets,
            'snippet_source' => (string) ($options['snippet_source'] ?? 'connector:auto-discovery'),
            'connector' => [
                'framework' => 'laravel',
                'version' => $this->resolveConnectorVersion(),
                'app_url' => (string) config('app.url', ''),
                'generated_at' => now()->toIso8601String(),
                'files_scanned' => $this->filesScanned,
            ],
        ];
    }

    public function pushPayload(array $payload, array $options = []): array
    {
        $config = (array) config('bites.plan_schema_sync', []);
        $timeout = (int) ($config['timeout'] ?? 15);
        $token = trim((string) ($options['token'] ?? $config['token'] ?? ''));
        $endpoint = $this->resolveEndpoint((string) ($options['endpoint'] ?? ''));

        $request = Http::acceptJson()->timeout($timeout);
        if ($token !== '') {
            $request = $request->withToken($token);
        }

        $request = $request->withHeaders([
            'X-Bites-App' => (string) ($payload['app_prefix'] ?? ''),
        ]);

        $response = $request->post($endpoint, $payload);

        return [
            'success' => $response->successful(),
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'response' => $response->json(),
        ];
    }

    protected function resolveEndpoint(string $override = ''): string
    {
        if ($override !== '') {
            return $override;
        }

        $config = (array) config('bites.plan_schema_sync', []);
        $path = (string) ($config['endpoint'] ?? '/api/webhooks/plans/schema-sync');
        $path = Str::start($path, '/');

        return rtrim($this->middlewareBaseUrl(), '/') . $path;
    }

    protected function middlewareBaseUrl(): string
    {
        $server = trim((string) env('MIDDLEWARE_SERVER', 'middleware.bites.com'));
        if ($server === '') {
            $server = 'middleware.bites.com';
        }

        if (!preg_match('#^https?://#i', $server)) {
            $server = 'https://' . $server;
        }

        return rtrim($server, '/');
    }

    protected function resolveAppPrefix(): string
    {
        $prefix = trim((string) env('BITES_APP_PREFIX', ''));
        if ($prefix !== '') {
            return $prefix;
        }

        $defaults = (array) config('bites.app_prefix_defaults', []);
        $normalizedDefaults = [];
        foreach ($defaults as $key => $value) {
            $normalizedDefaults[$this->normalizeKey((string) $key)] = trim((string) $value);
        }

        $candidates = [
            (string) config('app.name', ''),
            basename((string) base_path()),
        ];

        foreach ($candidates as $candidate) {
            $normalizedKey = $this->normalizeKey($candidate);
            if ($normalizedKey !== '' && !empty($normalizedDefaults[$normalizedKey])) {
                return (string) $normalizedDefaults[$normalizedKey];
            }
        }

        $fallback = $this->normalizeKey((string) (config('app.name', '') ?: basename((string) base_path())));

        return $fallback !== '' ? $fallback : 'app';
    }

    protected function detectPlanTable(): string
    {
        $candidates = [
            'plans',
            'plan',
            'subscriptions',
            'subscription',
            'billing_plans',
            'packages',
        ];

        foreach ($candidates as $table) {
            try {
                if (Schema::hasTable($table)) {
                    return $table;
                }
            } catch (\Throwable) {
                // Keep scanning candidates.
            }
        }

        return '';
    }

    protected function collectSnippets(array $options = []): array
    {
        $config = (array) config('bites.plan_schema_sync', []);
        $maxSnippets = (int) ($options['max_snippets'] ?? $config['max_snippets'] ?? 20);
        $maxChars = (int) ($options['max_snippet_chars'] ?? $config['max_snippet_chars'] ?? 12000);

        $snippets = [];
        $this->filesScanned = 0;

        foreach ($this->candidateFiles() as $path) {
            if (count($snippets) >= $maxSnippets) {
                break;
            }

            $this->filesScanned++;
            $content = (string) @file_get_contents($path);
            if ($content === '') {
                continue;
            }

            $snippet = $this->extractRelevantSnippet($content, $maxChars);
            if ($snippet === '') {
                continue;
            }

            $key = ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
            $snippets[$key] = $snippet;
        }

        return $snippets;
    }

    protected function candidateFiles(): array
    {
        $roots = array_filter([
            base_path('app/Http/Requests'),
            base_path('app/Http/Controllers'),
            base_path('app/Livewire'),
            base_path('app/Http/Livewire'),
            base_path('app/Filament'),
            base_path('resources/views'),
        ], static fn ($path) => is_dir($path));

        $files = [];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $path = $fileInfo->getPathname();
                $name = Str::lower($path);

                $isPhp = str_ends_with($name, '.php');
                $isBlade = str_ends_with($name, '.blade.php');
                if (!$isPhp && !$isBlade) {
                    continue;
                }

                if (
                    !str_contains($name, 'plan')
                    && !str_contains($name, 'subscription')
                    && !str_contains($name, 'package')
                    && !str_contains($name, 'billing')
                ) {
                    continue;
                }

                $files[] = $path;
            }
        }

        return array_values(array_unique($files));
    }

    protected function extractRelevantSnippet(string $content, int $maxChars): string
    {
        $blocks = [];

        $patterns = [
            "/['\"][^'\"]+['\"]\\s*=>\\s*['\"][^'\"]*in:[^'\"]+['\"]/m",
            "/['\"][^'\"]+['\"]\\s*=>\\s*\\[(?:.|\\n){0,800}?Rule::in\\((?:.|\\n){0,800}?\\)\\s*\\]/m",
            '/<select[^>]*name=["\'][^"\']+["\'][\s\S]{0,8000}?<\/select>/i',
            '/Select::make\(\s*[\'"][^\'"]+[\'"]\s*\)[\s\S]{0,3000}?;/m',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $content, $matches)) {
                continue;
            }

            foreach ((array) ($matches[0] ?? []) as $match) {
                $match = trim((string) $match);
                if ($match === '') {
                    continue;
                }
                $blocks[] = $match;
            }
        }

        $blocks = array_values(array_unique($blocks));
        if (empty($blocks)) {
            return '';
        }

        $snippet = implode("\n\n", $blocks);
        if (strlen($snippet) > $maxChars) {
            $snippet = substr($snippet, 0, $maxChars);
        }

        return $snippet;
    }

    protected function resolveConnectorVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('bites-development/bites-connector')) {
                return (string) InstalledVersions::getPrettyVersion('bites-development/bites-connector');
            }
        } catch (\Throwable) {
            // Fall back to unknown when version metadata is unavailable.
        }

        return 'unknown';
    }

    protected function resolveNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    protected function normalizeKey(string $value): string
    {
        $value = Str::lower(trim($value));
        if ($value === '') {
            return '';
        }

        return (string) preg_replace('/[^a-z0-9]/', '', $value);
    }
}
