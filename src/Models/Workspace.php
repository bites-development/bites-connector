<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\BitesMiddleware\Services\WorkspaceIdentityService;

class Workspace extends Model
{
    protected $guarded = [];

    public function users(){
        return $this->belongsToMany(User::class,(new WorkspaceUser)->getTable());
    }

    public function getPublicId(): int
    {
        if (!class_exists(WorkspaceIdentityService::class)) {
            return (int) $this->getKey();
        }

        try {
            return app()->make(WorkspaceIdentityService::class)->getPublicId($this);
        } catch (\Throwable) {
            return (int) $this->getKey();
        }
    }

    public static function findByPublicId(mixed $workspaceId): ?self
    {
        if (!class_exists(WorkspaceIdentityService::class)) {
            return static::query()->find($workspaceId);
        }

        try {
            return app()->make(WorkspaceIdentityService::class)->findByPublicId($workspaceId, static::class);
        } catch (\Throwable) {
            return static::query()->find($workspaceId);
        }
    }

    public static function resolveLocalId(mixed $workspaceId): ?int
    {
        if (!class_exists(WorkspaceIdentityService::class)) {
            return static::query()->find($workspaceId)?->getKey();
        }

        try {
            return app()->make(WorkspaceIdentityService::class)->resolveLocalId($workspaceId, static::class);
        } catch (\Throwable) {
            return static::query()->find($workspaceId)?->getKey();
        }
    }
}
