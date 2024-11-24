<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class WorkspaceMasterDB extends Model
{
    use UseMiddlewareDBTrait;

    protected $table = 'workspaces';
    protected $guarded = [];



}
