<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Models\Workspace;
use Modules\BitesMiddleware\Models\WorkspaceMapping;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;

class WorkspaceIdentityService
{
    private array $middlewareWorkspaceColumnCache = [];
    private array $workspaceLookupCache = [];

    public function getWorkspaceModelClass(): string
    {
        return config('bites.WORKSPACE.MAIN_WORKSPACE_CLASS', Workspace::class);
    }

    public function getRequestedWorkspaceId(?Request $request = null): mixed
    {
        $request ??= request();

        return $request->attributes->get('workspace_public_id')
            ?? $request->header('active-workspace')
            ?? $request->header('active_workspace')
            ?? $request->header('ACTIVE-WORKSPACE');
    }

    public function findByPublicId(mixed $workspaceId, ?string $workspaceClass = null): ?Model
    {
        if ($workspaceId === null || $workspaceId === '') {
            return null;
        }

        $workspaceClass ??= $this->getWorkspaceModelClass();
        $cacheKey = $workspaceClass . ':' . (string) $workspaceId;

        if (array_key_exists($cacheKey, $this->workspaceLookupCache)) {
            return $this->workspaceLookupCache[$cacheKey];
        }

        if ($this->usesLocalPublicIds()) {
            $workspace = $workspaceClass::query()->find($workspaceId);
            if ($workspace) {
                return $this->workspaceLookupCache[$cacheKey] = $workspace;
            }
        }

        if ($this->supportsMiddlewareWorkspaceId($workspaceClass)) {
            $mappedWorkspace = $workspaceClass::query()
                ->where('middleware_workspace_id', $workspaceId)
                ->first();

            if ($mappedWorkspace) {
                return $this->workspaceLookupCache[$cacheKey] = $mappedWorkspace;
            }
        }

        $canonicalWorkspaceId = $this->resolveCanonicalWorkspaceId($workspaceId);
        if (!empty($canonicalWorkspaceId)) {
            if ($this->supportsMiddlewareWorkspaceId($workspaceClass)) {
                $mappedWorkspace = $workspaceClass::query()
                    ->where('middleware_workspace_id', $canonicalWorkspaceId)
                    ->first();

                if ($mappedWorkspace) {
                    return $this->workspaceLookupCache[$cacheKey] = $mappedWorkspace;
                }
            } else {
                $canonicalWorkspace = $workspaceClass::query()->find($canonicalWorkspaceId);
                if ($canonicalWorkspace) {
                    return $this->workspaceLookupCache[$cacheKey] = $canonicalWorkspace;
                }
            }
        }

        if ($this->usesLocalPublicIds()) {
            return $this->workspaceLookupCache[$cacheKey] = $workspaceClass::query()->find($workspaceId);
        }

        return $this->workspaceLookupCache[$cacheKey] = null;
    }

    public function resolveLocalId(mixed $workspaceId, ?string $workspaceClass = null): ?int
    {
        return $this->findByPublicId($workspaceId, $workspaceClass)?->getKey();
    }

    public function resolveCanonicalWorkspaceId(mixed $workspaceId, ?string $sourceAppPrefix = null): ?int
    {
        if ($workspaceId === null || $workspaceId === '') {
            return null;
        }

        foreach ($this->resolveSourceAppPrefixes($sourceAppPrefix) as $prefix) {
            try {
                $mapping = WorkspaceMapping::query()
                    ->where('app_prefix', $prefix)
                    ->where('external_workspace_id', (string) $workspaceId)
                    ->first();
            } catch (\Throwable) {
                $mapping = null;
            }

            if ($mapping) {
                return (int) $mapping->workspace_id;
            }
        }

        try {
            $workspace = WorkspaceMasterDB::query()->find($workspaceId);
            if ($workspace) {
                return (int) $workspace->getKey();
            }
        } catch (\Throwable) {
            // Ignore transient middleware DB failures after mapping lookup.
        }

        return null;
    }

    public function getResolvedWorkspace(?Request $request = null, ?string $workspaceClass = null): ?Model
    {
        $request ??= request();

        $workspace = $request->attributes->get('workspace_model');
        if ($workspace instanceof Model) {
            return $workspace;
        }

        $resolvedWorkspaceId = $this->getResolvedWorkspaceId($request, $workspaceClass);
        if (empty($resolvedWorkspaceId)) {
            return null;
        }

        $workspaceClass ??= $this->getWorkspaceModelClass();
        return $workspaceClass::query()->find($resolvedWorkspaceId);
    }

