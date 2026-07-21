<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ReportLightsailMonthlyNetworkCommandTest extends TestCase
{
    public function test_it_queries_monthly_metrics_and_sends_only_to_a_direct_user(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('line/yuanta-personal-notify-target-id.txt', "Udirect-user\n");
        config()->set('line.yuanta_channel_access_token', 'test-token');

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
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200, [
                'x-line-request-id' => 'line-request-id',
            ]),
        ]);

        $this->artisan('aws:lightsail-monthly-network --send-line')
            ->expectsOutputToContain('總流量：279.40 GiB（300.00 GB）')
            ->expectsOutput('Lightsail monthly network report sent to the direct LINE user.')
            ->assertSuccessful();

        Process::assertRanTimes(fn (): bool => true, 4);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && ($payload['to'] ?? null) === 'Udirect-user'
                && str_contains((string) ($payload['messages'][0]['text'] ?? ''), 'AWS Lightsail 本月網路流量');
        });
    }

    public function test_it_refuses_a_group_target_before_querying_aws(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('line/yuanta-personal-notify-target-id.txt', "Cgroup-target\n");
        Process::fake();

        try {
            Artisan::call('aws:lightsail-monthly-network --send-line');
            $this->fail('Expected a non-user LINE target to be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Direct LINE user target is missing or is not a user ID',
                $exception->getMessage(),
            );
        }

        Process::assertNothingRan();
    }
}
