<?php

namespace App\Actions\Telegram;

use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class HandleUpdate
{
    public function __construct(private readonly TelegramClient $telegram)
    {
    }

    public function handle(array $update): void
    {
        Log::debug('HandleUpdate:incoming', ['update_id' => Arr::get($update, 'update_id')]);
        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }
        $chatId = Arr::get($message, 'chat.id');
        $text = Arr::get($message, 'text');
        $document = Arr::get($message, 'document');

        if (is_string($text)) {
            $this->handleText($chatId, $text);
            return;
        }

        if (is_array($document)) {
            $mime = Arr::get($document, 'mime_type');
            if ($mime !== 'application/pdf') {
                $this->telegram->sendMessage($chatId, 'Пожалуйста, пришлите PDF, я его сожму.');
                return;
            }
            // Заглушка для шага 2 — компрессия будет реализована далее
            $this->telegram->sendMessage($chatId, 'PDF получен. Сжатие будет реализовано на следующем шаге.');
            return;
        }

        $this->telegram->sendMessage($chatId, 'Пожалуйста, пришлите PDF, я его сожму.');
    }

    private function handleText(int|string $chatId, string $text): void
    {
        $cmd = trim($text);
        if ($cmd === '/start' || $cmd === '/help') {
            $this->telegram->sendMessage($chatId, "Отправьте PDF — я его сожму и верну. Если пришлёте текст или не-PDF, подскажу, что нужен PDF.");
            return;
        }

        $this->telegram->sendMessage($chatId, 'Echo: ' . $text);
        $this->telegram->sendMessage($chatId, 'Пожалуйста, пришлите PDF, я его сожму.');
    }
}
