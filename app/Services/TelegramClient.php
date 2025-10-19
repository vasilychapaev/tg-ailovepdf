<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class TelegramClient
{
    private string $token;
    private string $apiBase;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? env('TELEGRAM_BOT_TOKEN', '');
        $this->apiBase = 'https://api.telegram.org/bot' . $this->token . '/';
    }

    public function getUpdates(?int $offset = null, int $timeout = 25): array
    {
        $payload = [
            'timeout' => $timeout,
        ];
        if ($offset !== null) {
            $payload['offset'] = $offset;
        }
        $resp = Http::timeout($timeout + 5)->post($this->apiBase . 'getUpdates', $payload);
        if (!$resp->ok()) {
            return [];
        }
        $data = $resp->json();
        return $data['result'] ?? [];
    }

    public function getFile(string $fileId): ?array
    {
        $resp = Http::timeout(15)->post($this->apiBase . 'getFile', [
            'file_id' => $fileId,
        ]);
        if (!$resp->ok()) {
            return null;
        }
        $data = $resp->json();
        if (!($data['ok'] ?? false)) {
            return null;
        }
        return $data['result'] ?? null;
    }

    public function downloadFile(string $filePath): ?string
    {
        $url = 'https://api.telegram.org/file/bot' . $this->token . '/' . ltrim($filePath, '/');
        $resp = Http::timeout(60)->get($url);
        if (!$resp->ok()) {
            return null;
        }
        return $resp->body();
    }

    public function sendMessage(int|string $chatId, string $text): void
    {
        Http::timeout(10)->asForm()->post($this->apiBase . 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    public function sendDocument(int|string $chatId, string $filePath, ?string $caption = null): void
    {
        $multipart = [
            [
                'name' => 'chat_id',
                'contents' => (string)$chatId,
            ],
            [
                'name' => 'document',
                'contents' => fopen($filePath, 'r'),
                'filename' => basename($filePath),
            ],
        ];
        if ($caption) {
            $multipart[] = [
                'name' => 'caption',
                'contents' => $caption,
            ];
        }
        Http::timeout(60)->asMultipart()->post($this->apiBase . 'sendDocument', $multipart);
    }
}
