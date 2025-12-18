<?php

declare(strict_types=1);

namespace Modules\BitesMiddleware\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BitesPushService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('bites.push.base_url', ''), '/');
        $this->apiKey = config('bites.push.api_key', '');
    }

    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        return $this->send([
            'tokens' => $tokens,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        return $this->send([
            'user_id' => $userId,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        return $this->sendTopic([
            'topic' => $topic,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    protected function send(array $payload): array
    {
        if (empty($this->baseUrl) || empty($this->apiKey)) {
            Log::warning('BitesPushService: Push API not configured');
            return ['success' => false, 'message' => 'Push API not configured'];
        }

        try {
            $response = Http::withHeaders([
                'X-Push-Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/api/v1/push/send", $payload);

            $result = $response->json() ?? [];

            if ($response->successful()) {
                Log::info('BitesPushService: Push sent', [
                    'success' => $result['success'] ?? false,
                    'sent' => $result['sent'] ?? 0,
                ]);
                return $result;
            }

            Log::warning('BitesPushService: Push failed', [
                'status' => $response->status(),
                'response' => $result,
            ]);

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Request failed',
            ];

        } catch (\Exception $e) {
            Log::error('BitesPushService: Exception', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    protected function sendTopic(array $payload): array
    {
        if (empty($this->baseUrl) || empty($this->apiKey)) {
            Log::warning('BitesPushService: Push API not configured');
            return ['success' => false, 'message' => 'Push API not configured'];
        }

        try {
            $response = Http::withHeaders([
                'X-Push-Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/api/v1/push/topic", $payload);

            $result = $response->json() ?? [];

            if ($response->successful()) {
                return $result;
            }

            return [
                'success' => false,
                'message' => $result['message'] ?? 'Request failed',
            ];

        } catch (\Exception $e) {
            Log::error('BitesPushService: Topic exception', [
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
