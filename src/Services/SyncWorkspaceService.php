<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

use Modules\BitesMiddleware\Events\WorkspaceCreated;
use Modules\BitesMiddleware\Models\Workspace;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;

class SyncWorkspaceService
{
    public function sync()
    {
        if (empty(request()->header('ACTIVE-WORKSPACE'))) {
            throw new \RuntimeException('You have to pass workspace');
        }
        $workspaceId = request()->header('ACTIVE-WORKSPACE');
        $class = config('bites.WORKSPACE.MAIN_WORKSPACE_CLASS', Workspace::class);
        $masterClass = config('bites.WORKSPACE.MASTER_WORKSPACE_CLASS', WorkspaceMasterDB::class);
        $workspace = $masterClass::find($workspaceId);
        if (empty($workspace)) {
            throw new \RuntimeException('Workspace not exist');
        }

        if (empty($class::find($workspaceId))) {
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

            $generateMapper = array_merge($generateMapper, ['id' => $workspaceId]);
            $class = new $class();

            foreach ($generateMapper as $key => $value) {
                $class->$key = $value;
            }

            $class->saveQuietly();
            event(new WorkspaceCreated($class->refresh()));
        }
    }
}
