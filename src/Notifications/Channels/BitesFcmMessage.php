<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Notifications\Channels;

class BitesFcmMessage
{
    public string $title = '';
    public string $body = '';
    public array $data = [];
    public ?string $topic = null;
    public ?int $userId = null;
    public ?string $image = null;

    public static function create(): self
    {
        return new self();
    }

    public function title(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;
        return $this;
    }

    public function data(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function topic(string $topic): self
    {
        $this->topic = $topic;
        return $this;
    }

    public function toUser(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function image(string $image): self
    {
        $this->image = $image;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'data' => $this->data,
            'topic' => $this->topic,
            'user_id' => $this->userId,
            'image' => $this->image,
        ];
    }
}
