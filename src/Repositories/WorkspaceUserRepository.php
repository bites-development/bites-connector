<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\BitesMiddleware\Models\WorkspaceUser;

class WorkspaceUserRepository
{
    public function __construct(private WorkspaceUser $model)
    {
    }

    public function inviteWorkspaceUser($workspaceId, $userId): Model|Builder
    {
       return $this->model->newQuery()->updateOrCreate(
            ['workspace_id' => $workspaceId, 'b_user_id' => $userId],
            ['workspace_id' => $workspaceId, 'b_user_id' => $userId]
        );
    }
}
