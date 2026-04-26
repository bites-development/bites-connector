<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\BitesMiddleware\Models\WorkspaceMasterDB;
use Modules\BitesMiddleware\Models\WorkspaceUserMasterDB;

class WorkspaceBootstrapService
{
    private array $columnCache = [];
    private ?bool $hasMasterWorkspaceUsersTable = null;

    public function __construct(
        private readonly WorkspaceIdentityService $workspaceIdentityService,
    ) {
    }

    public function ensureRequestedWorkspaceContext(Request $request, ?Model $user = null): ?Model
    {
        $workspaceId = $this->workspaceIdentityService->getRequestedWorkspaceId($request);
        if (empty($workspaceId)) {
            return null;
        }

        $workspace = $this->workspaceIdentityService->findByPublicId($workspaceId);
        if (!$workspace) {
            app(SyncWorkspaceService::class)->sync();
            $workspace = $this->workspaceIdentityService->findByPublicId($workspaceId);
        }

        if (!$workspace) {
            abort(403, 'Selected workspace is not available.');
        }

        $this->workspaceIdentityService->applyResolvedWorkspaceToRequest($request);
        $workspace = $this->workspaceIdentityService->getResolvedWorkspace($request) ?? $workspace;

        if ($user) {
            $this->ensureLocalWorkspaceAccess($workspace, $user);
        }

        return $workspace;
    }

    public function ensureLocalWorkspaceAccess(Model $workspace, Model $user): bool
    {
        if ($this->hasLocalWorkspaceAccess($workspace, $user)) {
            return true;
        }

        $canonicalWorkspaceId = $this->resolveCanonicalWorkspaceId($workspace);
        $remoteUserId = $this->resolveRemoteUserId($user);

        if (empty($canonicalWorkspaceId) || empty($remoteUserId)) {
            abort(403, 'User does not have access to selected workspace.');
        }

        $masterWorkspace = WorkspaceMasterDB::query()->find($canonicalWorkspaceId);
        $isOwner = $masterWorkspace && (int) ($masterWorkspace->b_user_id ?? 0) === $remoteUserId;
        $hasRemoteMembership = $this->hasMasterWorkspaceUsersTable()
            && WorkspaceUserMasterDB::query()
                ->where('workspace_id', $canonicalWorkspaceId)
                ->where('b_user_id', $remoteUserId)
                ->exists();

        if (!$isOwner && !$hasRemoteMembership) {
            abort(403, 'User does not have access to selected workspace.');
        }

        $this->syncLocalWorkspaceOwner($workspace, $user, $isOwner, $remoteUserId);
        $this->syncLocalWorkspaceMembership($workspace, $user, $isOwner, $remoteUserId);

        return true;
    }

    private function hasLocalWorkspaceAccess(Model $workspace, Model $user): bool
    {
        if ($this->hasColumn($workspace, 'owner_id') && (int) ($workspace->owner_id ?? 0) === (int) $user->getKey()) {
            return true;
        }

        $memberModelClass = config('bites.WORKSPACE.WORKSPACE_USER.MODEL');
        $userColumn = config('bites.WORKSPACE.WORKSPACE_USER.USER_COLUMN', 'b_user_id');
        $workspaceColumn = config('bites.WORKSPACE.WORKSPACE_USER.WORKSPACE_COLUMN', 'workspace_id');

        if (empty($memberModelClass) || !class_exists($memberModelClass)) {
            return false;
        }

        $memberModel = new $memberModelClass();
        if (!$this->hasTable($memberModel)) {
            return false;
        }

        $memberUserId = $this->resolveLocalMembershipUserId($user, $userColumn);
        if (empty($memberUserId)) {
            return false;
        }

        return $memberModelClass::query()
            ->where($workspaceColumn, $workspace->getKey())
            ->where($userColumn, $memberUserId)
            ->exists();
    }

