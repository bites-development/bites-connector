<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Modules\BitesMiddleware\Services\BitesPushService;

class BitesFcmChannel
{
    public function __construct(
        protected BitesPushService $pushService
    ) {}

    public function send($notifiable, Notification $notification): void
    {
        $message = $notification->toBitesFcm($notifiable);

        if (!$message instanceof BitesFcmMessage) {
            return;
        }

        // Send to topic if specified
        if ($message->topic) {
            $this->pushService->sendToTopic(
                $message->topic,
                $message->title,
                $message->body,
                $message->data
            );
            return;
        }

        // Send to user_id if specified
        if ($message->userId) {
            $this->pushService->sendToUser(
                $message->userId,
                $message->title,
                $message->body,
                $message->data
            );
            return;
        }

        // Otherwise send to device tokens
        $tokens = $this->getTokens($notifiable);

        if (empty($tokens)) {
            return;
        }

        $this->pushService->sendToTokens(
            $tokens,
            $message->title,
            $message->body,
            $message->data
        );
    }

    protected function getTokens($notifiable): array
    {
        if (method_exists($notifiable, 'routeNotificationForBitesFcm')) {
            $tokens = $notifiable->routeNotificationForBitesFcm();
            return is_array($tokens) ? $tokens : [$tokens];
        }

        if (method_exists($notifiable, 'routeNotificationForFcm')) {
            $tokens = $notifiable->routeNotificationForFcm();
            return is_array($tokens) ? $tokens : [$tokens];
        }

        return [];
    }
}
