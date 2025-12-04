<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Observers;

use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class DynamicWorkspaceObserver
{
    use UseMiddlewareDBTrait;

    public function saving($item)
    {
        if ($this->shouldIgnoreWorkspace()) {
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
        if ($this->shouldIgnoreWorkspace()) {
            return;
        }

        $item->workspaceMaster()->delete();
        $item->workspaceMaster()->create(['workspace_id' => $item->workspace_id]);
    }

    protected function shouldIgnoreWorkspace(): bool
    {
        $ignoreRoutes = config('bites.IGNORE_WORKSPACE_ROUTES', []);
        if (request()->is($ignoreRoutes)) {
            return true;
        }

        $user = auth()->user();
        if (!$user) {
            return false;
        }

        $ignoreRoles = config('bites.IGNORE_WORKSPACE_ROLES', []);
        
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole($ignoreRoles);
        }

        if (isset($user->role) && in_array($user->role, $ignoreRoles)) {
            return true;
        }

        return false;
    }

}
