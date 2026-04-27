<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

use Illuminate\Support\Facades\Log;
use Modules\BitesMiddleware\Events\WorkspaceCreated;
use Modules\BitesMiddleware\Models\Workspace;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;

class SyncWorkspaceService
{
    public function sync()
    {
        $request = request();
        $workspaceIdentity = app(WorkspaceIdentityService::class);
        $workspaceId = $workspaceIdentity->getRequestedWorkspaceId($request);

        if (empty($workspaceId)) {
            return;
        }

        $class = config('bites.WORKSPACE.MAIN_WORKSPACE_CLASS', Workspace::class);

        if (!empty($workspaceIdentity->findByPublicId($workspaceId, $class))) {
            return;
        }

        // Only the public source app should skip auto-projection on its
        // workspace listing endpoint. Mirror apps need the projection even
        // when their first touched route is a workspace endpoint.
        if ($workspaceIdentity->usesLocalPublicIds() && $request->is('api/workspaces')) {
            return;
        }

        $canonicalWorkspaceId = $workspaceIdentity->resolveCanonicalWorkspaceId($workspaceId);
        if (empty($canonicalWorkspaceId)) {
            return;
        }

        $masterClass = config('bites.WORKSPACE.MASTER_WORKSPACE_CLASS', WorkspaceMasterDB::class);

        try {
            $workspace = $masterClass::find($canonicalWorkspaceId);
        } catch (\Throwable $exception) {
            Log::warning('Skipping middleware workspace sync because master lookup failed.', [
                'workspace_id' => $workspaceId,
                'canonical_workspace_id' => $canonicalWorkspaceId,
                'path' => $request->path(),
                'error' => $exception->getMessage(),
            ]);
            return;
        }

        if (empty($workspace)) {
            return;
        }

        $map = config('bites.WORKSPACE.TARGET_WORKSPACE_COLUMN_MAP', []);
        $generateMapper = [];
        foreach ($map as $targetDBKey => $masterDBKey) {
            if (is_callable($masterDBKey)) {
                $generateMapper[$targetDBKey] = $masterDBKey($workspace);
            } elseif (is_array($masterDBKey)) {
                $generateMapper[$targetDBKey] = $workspace->{$masterDBKey['key']} = $workspace->{$masterDBKey['key']} ?? $masterDBKey['default'];
            } else {
                $masterDBKey = explode(',', $masterDBKey);
                if (count($masterDBKey) > 1) {
                    $generateMapper[$targetDBKey] = $workspace->{$masterDBKey[0]} = $workspace->{$masterDBKey[0]} ?? $masterDBKey[1];
                } else {
                    $generateMapper[$targetDBKey] = $workspace->{$masterDBKey[0]};
                }
            }
        }

        $class = new $class();
        $usesMiddlewareWorkspaceId = $workspaceIdentity->supportsMiddlewareWorkspaceId($class);

        foreach ($generateMapper as $key => $value) {
            if ($usesMiddlewareWorkspaceId && $key === 'id') {
                continue;
            }

            $class->$key = $value;
        }

        if ($usesMiddlewareWorkspaceId) {
            unset($class->id);
            $class->middleware_workspace_id = $canonicalWorkspaceId;
        } else {
            $class->id = $canonicalWorkspaceId;
        }

        $class->saveQuietly();
        event(new WorkspaceCreated($class->refresh()));
    }
}
