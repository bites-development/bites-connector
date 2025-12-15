<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

class WorkspaceAccessService
{
    /**
     * Check if workspace filtering should be ignored for this request.
     * Based on configured routes and user roles.
     */
    public function shouldIgnoreWorkspace(): bool
    {
        $ignoreRoutes = config('bites.IGNORE_WORKSPACE_ROUTES', []);
        if (!empty($ignoreRoutes) && request()->is($ignoreRoutes)) {
            return true;
        }

        $user = auth()->user();
        if (!$user) {
            return false;
        }

        $ignoreRoles = config('bites.IGNORE_WORKSPACE_ROLES', []);
        if (empty($ignoreRoles)) {
            return false;
        }
        
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole($ignoreRoles);
        }

        if (isset($user->role) && in_array($user->role, $ignoreRoles)) {
            return true;
        }

        return false;
    }

    /**
     * Get the current active workspace ID from request header.
     */
    public function getActiveWorkspaceId(): int
    {
        return (int) request()->header('ACTIVE-WORKSPACE', 0);
    }

    /**
     * Get the current authenticated user ID.
     */
    public function getCurrentUserId(): int
    {
        return auth()->user()?->id ?? 0;
    }
}
