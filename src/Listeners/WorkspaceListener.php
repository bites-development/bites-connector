<?php

namespace Modules\BitesMiddleware\Listeners;

use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\DTOs\SnsNotificationDTO;
use Modules\BitesMiddleware\Events\WorkspaceCreated;
use Modules\BitesMiddleware\Models\Workspace;
use Modules\BitesMiddleware\Models\WorkspaceMapping;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;

class WorkspaceListener
{
    public function __construct()
    {
    }

    public function handle($event)
    {
        if (!$this->shouldConsumeWorkspaceSync()) {
            return;
        }

        $message = $event->workspace;
        $dto = SnsNotificationDTO::fromArray($message);
        if ($dto->message['type'] !== 'Workspace') {
            return;
        }

        $class = config('bites.WORKSPACE.MAIN_WORKSPACE_CLASS', Workspace::class);
        $masterClass = config('bites.WORKSPACE.MASTER_WORKSPACE_CLASS', WorkspaceMasterDB::class);
        $workspaceId = $dto->message['workspace']['id'];
        $workspace = $masterClass::find($workspaceId);
        if (empty($workspace)) {
            throw new \RuntimeException('Workspace not exist');
        }

        $localWorkspace = $this->resolveLocalWorkspace($class, $workspaceId);
        if (!$localWorkspace) {
            $localWorkspace = new $class();
        }

        $map = config('bites.WORKSPACE.TARGET_WORKSPACE_COLUMN_MAP', []);
        $generateMapper = [];
        foreach ($map as $targetDBKey => $masterDBKey) {
            $generateMapper[$targetDBKey] = $this->resolveMappedValue($workspace, $masterDBKey);
        }

        foreach ($generateMapper as $key => $value) {
            $localWorkspace->$key = $value;
        }

        if ($this->hasMiddlewareWorkspaceIdColumn($localWorkspace)) {
            $localWorkspace->middleware_workspace_id = $workspaceId;
        }

        $localWorkspace->saveQuietly();

        $this->syncWorkspaceMapping($localWorkspace, $workspaceId);

        event(new WorkspaceCreated($localWorkspace->refresh()));
    }

    private function resolveMappedValue($workspace, mixed $masterDBKey): mixed
    {
        if (is_callable($masterDBKey)) {
            return $masterDBKey($workspace);
        }

        if (is_array($masterDBKey)) {
            $key = $masterDBKey['key'] ?? null;
            $default = $masterDBKey['default'] ?? null;

            return $key === null ? $default : (data_get($workspace, $key) ?? $default);
        }

        $parts = explode(',', (string) $masterDBKey);
        $key = $parts[0] ?? null;
        $default = $parts[1] ?? null;

        return $key === null ? $default : (data_get($workspace, $key) ?? $default);
    }

    private function resolveLocalWorkspace(string $class, mixed $workspaceId)
    {
        $appPrefix = $this->normalizeAppPrefix(config('bites.app_prefix', env('BITES_APP_PREFIX', '')));

        if ($appPrefix !== null) {
            $mapping = WorkspaceMapping::query()
                ->where('workspace_id', $workspaceId)
                ->where('app_prefix', $appPrefix)
                ->first();

            if (!empty($mapping?->external_workspace_id)) {
                $mappedWorkspace = $class::find($mapping->external_workspace_id);
                if ($mappedWorkspace) {
                    return $mappedWorkspace;
                }
            }
        }

        $instance = new $class();
        if ($this->hasMiddlewareWorkspaceIdColumn($instance)) {
            $mappedWorkspace = $class::where('middleware_workspace_id', $workspaceId)->first();
            if ($mappedWorkspace) {
                return $mappedWorkspace;
            }
        }

        return $class::find($workspaceId);
    }

    private function syncWorkspaceMapping($localWorkspace, mixed $workspaceId): void
    {
        $appPrefix = $this->normalizeAppPrefix(config('bites.app_prefix', env('BITES_APP_PREFIX', '')));
        if ($appPrefix === null || empty($localWorkspace->id)) {
            return;
        }

        WorkspaceMapping::query()->updateOrCreate(
            [
                'app_prefix' => $appPrefix,
                'external_workspace_id' => (string) $localWorkspace->id,
            ],
            [
                'workspace_id' => $workspaceId,
                'external_slug' => $localWorkspace->slug,
            ]
        );
    }

    private function hasMiddlewareWorkspaceIdColumn($workspace): bool
    {
        return Schema::hasColumn($workspace->getTable(), 'middleware_workspace_id');
    }

    private function normalizeAppPrefix(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function shouldConsumeWorkspaceSync(): bool
    {
        return strtolower(trim((string) config('bites.workspace_sync_mode', 'mirror-db'))) === 'mirror-sns';
    }
}
