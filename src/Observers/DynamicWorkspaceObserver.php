<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Observers;

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

        if (empty(WorkspaceMasterDB::find($workspaceId))) {
            throw new \RuntimeException('Workspace not exist');
        }
        $item->workspace_id = $workspaceId;
    }

    public function saved($item)
    {
        $item->workspaceMaster()->delete();
        $item->workspaceMaster()->create(['workspace_id' => $item->workspace_id]);
    }

}
