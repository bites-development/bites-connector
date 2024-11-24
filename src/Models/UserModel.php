<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\BitesMiddleware\Shared\UseMiddlewareDBTrait;

class UserModel extends Model
{
    use UseMiddlewareDBTrait;

    public $timestamps = false;

    protected $table = 'user_models';
    protected $guarded = [];

    public function model()
    {
        return $this->setConnection('mysql')->morphTo();
    }
}
