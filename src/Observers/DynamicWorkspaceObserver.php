<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Observers;

use Illuminate\Support\Facades\Log;
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

        $keyName = method_exists($item, 'getKeyName') ? $item->getKeyName() : 'id';
        $modelId = method_exists($item, 'getKey') ? $item->getKey() : null;

        if (empty($modelId) && isset($item->{$keyName})) {
            $modelId = $item->{$keyName};
        }

        if (empty($modelId) && method_exists($item, 'getOriginal')) {
            $modelId = $item->getOriginal($keyName);
        }

        if (empty($modelId)) {
            Log::warning('Skipping workspace model sync: model key is empty after save', [
                'model_type' => get_class($item),
                'key_name' => $keyName,
                'workspace_id' => $item->workspace_id ?? null,
            ]);
            return;
        }

        $workspaceMasterModel = $item->workspaceMaster()->getRelated();

        $workspaceMasterModel->newQuery()->updateOrCreate(
            [
                'model_id' => $modelId,
                'model_type' => method_exists($item, 'getMorphClass') ? $item->getMorphClass() : get_class($item),
            ],
            [
                'workspace_id' => $item->workspace_id,
            ]
        );
    }
}
