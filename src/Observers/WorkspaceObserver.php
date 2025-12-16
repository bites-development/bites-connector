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
        // Start transactions on both connections
        DB::beginTransaction();
        DB::connection('MiddlewareDB')->beginTransaction();

        try {
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


            // Ensure slug is unique in master DB
            if (!empty($generateMapper['slug']) && !$workspace->exists) {
                $generateMapper['slug'] = ($generateMapper['slug'].'-'.($generateMapper['b_user_id'] ?? auth()->user()->id));
                $workspace->slug = $generateMapper['slug'];
            }

            $dbWorkspace = WorkspaceMasterDB::where('slug',$generateMapper['slug'])->first();
            if ($dbWorkspace) {
                $dbWorkspace->update($generateMapper);
            } else {
                $dbWorkspace = WorkspaceMasterDB::query()->firstOrCreate($generateMapper);
            }

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

            // Commit both transactions
            DB::connection('MiddlewareDB')->commit();
            DB::commit();
        } catch (\Exception $e) {
            // Rollback both transactions
            DB::connection('MiddlewareDB')->rollBack();
            DB::rollBack();
            throw $e;
        }
    }

    public function saved($workspace)
    {
        event(new WorkspaceCreated($workspace));
    }
}
