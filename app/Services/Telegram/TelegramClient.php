<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramClient
{
    protected string $baseUrl;
    protected string $fileBaseUrl;
    protected string $token;

    public function __construct(
        protected readonly string $tokenParam = ''
    ) {
        $tok = $this->tokenParam
            ?: (string) (config('services.telegram.bot_token') ?? '')
            ?: (string) env('TELEGRAM_BOT_TOKEN', '');
        $this->token = $tok;
        $this->baseUrl = 'https://api.telegram.org/bot' . $tok . '/';
        $this->fileBaseUrl = 'https://api.telegram.org/file/bot' . $tok . '/';
    }

    /**
     * Long polling getUpdates
     * @param array{offset?:int,timeout?:int,limit?:int,allowed_updates?:array} $params
     */
    public function getUpdates(array $params = []): array
    {
        $url = $this->baseUrl . 'getUpdates';
        Log::debug('TG getUpdates request', ['params' => $params]);
        $resp = Http::timeout(($params['timeout'] ?? 30) + 5)
            ->get($url, $params);
        $data = $resp->json() ?? [];
        Log::debug('TG getUpdates response', ['status' => $resp->status(), 'ok' => $data['ok'] ?? null, 'result_count' => isset($data['result']) ? count($data['result']) : null]);
        return $data;
    }

    /**
     * sendMessage
     * @param int|string $chatId
     */
    public function sendMessage(int|string $chatId, string $text, array $opts = []): array
    {
        $url = $this->baseUrl . 'sendMessage';
        $payload = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $opts['parse_mode'] ?? 'HTML',
            'disable_web_page_preview' => true,
        ], $opts);
        Log::debug('TG sendMessage', ['chat_id' => $chatId]);
        $resp = Http::asForm()->post($url, $payload);
        return $resp->json() ?? [];
    }

    /**
     * sendDocument (на будущее, для шага 2)
     * @param int|string $chatId
     * @param \SplFileInfo|string $file
     */
    public function sendDocument(int|string $chatId, \SplFileInfo|string $file, array $opts = []): array
    {
        $url = $this->baseUrl . 'sendDocument';
        $payload = [
            'chat_id' => $chatId,
        ];
        foreach ($opts as $k => $v) {
            $payload[$k] = $v;
        }
        $multipart = [];
        foreach ($payload as $k => $v) {
            $multipart[] = ['name' => $k, 'contents' => is_scalar($v) ? (string) $v : json_encode($v)];
        }
        if ($file instanceof \SplFileInfo) {
            $multipart[] = ['name' => 'document', 'contents' => fopen($file->getRealPath(), 'r'), 'filename' => $file->getBasename()];
        } else {
            $multipart[] = ['name' => 'document', 'contents' => fopen($file, 'r'), 'filename' => basename($file)];
        }
        $resp = Http::asMultipart()->post($url, $multipart);
        return $resp->json() ?? [];
    }

    /**
     * getFile: resolve file_id to file_path
     */
    public function getFile(string $fileId): ?array
    {
        $url = $this->baseUrl . 'getFile';
        $resp = Http::timeout(20)->asForm()->post($url, ['file_id' => $fileId]);
        $data = $resp->json() ?? [];
        if (!($data['ok'] ?? false)) {
            Log::warning('TG getFile not ok', ['status' => $resp->status(), 'body' => $data]);
            return null;
        }
        return $data['result'] ?? null;
    }

    /**
     * downloadFile: download bytes by file_path
     */
    public function downloadFile(string $filePath): ?string
    {
        $url = $this->fileBaseUrl . ltrim($filePath, '/');
        $resp = Http::timeout(120)->get($url);
        if (!$resp->ok()) {
            Log::warning('TG download file failed', ['status' => $resp->status(), 'url' => $url]);
            return null;
        }
        return $resp->body();
    }
}
