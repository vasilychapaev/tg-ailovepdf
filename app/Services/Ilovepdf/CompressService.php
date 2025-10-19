<?php

namespace App\Services\Ilovepdf;

use Illuminate\Support\Facades\Log;
use Ilovepdf\Ilovepdf;

class CompressService
{
    public function __construct(
        private readonly ?string $publicKey = null,
        private readonly ?string $secretKey = null,
    ) {
    }

    /**
     * Выполняет сжатие PDF через iLovePDF SDK.
     * Возвращает размеры до/после и уменьшение в байтах.
     *
     * @return array{size_before:int,size_after:int,reduced_bytes:int}
     */
    public function compress(string $inputPath, string $outputPath): array
    {
        $start = (int) (microtime(true) * 1000);
        $cid = bin2hex(random_bytes(8));
        $mode = (string) (config('services.ilovepdf.compress_mode') ?? 'recommended');
        $timeout = (int) (config('services.ilovepdf.timeout') ?? 120);
        $pub = $this->publicKey ?: (string) (config('services.ilovepdf.public_key') ?? env('ILOVEPDF_PUBLIC_KEY'));
        $sec = $this->secretKey ?: (string) (config('services.ilovepdf.secret_key') ?? env('ILOVEPDF_SECRET_KEY'));

        if (!is_file($inputPath)) {
            throw new \RuntimeException('Input file not found: ' . $inputPath);
        }

        $before = @filesize($inputPath) ?: 0;

        $attempt = 0;
        $lastError = null;
        while ($attempt < 3) {
            $attempt++;
            try {
                Log::info('ilovepdf:init', ['cid' => $cid, 'attempt' => $attempt, 'mode' => $mode]);
                $ilovepdf = new Ilovepdf($pub, $sec);
                $task = $ilovepdf->newTask('compress');
                
                // Логируем начальный баланс токенов (если доступен)
                $this->logTokenBalance($ilovepdf, $cid);
                $task->addFile($inputPath);
                // Опции качества. SDK: setCompressionLevel or setMode в зависимости от версии
                if (method_exists($task, 'setCompressionLevel')) {
                    $task->setCompressionLevel($mode);
                } elseif (method_exists($task, 'setMode')) {
                    $task->setMode($mode);
                }
                $task->execute();
                $task->download(dirname($outputPath));

                // SDK скачивает с тем же именем; найдём последний файл из папки и переименуем
                $downloaded = $this->findLatestPdfInDir(dirname($outputPath));
                if ($downloaded === null || !is_file($downloaded)) {
                    throw new \RuntimeException('Downloaded file not found');
                }
                if ($downloaded !== $outputPath) {
                    if (!@rename($downloaded, $outputPath)) {
                        // если rename не удался — копируем
                        if (!@copy($downloaded, $outputPath)) {
                            throw new \RuntimeException('Failed to place output file: ' . $outputPath);
                        }
                        @unlink($downloaded);
                    }
                }

                $after = @filesize($outputPath) ?: 0;
                $duration = (int) (microtime(true) * 1000) - $start;
                
                // Логируем финальный баланс токенов
                $this->logTokenBalance($ilovepdf, $cid, 'final');
                
                Log::info('ilovepdf:success', [
                    'cid' => $cid,
                    'size_before' => $before,
                    'size_after' => $after,
                    'reduced_bytes' => max(0, $before - $after),
                    'duration_ms' => $duration,
                ]);

                return [
                    'size_before' => $before,
                    'size_after' => $after,
                    'reduced_bytes' => max(0, $before - $after),
                ];
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('ilovepdf:attempt_failed', [
                    'cid' => $cid,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                if ($attempt >= 3) {
                    break;
                }
                // экспоненциальная задержка: 0.5s, 1s
                usleep(($attempt === 1 ? 500000 : 1000000));
            }
        }

        $duration = (int) (microtime(true) * 1000) - $start;
        Log::error('ilovepdf:failed', ['cid' => $cid, 'duration_ms' => $duration, 'error' => $lastError?->getMessage()]);
        throw new \RuntimeException('Не удалось сжать PDF. Попробуйте позже.');
    }

    private function findLatestPdfInDir(string $dir): ?string
    {
        $files = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.pdf');
        if (!$files) {
            return null;
        }
        usort($files, static function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        return $files[0] ?? null;
    }

    /**
     * Логирует баланс токенов (если доступен в SDK)
     */
    private function logTokenBalance($ilovepdf, string $cid, string $stage = 'initial'): void
    {
        try {
            // Попытка получить баланс через рефлексию или публичные методы
            if (method_exists($ilovepdf, 'getRemainingCredits')) {
                $credits = $ilovepdf->getRemainingCredits();
                Log::info("ilovepdf:tokens:{$stage}", [
                    'cid' => $cid,
                    'remaining_credits' => $credits,
                ]);
            } elseif (property_exists($ilovepdf, 'remaining_credits')) {
                $credits = $ilovepdf->remaining_credits;
                Log::info("ilovepdf:tokens:{$stage}", [
                    'cid' => $cid,
                    'remaining_credits' => $credits,
                ]);
            } else {
                Log::debug("ilovepdf:tokens:{$stage}:unavailable", [
                    'cid' => $cid,
                    'note' => 'Token balance not available in current SDK version',
                ]);
            }
        } catch (\Throwable $e) {
            Log::debug("ilovepdf:tokens:{$stage}:error", [
                'cid' => $cid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
