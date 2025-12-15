<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Observers;

use Modules\BitesMiddleware\Services\WorkspaceAccessService;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class DynamicWorkspaceObserver
{
    use UseMiddlewareDBTrait;

    protected WorkspaceAccessService $workspaceAccess;

    public function __construct()
    {
        $this->workspaceAccess = app(WorkspaceAccessService::class);
    }

    public function saving($item)
    {
        if ($this->workspaceAccess->shouldIgnoreWorkspace()) {
            return;
        }

        if (empty(request()->header('ACTIVE-WORKSPACE')) && empty($item->workspace_id)) {
            throw new \RuntimeException('You have to pass workspace');
        }
        $workspaceId = request()->header('ACTIVE-WORKSPACE', $item->workspace_id);
        $item->workspace_id = $workspaceId;
    }

    public function saved($item)
    {
        if ($this->workspaceAccess->shouldIgnoreWorkspace()) {
            return;
        }

        $item->workspaceMaster()->delete();
        $item->workspaceMaster()->create(['workspace_id' => $item->workspace_id]);
    }
}
