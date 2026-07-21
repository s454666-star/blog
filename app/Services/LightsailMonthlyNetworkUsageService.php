<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class LightsailMonthlyNetworkUsageService
{
    /**
     * @return array<string, int|float|string>
     */
    public function report(
        ?string $instanceName = null,
        ?string $region = null,
        ?string $profile = null,
        ?CarbonImmutable $now = null,
    ): array {
        $instanceName = trim((string) ($instanceName ?: config('aws_metrics.instance')));
        $region = trim((string) ($region ?: config('aws_metrics.region')));
        $profile = trim((string) ($profile ?: config('aws_metrics.profile')));
        $end = ($now ?: CarbonImmutable::now('UTC'))->utc();
        $start = $end->startOfMonth();

        $instances = $this->aws([
            'lightsail', 'get-instances', '--region', $region, '--profile', $profile,
        ]);
        $instance = collect($instances['instances'] ?? [])->firstWhere('name', $instanceName);
        if (! is_array($instance)) {
            throw new RuntimeException("Lightsail instance {$instanceName} was not found in {$region}.");
        }

        $bundles = $this->aws([
            'lightsail', 'get-bundles', '--region', $region, '--profile', $profile, '--include-inactive',
        ]);
        $bundleId = (string) ($instance['bundleId'] ?? '');
        $bundle = collect($bundles['bundles'] ?? [])->firstWhere('bundleId', $bundleId);

        $networkIn = $this->metricSum($instanceName, $region, $profile, 'NetworkIn', $start, $end);
        $networkOut = $this->metricSum($instanceName, $region, $profile, 'NetworkOut', $start, $end);
        $total = $networkIn + $networkOut;

        return [
            'instance' => $instanceName,
            'region' => $region,
            'start' => $start->toIso8601ZuluString(),
            'end' => $end->toIso8601ZuluString(),
            'network_in_bytes' => $networkIn,
            'network_out_bytes' => $networkOut,
            'total_bytes' => $total,
            'total_gib' => $total / 1073741824,
            'total_gb' => $total / 1000000000,
            'bundle_id' => $bundleId,
            'allowance_gb' => (int) ($bundle['transferPerMonthInGb'] ?? 0),
        ];
    }

    private function metricSum(
        string $instanceName,
        string $region,
        string $profile,
        string $metricName,
        CarbonImmutable $start,
        CarbonImmutable $end,
    ): int {
        $response = $this->aws([
            'lightsail', 'get-instance-metric-data',
            '--region', $region,
            '--profile', $profile,
            '--instance-name', $instanceName,
            '--metric-name', $metricName,
            '--period', '3600',
            '--statistics', 'Sum',
            '--unit', 'Bytes',
            '--start-time', $start->toIso8601ZuluString(),
            '--end-time', $end->toIso8601ZuluString(),
        ]);

        return (int) round(collect($response['metricData'] ?? [])->sum(
            fn (mixed $row): float => is_array($row) ? (float) ($row['sum'] ?? 0) : 0,
        ));
    }

    /**
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function aws(array $arguments): array
    {
        $command = [(string) config('aws_metrics.cli_path', 'aws'), ...$arguments, '--output', 'json'];
        $environment = ['AWS_EC2_METADATA_DISABLED' => 'true'];

        foreach ([
            'AWS_SHARED_CREDENTIALS_FILE' => config('aws_metrics.credentials_file'),
            'AWS_CONFIG_FILE' => config('aws_metrics.config_file'),
        ] as $name => $value) {
            if (is_string($value) && trim($value) !== '') {
                $environment[$name] = $value;
            }
        }

        $result = Process::env($environment)->timeout(120)->run($command);
        if ($result->failed()) {
            throw new RuntimeException('AWS Lightsail metrics query failed: ' . trim($result->errorOutput()));
        }

        $decoded = json_decode($result->output(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('AWS Lightsail metrics query returned invalid JSON.');
        }

        return $decoded;
    }
}
