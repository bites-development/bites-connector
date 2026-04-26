<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Observers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Events\WorkspaceCreated;
use Modules\BitesMiddleware\Models\WorkspaceMapping;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;
use Modules\BitesMiddleware\Services\SnsService;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class WorkspaceObserver
{
    use UseMiddlewareDBTrait;

    protected static array $processingWorkspaces = [];
    protected static array $middlewareWorkspaceColumnCache = [];

    public function saving($workspace)
    {
        // Intentionally left blank. We sync to middleware after the local
        // workspace has a stable primary key so we can persist an app mapping.
    }

    public function saved($workspace)
    {
        if (DB::transactionLevel() > 0) {
            $workspaceClass = $workspace::class;
            $workspaceKey = $workspace->getKey();

            DB::afterCommit(function () use ($workspace, $workspaceClass, $workspaceKey) {
                $freshWorkspace = $workspaceKey ? $workspaceClass::query()->find($workspaceKey) : null;
                $this->sync($freshWorkspace ?? $workspace);
            });

            return;
        }

        $this->sync($workspace);
    }

    public function sync($workspace)
    {
        if (!$this->shouldPublishWorkspaceSync()) {
            return;
        }

        $workspaceKey = (string) ($workspace->getKey() ?: ($workspace->slug ?? $workspace->name ?? spl_object_id($workspace)));

        if (isset(self::$processingWorkspaces[$workspaceKey])) {
            return;
        }

        self::$processingWorkspaces[$workspaceKey] = true;

        try {
            $generateMapper = $this->buildWorkspaceMapper($workspace);
            $dbWorkspace = $this->syncMasterWorkspace($workspace, $generateMapper);

            $this->syncWorkspaceMapping($workspace, $dbWorkspace);
            $this->syncLocalMiddlewareWorkspaceId($workspace, $dbWorkspace);

            if (config('bites.SNS_ENABLED', false)) {
                /** @var SnsService $snsService */
                $snsService = app()->make(SnsService::class);
                $snsService->publish(['type' => 'Workspace', 'workspace' => $dbWorkspace->toArray()]);
            }

            event(new WorkspaceCreated($workspace->fresh()));
        } finally {
            unset(self::$processingWorkspaces[$workspaceKey]);
        }
    }

    private function buildWorkspaceMapper($workspace): array
    {
        $map = config('bites.WORKSPACE.WORKSPACE_COLUMN_MAP', []);
        $generateMapper = [];

        foreach ($map as $masterDBKey => $targetDBKey) {
            $generateMapper[$masterDBKey] = $this->resolveMappedValue($workspace, $targetDBKey);
        }

        if (!empty($generateMapper['slug'])) {
            $ownerId = $generateMapper['b_user_id'] ?? auth()->id() ?? $workspace->created_by ?? null;
            if (!empty($ownerId)) {
                $generateMapper['slug'] .= '-' . $ownerId;
            }
        }

        return $generateMapper;
    }

    private function resolveMappedValue($workspace, mixed $targetDBKey): mixed
    {
        if (is_callable($targetDBKey)) {
            return $targetDBKey($workspace);
        }

        if (is_string($targetDBKey) && function_exists($targetDBKey)) {
            return $targetDBKey($workspace);
        }

        if (is_array($targetDBKey)) {
            $key = $targetDBKey['key'] ?? null;
            $default = $targetDBKey['default'] ?? null;

            return $key === null ? $default : (data_get($workspace, $key) ?? $default);
        }

        $parts = explode(',', (string) $targetDBKey);
        $key = $parts[0] ?? null;
        $default = $parts[1] ?? null;

        return $key === null ? $default : (data_get($workspace, $key) ?? $default);
    }

    private function syncMasterWorkspace($workspace, array $generateMapper): WorkspaceMasterDB
    {
        $existingWorkspace = $this->resolveExistingMasterWorkspace($workspace, $generateMapper);
        $connection = DB::connection('MiddlewareDB');
        $lockName = $this->workspaceLockName($generateMapper, $existingWorkspace?->id, (string) $workspace->getKey());
        $lockAcquired = false;

        if ($lockName !== null) {
            $lockRow = $connection->selectOne('SELECT GET_LOCK(?, 10) AS workspace_lock', [$lockName]);
            $lockAcquired = (int) ($lockRow->workspace_lock ?? 0) === 1;

            if (!$lockAcquired) {
                throw new \RuntimeException('Unable to acquire workspace sync lock.');
            }
        }

        try {
            $dbWorkspace = $existingWorkspace;

            if (empty($dbWorkspace) && !empty($generateMapper['slug'])) {
                $query = WorkspaceMasterDB::query()->where('slug', $generateMapper['slug']);
                if (!empty($generateMapper['b_user_id'])) {
                    $query->where('b_user_id', $generateMapper['b_user_id']);
                }
                $dbWorkspace = $query->first();
            }

            if ($dbWorkspace) {
                $dbWorkspace->fill($generateMapper);
                if ($dbWorkspace->isDirty()) {
                    $dbWorkspace->save();
                }
            } else {
                $dbWorkspace = WorkspaceMasterDB::query()->create($generateMapper);
            }

            return $dbWorkspace->refresh();
        } finally {
            if ($lockAcquired) {
                $connection->select('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        }
    }

    private function resolveExistingMasterWorkspace($workspace, array $generateMapper): ?WorkspaceMasterDB
    {
        $mapping = $this->findWorkspaceMapping($workspace);
        if (!empty($mapping?->workspace_id)) {
            $mappedWorkspace = WorkspaceMasterDB::query()->find($mapping->workspace_id);
            if ($mappedWorkspace) {
                return $mappedWorkspace;
            }
        }

        if (!empty($workspace->middleware_workspace_id)) {
            $canonicalWorkspace = WorkspaceMasterDB::query()->find($workspace->middleware_workspace_id);
            if ($canonicalWorkspace) {
                return $canonicalWorkspace;
            }
        }

        if (!empty($workspace->getKey())) {
            $legacyWorkspace = WorkspaceMasterDB::query()->find($workspace->getKey());
            if ($legacyWorkspace) {
                return $legacyWorkspace;
            }
        }

        if (empty($generateMapper['slug'])) {
            return null;
        }

        $query = WorkspaceMasterDB::query()->where('slug', $generateMapper['slug']);
        if (!empty($generateMapper['b_user_id'])) {
            $query->where('b_user_id', $generateMapper['b_user_id']);
        }

        return $query->first();
    }

    private function syncWorkspaceMapping($workspace, WorkspaceMasterDB $dbWorkspace): void
    {
        $appPrefix = $this->normalizeAppPrefix(config('bites.app_prefix', env('BITES_APP_PREFIX', '')));
        $externalWorkspaceId = $workspace->getKey();

        if ($appPrefix === null || empty($externalWorkspaceId)) {
            return;
        }

        WorkspaceMapping::query()->updateOrCreate(
            [
                'app_prefix' => $appPrefix,
                'external_workspace_id' => (string) $externalWorkspaceId,
            ],
            [
                'workspace_id' => $dbWorkspace->id,
                'external_slug' => $workspace->slug,
            ]
        );
    }

    private function syncLocalMiddlewareWorkspaceId($workspace, WorkspaceMasterDB $dbWorkspace): void
    {
        if (!$this->hasMiddlewareWorkspaceIdColumn($workspace)) {
            return;
        }

        if ((int) ($workspace->middleware_workspace_id ?? 0) === (int) $dbWorkspace->id) {
            return;
        }

        $workspace->forceFill([
            'middleware_workspace_id' => $dbWorkspace->id,
        ])->saveQuietly();
    }

    private function hasMiddlewareWorkspaceIdColumn($workspace): bool
    {
        $table = $workspace->getTable();

        if (!array_key_exists($table, self::$middlewareWorkspaceColumnCache)) {
            self::$middlewareWorkspaceColumnCache[$table] = Schema::hasColumn($table, 'middleware_workspace_id');
        }

        return self::$middlewareWorkspaceColumnCache[$table];
    }

    private function findWorkspaceMapping($workspace): ?WorkspaceMapping
    {
        $appPrefix = $this->normalizeAppPrefix(config('bites.app_prefix', env('BITES_APP_PREFIX', '')));
        $externalWorkspaceId = $workspace->getKey();

        if ($appPrefix === null || empty($externalWorkspaceId)) {
            return null;
        }

        try {
            return WorkspaceMapping::query()
                ->where('app_prefix', $appPrefix)
                ->where('external_workspace_id', (string) $externalWorkspaceId)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeAppPrefix(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function workspaceLockName(array $generateMapper, mixed $workspaceId, string $externalWorkspaceId): ?string
    {
        if (!empty($workspaceId)) {
            return 'workspace_sync:id:' . $workspaceId;
        }

        $appPrefix = $this->normalizeAppPrefix(config('bites.app_prefix', env('BITES_APP_PREFIX', '')));
        if ($appPrefix !== null && $externalWorkspaceId !== '') {
            return 'workspace_sync:map:' . $appPrefix . ':' . $externalWorkspaceId;
        }

        $slug = $generateMapper['slug'] ?? null;
        if (empty($slug)) {
            return null;
        }

        return 'workspace_sync:' . $slug;
    }

    private function shouldPublishWorkspaceSync(): bool
    {
        return strtolower(trim((string) config('bites.workspace_sync_mode', 'mirror-db'))) === 'source';
    }
}
