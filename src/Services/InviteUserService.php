<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

use Modules\BitesMiddleware\Repositories\WorkspaceUserRepository;

class InviteUserService
{
    public function __construct(private WorkspaceUserRepository $workspaceUserRepository)
    {
    }

    public function invite($workspaceId, $userId)
    {
        return $this->workspaceUserRepository->inviteWorkspaceUser($workspaceId, $userId);
    }
}
