<?php

namespace App\Console\Commands;

use App\Services\LightsailMonthlyNetworkUsageService;
use App\Services\LinePushService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ReportLightsailMonthlyNetworkCommand extends Command
{
    protected $signature = 'aws:lightsail-monthly-network
        {--instance= : Lightsail instance name}
        {--region= : AWS region}
        {--profile= : AWS CLI profile}
        {--target= : Direct LINE user id override}
        {--send-line : Push the report to the configured direct LINE user}
        {--json : Output the report as JSON}';

    protected $description = 'Query the current-month Lightsail NetworkIn and NetworkOut totals.';

    public function handle(
        LightsailMonthlyNetworkUsageService $usage,
        LinePushService $linePush,
    ): int {
        $target = $this->option('send-line') ? $this->personalLineTarget() : null;
        $report = $usage->report(
            instanceName: $this->stringOption('instance'),
            region: $this->stringOption('region'),
            profile: $this->stringOption('profile'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line($this->message($report));
        }

        if ($target !== null) {
            $linePush->pushText($this->message($report), $target);
            $this->info('Lightsail monthly network report sent to the direct LINE user.');
        }

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }

    private function personalLineTarget(): string
    {
        $target = trim((string) ($this->option('target') ?: config('aws_metrics.line_target_id', '')));
        if ($target === '' && Storage::disk('local')->exists('line/yuanta-personal-notify-target-id.txt')) {
            $target = trim(Storage::disk('local')->get('line/yuanta-personal-notify-target-id.txt'));
        }

        if (! str_starts_with($target, 'U')) {
            throw new RuntimeException('Direct LINE user target is missing or is not a user ID; group and room targets are refused.');
        }

        return $target;
    }

    /**
     * @param array<string, int|float|string> $report
     */
    private function message(array $report): string
    {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $start = CarbonImmutable::parse((string) $report['start'])->setTimezone($timezone);
        $end = CarbonImmutable::parse((string) $report['end'])->setTimezone($timezone);
        $allowanceGb = (int) $report['allowance_gb'];
        $usagePercent = $allowanceGb > 0
            ? ((float) $report['total_gb'] / $allowanceGb) * 100
            : 0;

        return implode("\n", [
            'AWS Lightsail 本月網路流量',
            '主機：' . (string) $report['instance'],
            sprintf('期間：%s ～ %s', $start->format('Y-m-d H:i'), $end->format('Y-m-d H:i')),
            sprintf('流入 NetworkIn：%.2f GiB', (int) $report['network_in_bytes'] / 1073741824),
            sprintf('流出 NetworkOut：%.2f GiB', (int) $report['network_out_bytes'] / 1073741824),
            sprintf('總流量：%.2f GiB（%.2f GB）', (float) $report['total_gib'], (float) $report['total_gb']),
            sprintf('方案額度：%d GB（已使用 %.2f%%）', $allowanceGb, $usagePercent),
        ]);
    }
}
