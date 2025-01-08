<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class WorkspaceModel extends Model
{

    public $timestamps = false;

    protected $table = 'workspace_models';
    protected $guarded = [];

    public function model()
    {
        return $this->setConnection('mysql')->morphTo();
    }
}