    public function getResolvedWorkspaceId(?Request $request = null, ?string $workspaceClass = null): ?int
    {
        $request ??= request();

        $resolvedWorkspaceId = $request->attributes->get('resolved_workspace_id')
            ?? $request->input('resolved_workspace_id')
            ?? $request->input('workspace_id');

        if (!empty($resolvedWorkspaceId)) {
            return (int) $resolvedWorkspaceId;
        }

        return $this->resolveLocalId($this->getRequestedWorkspaceId($request), $workspaceClass);
    }

    public function getPublicId(Model $workspace): int
    {
        if ($this->usesLocalPublicIds()) {
            return (int) $workspace->getKey();
        }

        if ($this->supportsMiddlewareWorkspaceId($workspace) && !empty($workspace->middleware_workspace_id)) {
            return (int) $workspace->middleware_workspace_id;
        }

        return (int) $workspace->getKey();
    }

    public function toPublicPayload(Model $workspace, array $payload): array
    {
        $publicId = $this->getPublicId($workspace);

        $payload['id'] = $publicId;

        if ((int) $workspace->getKey() !== $publicId) {
            $payload['local_id'] = (int) $workspace->getKey();
        }

        return $payload;
    }

    public function applyResolvedWorkspaceToRequest(Request $request, string $attribute = 'workspace_id', ?string $workspaceClass = null): ?int
    {
        $workspaceClass ??= $this->getWorkspaceModelClass();
        $publicWorkspaceId = $this->getRequestedWorkspaceId($request);
        $resolvedWorkspaceId = $this->resolveLocalId($publicWorkspaceId, $workspaceClass);

        if (!empty($resolvedWorkspaceId)) {
            $workspace = $this->findByPublicId($publicWorkspaceId, $workspaceClass)
                ?? $workspaceClass::query()->find($resolvedWorkspaceId);

            $request->attributes->set('workspace_public_id', (int) ($workspace ? $this->getPublicId($workspace) : $publicWorkspaceId));
            $request->attributes->set('resolved_workspace_id', $resolvedWorkspaceId);
            $request->attributes->set('workspace_model', $workspace);
            $request->merge([
                $attribute => $resolvedWorkspaceId,
                'resolved_workspace_id' => $resolvedWorkspaceId,
            ]);
        }

        return $resolvedWorkspaceId;
    }

    public function supportsMiddlewareWorkspaceId(string|Model $workspace): bool
    {
        $workspaceModel = is_string($workspace) ? new $workspace() : $workspace;
        $table = $workspaceModel->getTable();

        if (!array_key_exists($table, $this->middlewareWorkspaceColumnCache)) {
            $this->middlewareWorkspaceColumnCache[$table] = Schema::hasColumn($table, 'middleware_workspace_id');
        }

        return $this->middlewareWorkspaceColumnCache[$table];
    }

    public function usesLocalPublicIds(): bool
    {
        $currentAppPrefix = $this->normalizePrefix(config('bites.app_prefix', env('BITES_APP_PREFIX', '')));
        $publicSourceAppPrefix = $this->normalizePrefix(
            config('bites.public_workspace_source_app_prefix', env('BITES_PUBLIC_WORKSPACE_SOURCE_APP_PREFIX', 'dash'))
        );

        return $currentAppPrefix !== null
            && $publicSourceAppPrefix !== null
            && $currentAppPrefix === $publicSourceAppPrefix;
    }

    private function normalizePrefix(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private function resolveSourceAppPrefixes(?string $sourceAppPrefix = null): array
    {
        $prefixes = [];

        foreach ([
            $this->normalizePrefix($sourceAppPrefix),
            $this->normalizePrefix(
                config('bites.public_workspace_source_app_prefix', env('BITES_PUBLIC_WORKSPACE_SOURCE_APP_PREFIX', 'dash'))
            ),
            $this->normalizePrefix(config('bites.app_prefix', env('BITES_APP_PREFIX', ''))),
        ] as $prefix) {
            if ($prefix !== null && !in_array($prefix, $prefixes, true)) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }
}
