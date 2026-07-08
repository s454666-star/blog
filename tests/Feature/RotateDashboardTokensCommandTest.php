<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RotateDashboardTokensCommandTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = tempnam(sys_get_temp_dir(), 'dashboard-env-');
        file_put_contents($this->envPath, implode("\n", [
            'APP_URL=https://mystar.monster',
            'ESUN_PORTFOLIO_DASHBOARD_TOKEN=old-esun-token',
            'YUANTA_PORTFOLIO_DASHBOARD_TOKEN=old-yuanta-token',
            'LINE_CHANNEL_ID=esun-channel-id',
            'LINE_CHANNEL_SECRET=esun-secret',
            'LINE_CHANNEL_ACCESS_TOKEN=esun-static-token',
            'LINE_DASHBOARD_NOTIFY_TARGET_ID=Cesun-target',
            'YUANTA_LINE_CHANNEL_ID=yuanta-channel-id',
            'YUANTA_LINE_CHANNEL_SECRET=yuanta-secret',
            'YUANTA_LINE_CHANNEL_ACCESS_TOKEN=',
            'YUANTA_LINE_DASHBOARD_NOTIFY_TARGET_ID=Cyuanta-target',
        ]) . "\n");
    }

    protected function tearDown(): void
    {
        if (isset($this->envPath) && is_file($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    public function test_it_rotates_dashboard_tokens_and_sends_line_notifications(): void
    {
        Storage::fake('local');

        Http::fake([
            'https://api.line.me/oauth2/v3/token' => Http::response([
                'access_token' => 'issued-line-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 2592000,
            ]),
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200, [
                'x-line-request-id' => 'test-request-id',
            ]),
        ]);

        $exitCode = Artisan::call('line:rotate-dashboard-tokens', [
            'portfolio' => 'all',
            '--env-path' => $this->envPath,
            '--skip-config-cache' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $env = file_get_contents($this->envPath);
        $this->assertMatchesRegularExpression('/^ESUN_PORTFOLIO_DASHBOARD_TOKEN=[a-f0-9]{64}$/m', $env);
        $this->assertMatchesRegularExpression('/^YUANTA_PORTFOLIO_DASHBOARD_TOKEN=[a-f0-9]{64}$/m', $env);
        $this->assertStringNotContainsString('old-esun-token', $env);
        $this->assertStringNotContainsString('old-yuanta-token', $env);

        Storage::disk('local')->assertExists('esun/dashboard-url.txt');
        Storage::disk('local')->assertExists('yuanta/dashboard-url.txt');
        $this->assertStringContainsString('https://mystar.monster/tw-stock/esun-portfolio?token=', Storage::disk('local')->get('esun/dashboard-url.txt'));
        $this->assertStringContainsString('https://mystar.monster/tw-stock/yuanta-portfolio?token=', Storage::disk('local')->get('yuanta/dashboard-url.txt'));

        Http::assertSentCount(4);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && ($request->data()['to'] ?? null) === 'Cesun-target'
                && str_contains((string) ($request->data()['messages'][0]['text'] ?? ''), '/tw-stock/esun-portfolio?token=');
        });
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && ($request->data()['to'] ?? null) === 'Cyuanta-target'
                && str_contains((string) ($request->data()['messages'][0]['text'] ?? ''), '/tw-stock/yuanta-portfolio?token=');
        });
    }
}
