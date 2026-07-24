<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class SendYuantaDashboardLineUrlCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://mystar.monster');
        config()->set('yuanta.dashboard_token', 'yuanta-test-token');
        config()->set('line.yuanta_channel_id', '2010552911');
        config()->set('line.yuanta_channel_secret', 'yuanta-secret');
        config()->set('line.yuanta_channel_access_token', '');
        config()->set('line.yuanta_dashboard_notify_target_id', '');
        config()->set('line.dashboard_notify_target_id', 'Cesun-target');
        config()->set('telegram.line_mirror.enabled', true);
        config()->set('telegram.line_mirror.routes.yuanta', [
            'bot_token' => 'yuanta-telegram-token',
            'chat_id' => '-100222',
        ]);
    }

    public function test_it_sends_yuanta_dashboard_url_to_yuanta_group_only(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('line/yuanta-dashboard-notify-target-id.txt', "Cyuanta-target\n");

        Http::fake([
            'https://api.line.me/oauth2/v3/token' => Http::response([
                'access_token' => 'line-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 2592000,
            ]),
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200, [
                'x-line-request-id' => 'test-request-id',
            ]),
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 123],
            ]),
        ]);

        $exitCode = Artisan::call('line:send-yuanta-dashboard-url');

        $this->assertSame(0, $exitCode);
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.line.me/v2/bot/message/push') {
                return false;
            }

            $payload = $request->data();

            return ($payload['to'] ?? null) === 'Cyuanta-target'
                && str_contains((string) ($payload['messages'][0]['text'] ?? ''), '/tw-stock/yuanta-portfolio?token=yuanta-test-token');
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/botyuanta-telegram-token/sendMessage'
                && ($request->data()['chat_id'] ?? null) === '-100222'
                && str_contains((string) ($request->data()['text'] ?? ''), '/tw-stock/yuanta-portfolio?token=yuanta-test-token');
        });
    }

    public function test_it_refuses_to_send_yuanta_url_to_esun_group_target(): void
    {
        config()->set('line.dashboard_notify_target_id', 'Csame-target');
        config()->set('line.yuanta_dashboard_notify_target_id', 'Csame-target');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to send Yuanta dashboard URL to the E.SUN LINE target.');

        Artisan::call('line:send-yuanta-dashboard-url', [
            '--dry-run' => true,
        ]);
    }
}
