<?php

namespace App\Console\Commands;

use App\Actions\Telegram\HandleUpdate;
use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BotPollCommand extends Command
{
    protected $signature = 'bot:poll {--timeout=25} {--sleep=1} {--once}';

    protected $description = 'Long-poll Telegram getUpdates and handle messages';

    private string $offsetPath = 'telegram/offset.json';

    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly HandleUpdate $handler,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting bot polling...');
        $timeout = (int) $this->option('timeout');
        $sleep = (int) $this->option('sleep');
        do {
            $offset = $this->readOffset();
            $params = [
                'timeout' => $timeout,
            ];
            if ($offset !== null) {
                $params['offset'] = $offset;
            }

            $data = $this->telegram->getUpdates($params);
            if (!($data['ok'] ?? false)) {
                $this->warn('Telegram response not ok');
                // небольшая пауза, чтобы не крутить CPU
                sleep(max($sleep, 1));
                continue;
            }

            $updates = $data['result'] ?? [];
            $lastId = null;
            foreach ($updates as $u) {
                $lastId = $u['update_id'] ?? $lastId;
                try {
                    $this->handler->handle($u);
                } catch (\Throwable $e) {
                    Log::error('Handle update failed', ['e' => $e->getMessage()]);
                }
            }

            if ($lastId !== null) {
                $this->writeOffset(((int) $lastId) + 1);
            }

            if ($this->option('once')) {
                break;
            }

            sleep(max($sleep, 1));
        } while (true);

        $this->info('Bot polling stopped.');
        return self::SUCCESS;
    }

    private function readOffset(): ?int
    {
        if (!Storage::exists($this->offsetPath)) {
            return null;
        }
        $raw = Storage::get($this->offsetPath);
        $j = json_decode($raw, true);
        $off = $j['offset'] ?? null;
        return is_numeric($off) ? (int) $off : null;
    }

    private function writeOffset(int $offset): void
    {
        $payload = json_encode(['offset' => $offset], JSON_UNESCAPED_UNICODE);
        Storage::put($this->offsetPath, $payload);
    }
}
