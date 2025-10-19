<?php

namespace Tests\Feature\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BotPollCommandTest extends TestCase
{
    public function test_bot_poll_handles_text_message(): void
    {
        Storage::fake('local');

        Http::fake(function ($request) {
            $url = (string) $request->url();
            if (str_contains($url, '/getUpdates')) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        [
                            'update_id' => 1001,
                            'message' => [
                                'message_id' => 10,
                                'date' => time(),
                                'chat' => ['id' => 12345, 'type' => 'private'],
                                'text' => 'Hello world',
                            ],
                        ],
                    ],
                ], 200);
            }
            return Http::response(['ok' => true], 200);
        });

        $this->artisan('bot:poll --timeout=0 --once')
            ->assertExitCode(0);

        // Проверяем, что offset был записан (обработка апдейтов прошла)
        $this->assertTrue(Storage::disk('local')->exists('telegram/offset.json'));
    }
}
