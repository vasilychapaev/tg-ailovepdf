<?php

namespace App\Services\Ilovepdf;

use Illuminate\Support\Facades\Log;

class CompressService
{
    public function compress(string $inputPath, string $outputPath): array
    {
        try {
            if (!is_file($inputPath)) {
                throw new \RuntimeException('Input file not found: ' . $inputPath);
            }
            $before = filesize($inputPath) ?: 0;
            // TODO: replace with real iLovePDF API call. For now, copy as a stub.
            if (!@copy($inputPath, $outputPath)) {
                throw new \RuntimeException('Failed to create output file: ' . $outputPath);
            }
            $after = filesize($outputPath) ?: 0;
            Log::debug('CompressService.compress', [
                'input' => $inputPath,
                'output' => $outputPath,
                'size_before' => $before,
                'size_after' => $after,
            ]);
            return [
                'size_before' => $before,
                'size_after' => $after,
                'reduced_bytes' => max(0, $before - $after),
            ];
        } catch (\Throwable $e) {
            Log::error('CompressService.compress error: ' . $e->getMessage());
            throw $e;
        }
    }
}
