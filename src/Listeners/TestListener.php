<?php

namespace Modules\BitesMiddleware\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\BitesMiddleware\DTOs\SnsNotificationDTO;

class TestListener
{
    public function __construct()
    {

    }

    public function handle($event)
    {
        $message = $event->snsMessage;
        $dto = SnsNotificationDTO::fromArray($message);
        Log::error($dto->message[0]);
    }
}
