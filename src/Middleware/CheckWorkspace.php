<?php

namespace Modules\BitesMiddleware\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\BitesMiddleware\Services\SyncWorkspaceService;

class CheckWorkspace
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure                 $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $syncWorkspace = app()->make(SyncWorkspaceService::class);
        $syncWorkspace->sync();
        return $next($request);
    }
}
