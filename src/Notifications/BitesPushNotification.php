<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Modules\BitesMiddleware\Notifications\Channels\BitesFcmChannel;
use Modules\BitesMiddleware\Notifications\Channels\BitesFcmMessage;

class BitesPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $title,
        protected string $body,
        protected array $data = [],
        protected ?string $topic = null,
        protected ?int $userId = null
    ) {}

    public function via($notifiable): array
    {
        return [BitesFcmChannel::class];
    }

    public function toBitesFcm($notifiable): BitesFcmMessage
    {
        $message = BitesFcmMessage::create()
            ->title($this->title)
            ->body($this->body)
            ->data($this->data);

        if ($this->topic) {
            $message->topic($this->topic);
        }

        if ($this->userId) {
            $message->toUser($this->userId);
        }

        return $message;
    }
}
