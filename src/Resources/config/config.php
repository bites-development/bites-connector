<?php

use Modules\BitesMiddleware\Events\SnsMessageReceived;
use Modules\BitesMiddleware\Listeners\TestListener;

return [
    'key' => env('SNS_ACCESS_KEY_ID',env('AWS_ACCESS_KEY_ID')),
    'secret' => env('SNS_SECRET_ACCESS_KEY',env('AWS_SECRET_ACCESS_KEY')),
    'region' => env('SNS_DEFAULT_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    'listeners' => [
        SnsMessageReceived::class => [
            TestListener::class
        ]
    ]
];
