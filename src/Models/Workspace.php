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
        return app(WorkspaceIdentityService::class)->getPublicId($this);
    }

    public static function findByPublicId(mixed $workspaceId): ?self
    {
        return app(WorkspaceIdentityService::class)->findByPublicId($workspaceId, static::class);
    }

    public static function resolveLocalId(mixed $workspaceId): ?int
    {
        return app(WorkspaceIdentityService::class)->resolveLocalId($workspaceId, static::class);
    }
}