    private function syncLocalWorkspaceOwner(Model $workspace, Model $user, bool $isOwner, int $remoteUserId): void
    {
        $updates = [];

        if ($isOwner && $this->hasColumn($workspace, 'owner_id') && (int) ($workspace->owner_id ?? 0) !== (int) $user->getKey()) {
            $updates['owner_id'] = (int) $user->getKey();
        }

        if ($isOwner && $this->hasColumn($workspace, 'created_by') && (int) ($workspace->created_by ?? 0) !== (int) $user->getKey()) {
            $updates['created_by'] = (int) $user->getKey();
        }

        if ($this->hasColumn($workspace, 'b_user_id') && (int) ($workspace->b_user_id ?? 0) !== $remoteUserId) {
            $updates['b_user_id'] = $remoteUserId;
        }

        if (empty($updates)) {
            return;
        }

        $workspace->forceFill($updates);
        if ($workspace->isDirty()) {
            $workspace->saveQuietly();
        }
    }

    private function syncLocalWorkspaceMembership(Model $workspace, Model $user, bool $isOwner, int $remoteUserId): void
    {
        $memberModelClass = config('bites.WORKSPACE.WORKSPACE_USER.MODEL');
        $userColumn = config('bites.WORKSPACE.WORKSPACE_USER.USER_COLUMN', 'b_user_id');
        $workspaceColumn = config('bites.WORKSPACE.WORKSPACE_USER.WORKSPACE_COLUMN', 'workspace_id');

        if (empty($memberModelClass) || !class_exists($memberModelClass)) {
            return;
        }

        $memberModel = new $memberModelClass();
        if (!$this->hasTable($memberModel)) {
            return;
        }

        $memberUserId = $userColumn === 'b_user_id'
            ? $remoteUserId
            : (int) $user->getKey();

        if ($memberUserId < 1) {
            return;
        }

        $attributes = [
            $workspaceColumn => $workspace->getKey(),
            $userColumn => $memberUserId,
        ];

        $values = [];
        if ($this->hasColumn($memberModel, 'is_owner')) {
            $values['is_owner'] = $isOwner;
        }

        $record = $memberModelClass::query()->firstOrNew($attributes);
        $record->forceFill($values);
        if (!$record->exists || $record->isDirty()) {
            $record->save();
        }
    }

    private function resolveCanonicalWorkspaceId(Model $workspace): ?int
    {
        if (!empty($workspace->middleware_workspace_id)) {
            return (int) $workspace->middleware_workspace_id;
        }

        return $this->workspaceIdentityService->resolveCanonicalWorkspaceId($workspace->getKey());
    }

    private function resolveRemoteUserId(Model $user): ?int
    {
        $requestRemoteUserId = (int) request()->attributes->get('middleware_user_id', 0);
        if ($requestRemoteUserId > 0) {
            return $requestRemoteUserId;
        }

        if ($this->hasColumn($user, 'm_user_id') && !empty($user->m_user_id)) {
            return (int) $user->m_user_id;
        }

        $userKey = (int) $user->getKey();

        return $userKey > 0 ? $userKey : null;
    }

    private function resolveLocalMembershipUserId(Model $user, string $userColumn): ?int
    {
        if ($userColumn === 'b_user_id') {
            return $this->resolveRemoteUserId($user);
        }

        $userKey = (int) $user->getKey();

        return $userKey > 0 ? $userKey : null;
    }

    private function hasColumn(Model $model, string $column): bool
    {
        $cacheKey = $model->getTable() . ':' . $column;
        if (array_key_exists($cacheKey, $this->columnCache)) {
            return $this->columnCache[$cacheKey];
        }

        return $this->columnCache[$cacheKey] = Schema::hasColumn($model->getTable(), $column);
    }

    private function hasTable(Model $model): bool
    {
        $table = $model->getTable();

        return Schema::hasTable(str_contains($table, '.') ? explode('.', $table, 2)[1] : $table);
    }

    private function hasMasterWorkspaceUsersTable(): bool
    {
        if ($this->hasMasterWorkspaceUsersTable !== null) {
            return $this->hasMasterWorkspaceUsersTable;
        }

        $model = new WorkspaceUserMasterDB();
        $table = $model->getTable();
        $table = str_contains($table, '.') ? explode('.', $table, 2)[1] : $table;

        return $this->hasMasterWorkspaceUsersTable = Schema::connection($model->getConnectionName())->hasTable($table);
    }
}
