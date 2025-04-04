<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Models;

use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    protected $guarded = [];

    public function users(){
        return $this->belongsToMany(User::class,(new WorkspaceUser)->getTable());
    }
}
