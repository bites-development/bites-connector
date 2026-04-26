<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\BitesMiddleware\Services\WorkspaceBootstrapService;
use Modules\BitesMiddleware\Services\WorkspaceIdentityService;

class ResolveWorkspaceContext
{
    public function handle(Request $request, Closure $next)
    {
        $workspaceIdentity = app(WorkspaceIdentityService::class);
        $publicWorkspaceId = $workspaceIdentity->getRequestedWorkspaceId($request);

        if (!empty($publicWorkspaceId)) {
            app(WorkspaceBootstrapService::class)->ensureRequestedWorkspaceContext($request);
        }

        return $next($request);
    }
}
