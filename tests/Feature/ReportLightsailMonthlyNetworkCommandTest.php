<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ReportLightsailMonthlyNetworkCommandTest extends TestCase
{
    public function test_it_queries_monthly_metrics_and_sends_only_to_the_yuanta_telegram_group(): void
    {
        config()->set('telegram.line_mirror.enabled', true);
        config()->set('telegram.line_mirror.routes.yuanta', [
            'bot_token' => 'telegram-test-token',
            'chat_id' => '-1004546666',
        ]);

        Process::fake(function (PendingProcess $process) {
            $command = $process->command;

            if (in_array('get-instances', $command, true)) {
                return Process::result(json_encode(['instances' => [[
                    'name' => 'star-s',
                    'bundleId' => 'medium_3_0',
                    'state' => ['name' => 'running'],
                ]]], JSON_THROW_ON_ERROR));
            }

            if (in_array('get-bundles', $command, true)) {
                return Process::result(json_encode(['bundles' => [[
                    'bundleId' => 'medium_3_0',
                    'transferPerMonthInGb' => 4096,
                ]]], JSON_THROW_ON_ERROR));
            }

            $metricIndex = array_search('--metric-name', $command, true);
            $metric = $metricIndex === false ? '' : ($command[$metricIndex + 1] ?? '');

            return Process::result(json_encode(['metricData' => [
                ['sum' => $metric === 'NetworkIn' ? 100000000000 : 200000000000],
            ]], JSON_THROW_ON_ERROR));
        });

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 123],
            ]),
        ]);

        $this->artisan('aws:lightsail-monthly-network --send-telegram')
            ->expectsOutputToContain('總流量：279.40 GiB（300.00 GB）')
            ->expectsOutput('Lightsail monthly network report sent to the Yuanta Telegram group.')
            ->assertSuccessful();

        Process::assertRanTimes(fn (): bool => true, 4);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains($request->url(), 'api.telegram.org/')
                && ($payload['chat_id'] ?? null) === '-1004546666'
                && str_contains((string) ($payload['text'] ?? ''), 'AWS Lightsail 本月網路流量');
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'api.line.me'));
    }
}
