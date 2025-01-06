<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Models;

use Modules\BitesMiddleware\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Model;

class SnsLog extends Model
{
    use UuidTrait;


    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'message',
        'message_identifier',
        'message_type',
    ];

    protected $casts = [
        'id' => 'string',
    ];
}
