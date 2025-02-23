<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Observers;

use Modules\BitesMiddleware\Models\Workspace;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class DynamicWorkspaceObserver
{
    use UseMiddlewareDBTrait;

    public function saving($item)
    {
        if (empty(request()->header('ACTIVE-WORKSPACE')) && empty($item->workspace_id)) {
            throw new \RuntimeException('You have to pass workspace');
        }
        $workspaceId = request()->header('ACTIVE-WORKSPACE', $item->workspace_id);
        $class = config('bites.WORKSPACE.MAIN_WORKSPACE_CLASS', Workspace::class);
        $masterClass = config('bites.WORKSPACE.MASTER_WORKSPACE_CLASS', WorkspaceMasterDB::class);
        $workspace = $masterClass::find($workspaceId);
        if (empty($workspace)) {
            throw new \RuntimeException('Workspace not exist');
        }

        if (empty($class::find($workspaceId))) {
            $map = config('bites.WORKSPACE.TARGET_WORKSPACE_COLUMN_MAP',[]);
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

            $generateMapper = array_merge($generateMapper,['id'=>$workspaceId]);
            $class = new $class();

            foreach ($generateMapper as $key => $value){
                $class->$key = $value;
            }

            $class->saveQuietly();
        }
        $item->workspace_id = $workspaceId;
    }

    public function saved($item)
    {
        $item->workspaceMaster()->delete();
        $item->workspaceMaster()->create(['workspace_id' => $item->workspace_id]);
    }

}
