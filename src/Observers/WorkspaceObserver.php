<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Observers;

use Junges\Kafka\Facades\Kafka;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class WorkspaceObserver
{
    use UseMiddlewareDBTrait;

    public function saving($workspace)
    {
        $map = config('bites.WORKSPACE.WORKSPACE_COLUMN_MAP');
        $dbWorkspace = WorkspaceMasterDB::query()->where('id', $workspace->id)->first();
        $generateMapper = [];
        foreach ($map as $masterDBKey => $targetDBKey) {
            if(is_callable($targetDBKey)){
                $generateMapper[$masterDBKey] = $targetDBKey($workspace);
            }
            else if (is_array($targetDBKey)) {
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
        if ($dbWorkspace) {
            $dbWorkspace->update($generateMapper);
        } else {
            $dbWorkspace = WorkspaceMasterDB::query()->create($generateMapper);
        }

        $workspace->id = $dbWorkspace->id;
        //Kafka::publishOn('topic')
        //    ->withBodyKey(
        //                            'key', ['property-value'
        //                        ])->send();
        //foreach ($map as $key => $value) {
        //    dd($workspace->$key);
        //}
    }

}
