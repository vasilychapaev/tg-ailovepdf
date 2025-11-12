<?php

namespace App\Actions\Telegram;

use App\Services\Telegram\TelegramClient;
use App\Services\Ilovepdf\CompressService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;

class HandleUpdate
{
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly CompressService $compress
    )
    {
    }

    public function handle(array $update): void
    {
        $cid = (string) Str::uuid();
        Log::debug('HandleUpdate:incoming', ['cid' => $cid, 'update_id' => Arr::get($update, 'update_id')]);
        $message = $update['message'] ?? null;
        if (!$message) {
            return;
        }
        $chatId = Arr::get($message, 'chat.id');
        $userId = Arr::get($message, 'from.id', 'unknown');
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
            // Шаг 2 — приём, сохранение, компрессия и ответ
            try {
                $fileId = (string) Arr::get($document, 'file_id');
                $origName = (string) Arr::get($document, 'file_name', 'document.pdf');
                $sizeBeforeMeta = (int) (Arr::get($document, 'file_size', 0) ?: 0);
                $fileSizeLimit = 20 * 1024 * 1024; // 20 МБ

                // Проверка размера файла
                if ($sizeBeforeMeta > 0 && $sizeBeforeMeta > $fileSizeLimit) {
                    $humanSize = $this->humanSize($sizeBeforeMeta);
                    $this->telegram->sendMessage($chatId, "⚠️ Файл слишком большой ({$humanSize}). Лимит: 20 МБ. Файлы больше этого размера пока обработать не можем. Извиняемся за неудобства — мы работаем над решением этой проблемы.");
                }

                // resolve file_path and download
                $fileResponse = $this->telegram->getFile($fileId);
                $fileInfo = $fileResponse['result'] ?? null;
                $apiError = $fileResponse['error'] ?? null;

                // Обработка ошибок от Telegram API
                if ($apiError !== null) {
                    $errorDescription = Arr::get($apiError, 'description', 'Неизвестная ошибка');
                    $errorCode = Arr::get($apiError, 'error_code', 'unknown');
                    $this->telegram->sendMessage($chatId, "❌ Ошибка от Telegram API: {$errorDescription} (код: {$errorCode})");
                    return;
                }

                if (!$fileInfo) {
                    throw new \RuntimeException('Не удалось получить file_path по file_id');
                }
                $filePath = (string) Arr::get($fileInfo, 'file_path');
                $bytes = $this->telegram->downloadFile($filePath);
                if ($bytes === null) {
                    throw new \RuntimeException('Не удалось скачать файл по file_path');
                }

                // build names and ensure dirs
                $ts = CarbonImmutable::now('UTC')->format('Y-m-d-H-i-s');
                $safeOriginal = $this->sanitizeFileName($origName);
                $baseName = $ts . '_' . $userId . '_' . $safeOriginal;
                if (!str_ends_with(strtolower($baseName), '.pdf')) {
                    $baseName .= '.pdf';
                }
                $incomingRel = 'incoming/' . $baseName;
                $outRel = 'outgoing/' . preg_replace('/\.pdf$/i', '_compressed.pdf', $baseName);
                Storage::makeDirectory('incoming');
                Storage::makeDirectory('outgoing');

                // save incoming
                Storage::put($incomingRel, $bytes);
                $inFull = Storage::path($incomingRel);
                $before = @filesize($inFull) ?: $sizeBeforeMeta;

                // compress
                $outFull = Storage::path($outRel);
                $res = $this->compress->compress($inFull, $outFull);
                $after = (int) ($res['size_after'] ?? (@filesize($outFull) ?: 0));

                // caption metrics with ASCII progress bar
                [$hBefore, $hAfter, $pct] = $this->humanMetrics($before, $after);
                $progressBar = $this->generateProgressBar($before, $after);
                $caption = "📄 PDF оптимизирован: \nБыло: {$hBefore} → Стало: {$hAfter} (−{$pct}%)\n{$progressBar}";

                // send result
                $this->telegram->sendDocument($chatId, $outFull, [
                    'caption' => $caption,
                ]);

                Log::info('PDF compressed and sent', [
                    'cid' => $cid,
                    'in' => $incomingRel,
                    'out' => $outRel,
                    'size_before' => $before,
                    'size_after' => $after,
                ]);
            } catch (\Throwable $e) {
                Log::error('HandleUpdate PDF failed', ['cid' => $cid, 'err' => $e->getMessage()]);
                $this->telegram->sendMessage($chatId, 'Не удалось обработать PDF. Попробуйте позже. CID: ' . $cid);
            }
            return;
        }

        $this->telegram->sendMessage($chatId, 'Пожалуйста, пришлите PDF, я его сожму.');
    }

    private function handleText(int|string $chatId, string $text): void
    {
        $cmd = trim($text);
        // if ($cmd === '/start' || $cmd === '/help') {
            // $this->telegram->sendMessage($chatId, "Отправьте PDF — я его сожму и верну. Если пришлёте текст или не-PDF, подскажу, что нужен PDF.\n\n⚠️ 20 МБ - лимит от телеграма для ботов. \nЛимиты обойдем. Ждите новыйх версий");
            // return;
        // }

        if ($text) {
            $this->telegram->sendMessage($chatId, 'Echo: ' . $text);
            // $this->telegram->sendMessage($chatId, 'Пожалуйста, пришлите PDF, я его сожму.');
        } 
        $this->telegram->sendMessage($chatId, "Отправьте PDF — я его сожму и верну.\nЕсли пришлёте текст или не-PDF, подскажу, что нужен PDF.\n\n⚠️ 20 МБ - лимит от телеграма для ботов. \nЛимиты обойдем. Ждите новыйх версий");
    }

    private function sanitizeFileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/u', '_', $name) ?? 'document.pdf';
        // ограничим длину
        return substr($name, 0, 120);
    }

    /**
     * @return array{0:string,1:string,2:string} [human_before, human_after, percent]
     */
    private function humanMetrics(int $before, int $after): array
    {
        $hb = $this->humanSize($before);
        $ha = $this->humanSize($after);
        $pct = $before > 0 ? number_format(max(0, ($before - $after) * 100 / $before), 1, '.', '') : '0.0';
        return [$hb, $ha, $pct];
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B','KB','MB','GB'];
        $i = 0;
        $val = (float) max(0, $bytes);
        while ($val >= 1024 && $i < count($units)-1) {
            $val /= 1024; $i++;
        }
        return number_format($val, $i === 0 ? 0 : 2, '.', '') . ' ' . $units[$i];
    }

    /**
     * Генерирует ASCII прогресс-бар для визуализации сжатия
     */
    private function generateProgressBar(int $before, int $after): string
    {
        $totalBars = 20;
        $compressedBars = min($totalBars, (int) round(($after / $before) * $totalBars));
        $savedBars = $totalBars - $compressedBars;

        $compressed = str_repeat('█', $compressedBars);
        $saved = str_repeat('░', $savedBars);

        return "{$compressed}{$saved}";
    }
}
