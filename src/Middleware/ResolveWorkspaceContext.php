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
        if (!class_exists(WorkspaceIdentityService::class) || !class_exists(WorkspaceBootstrapService::class)) {
            return $next($request);
        }

        try {
            $workspaceIdentity = app()->make(WorkspaceIdentityService::class);
            $publicWorkspaceId = $workspaceIdentity->getRequestedWorkspaceId($request);
        } catch (\Throwable) {
            return $next($request);
        }

        if (!empty($publicWorkspaceId)) {
            try {
                app()->make(WorkspaceBootstrapService::class)->ensureRequestedWorkspaceContext($request);
            } catch (\Throwable) {
                return $next($request);
            }
        }

        return $next($request);
    }
}
