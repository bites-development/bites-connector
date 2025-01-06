<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Controllers;

use App\Http\Controllers\Controller;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Aws\Sns\SnsClient;
use Modules\BitesMiddleware\Events\SnsMessageReceived;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BitesMiddleware\Models\SnsLog;

class SnsController extends Controller
{
    public function __construct()
    {
    }

    public function listener(Request $request): JsonResponse
    {
        $message = Message::fromRawPostData();
        $validator = new MessageValidator();
        $messageArray = $message->toArray();
        if ($validator->isValid($message)) {
            SnsLog::updateOrCreate(
                [
                    'message_identifier' => $messageArray['MessageId'],
                    'message_type' => $request->header('x-amz-sns-message-type'),
                ],
                [
                    'message' => json_encode($message->toArray()),
                ]
            );

            if ($request->header('x-amz-sns-message-type') === 'SubscriptionConfirmation') {
                $snsClient = new SnsClient(
                    [
                        'version' => 'latest',
                        'region' => env('AWS_DEFAULT_REGION'),
                    ]
                );

                $snsClient->confirmSubscription
                (
                    [
                        'TopicArn' => $message['TopicArn'],
                        'Token' => $message['Token'],
                    ]
                );

                return response()->json(['message' => 'Subscription confirmed']);
            } elseif ($request->header('x-amz-sns-message-type') == 'Notification') {
                    event(new SnsMessageReceived($message->toArray()));
                    return response()->json(['message' => 'Message Received and Valid'], 200);

            }
        } else {
            return response()->json(['message' => 'Message Received and Not Valid'], 400);
        }

        return response()->json(['message' => 'Not Valid'], 400);
    }
}
