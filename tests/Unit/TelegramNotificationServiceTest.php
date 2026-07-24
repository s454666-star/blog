<?php

namespace Tests\Unit;

use App\Services\TelegramNotificationService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelegramNotificationServiceTest extends TestCase
{
    public function test_it_sends_to_the_configured_group_without_exposing_the_token_in_errors(): void
    {
        config()->set('telegram.line_mirror.enabled', true);
        config()->set('telegram.line_mirror.routes.yuanta', [
            'bot_token' => 'secret-yuanta-token',
            'chat_id' => '-1001234567890',
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 321],
            ]),
        ]);

        $messageIds = app(TelegramNotificationService::class)->sendText('yuanta', '測試通知');

        $this->assertSame([321], $messageIds);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/botsecret-yuanta-token/sendMessage'
                && ($request->data()['chat_id'] ?? null) === '-1001234567890'
                && ($request->data()['text'] ?? null) === '測試通知';
        });
    }

    public function test_it_refuses_a_group_id_for_the_personal_route(): void
    {
        config()->set('telegram.line_mirror.enabled', true);
        config()->set('telegram.line_mirror.routes.personal', [
            'bot_token' => 'secret-token',
            'chat_id' => '-1001234567890',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('personal route refuses group');

        app(TelegramNotificationService::class)->sendText('personal', '測試通知');
    }

    public function test_it_does_nothing_when_the_mirror_is_disabled(): void
    {
        config()->set('telegram.line_mirror.enabled', false);
        Http::fake();

        $this->assertSame([], app(TelegramNotificationService::class)->sendText('yuanta', '測試通知'));
        Http::assertNothingSent();
    }
}
