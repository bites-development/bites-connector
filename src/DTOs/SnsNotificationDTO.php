<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\DTOs;

class SnsNotificationDTO
{
    public function __construct(
       public string $type,
       public string $messageId,
       public string $topicArn,
       public array $message,
       public string $timestamp,
       public string $signatureVersion,
       public string $signature,
       public string $signingCertUrl,
       public string $unsubscribeUrl
    ) {

    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['Type'] ?? '',
            $data['MessageId'] ?? '',
            $data['TopicArn'] ?? '',
            json_decode($data['Message'] ?? '[]',true),
            $data['Timestamp'] ?? '',
            $data['SignatureVersion'] ?? '',
            $data['Signature'] ?? '',
            $data['SigningCertURL'] ?? '',
            $data['UnsubscribeURL'] ?? ''
        );
    }
}
