<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Observers;

use Illuminate\Support\Facades\DB;
use Modules\BitesMiddleware\Events\WorkspaceCreated;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;
use Modules\BitesMiddleware\Services\SnsService;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class WorkspaceObserver
{
    use UseMiddlewareDBTrait;

    /**
     * Track workspaces being processed to prevent duplicate saves within same request.
     */
    protected static array $processingWorkspaces = [];

    public function saving($workspace)
    {
        // Generate a unique key for this workspace (by slug or temp identifier)
        $workspaceKey = $workspace->slug ?? $workspace->name ?? spl_object_id($workspace);

        // Prevent duplicate processing within the same request
        if (isset(self::$processingWorkspaces[$workspaceKey])) {
            // Already processed, just set the id and return false to cancel save
            $workspace->id = self::$processingWorkspaces[$workspaceKey];
            return;
        }

        // Mark as processing
        self::$processingWorkspaces[$workspaceKey] = true;
        $map = config('bites.WORKSPACE.WORKSPACE_COLUMN_MAP');

        // Build the mapper first to get the slug
        $generateMapper = [];
        foreach ($map as $masterDBKey => $targetDBKey) {
            if (is_callable($targetDBKey)) {
                $generateMapper[$masterDBKey] = $targetDBKey($workspace);
            } elseif (is_array($targetDBKey)) {
                $generateMapper[$masterDBKey] = $workspace->{$targetDBKey['key']} = $workspace->{$targetDBKey['key']} ?? $targetDBKey['default'];
            } else {
                $targetDBKey = explode(',', $targetDBKey);
                if (count($targetDBKey) > 1) {
                    $generateMapper[$masterDBKey] = $workspace->{$targetDBKey[0]} = $workspace->{$targetDBKey[0]} ?? $targetDBKey[1];
                } else {
                    $generateMapper[$masterDBKey] = $workspace->{$targetDBKey[0]};
                }
            }
        }

        // Keep the master slug stable per owner, but do it without opening
        // long-lived transactions across the local and master databases.
        if (!empty($generateMapper['slug']) && !$workspace->exists) {
            $ownerId = $generateMapper['b_user_id'] ?? auth()->id() ?? $workspace->created_by ?? null;
            if (!empty($ownerId)) {
                $generateMapper['slug'] .= '-' . $ownerId;
            }
            $workspace->slug = $generateMapper['slug'];
        }

        $dbWorkspace = $this->syncMasterWorkspace($generateMapper);

        // CRITICAL: Sync the ID from master DB to local workspace
        // This ensures all projects use the same workspace ID
        if ($workspace && $dbWorkspace) {
            $workspace->id = $dbWorkspace->id;
        }

        // Store the workspace id to prevent duplicate processing
        self::$processingWorkspaces[$workspaceKey] = $dbWorkspace->id;

        // Publish to SNS if enabled
        if (config('bites.SNS_ENABLED', false)) {
            /** @var SnsService $snsService */
            $snsService = app()->make(SnsService::class);
            $snsService->publish(['type' => 'Workspace', 'workspace' => $dbWorkspace->toArray()]);
        }
    }

    public function saved($workspace)
    {
        event(new WorkspaceCreated($workspace));
    }

    private function syncMasterWorkspace(array $generateMapper): WorkspaceMasterDB
    {
        $connection = DB::connection('MiddlewareDB');
        $lockName = $this->workspaceLockName($generateMapper);
        $lockAcquired = false;

        if ($lockName !== null) {
            $lockRow = $connection->selectOne('SELECT GET_LOCK(?, 10) AS workspace_lock', [$lockName]);
            $lockAcquired = (int) ($lockRow->workspace_lock ?? 0) === 1;

            if (!$lockAcquired) {
                throw new \RuntimeException('Unable to acquire workspace sync lock.');
            }
        }

        try {
            $dbWorkspace = null;

            if (!empty($generateMapper['slug'])) {
                $dbWorkspace = WorkspaceMasterDB::query()
                    ->where('slug', $generateMapper['slug'])
                    ->first();
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

    private function workspaceLockName(array $generateMapper): ?string
    {
        $slug = $generateMapper['slug'] ?? null;
        if (empty($slug)) {
            return null;
        }

        return 'workspace_sync:' . $slug;
    }
}
