<?php

namespace App\Console\Commands;

use App\Http\Controllers\TwFuturesHourlyPriceController;
use App\Services\LinePushService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class NotifyTaiexFuturesLineAlertsCommand extends Command
{
    private const GAP_THRESHOLD = 1000.0;

    private const OPENING_GAP_THRESHOLD = 600.0;

    private const BIAS_RATE_THRESHOLD = 0.05;

    private const CACHE_TTL_DAYS = 7;

    protected $signature = 'tw-stock:notify-taiex-futures-line
        {--target= : Override LINE group or room id}
        {--lookback-minutes=90 : Scan recent displayed K-line timestamps}
        {--max-alerts=8 : Maximum alerts to send per run}
        {--dry-run : Print messages without sending LINE notifications or writing cache}';

    protected $description = 'Send LINE notifications for 台指期差值、乖離率、開盤差值 and 4H MA5 alerts.';

    public function handle(
        TwFuturesHourlyPriceController $controller,
        LinePushService $linePush,
    ): int {
        if (! $this->notificationsEnabled()) {
            $this->line('台指期 LINE 通知未啟用。');

            return self::SUCCESS;
        }

        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $lookbackMinutes = max(1, min(1440, (int) $this->option('lookback-minutes')));
        $maxAlerts = max(1, min(50, (int) $this->option('max-alerts')));
        $now = CarbonImmutable::now($timezone);
        $payload = $controller->lineAlertPayload();
        $alerts = $this->alertsFromPayload($payload, $now, $lookbackMinutes);
        $alerts = array_slice($alerts, -$maxAlerts);

        if ($alerts === []) {
            $this->line('沒有新的台指期 LINE 通知。');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $target = trim((string) $this->option('target'));

        foreach ($alerts as $alert) {
            $cacheKey = 'tw_futures_line_alert:' . $alert['key'];
            if (Cache::has($cacheKey)) {
                $skipped++;
                continue;
            }

            if ($this->option('dry-run')) {
                $this->line($alert['message']);
            } else {
                $linePush->pushText($alert['message'], $target !== '' ? $target : null);
                Cache::put($cacheKey, $now->toIso8601String(), $now->addDays(self::CACHE_TTL_DAYS));
            }

            $sent++;
        }

        $this->info(sprintf(
            '台指期 LINE 通知完成：alerts=%d sent=%d skipped=%d dry_run=%s',
            count($alerts),
            $sent,
            $skipped,
            $this->option('dry-run') ? 'yes' : 'no',
        ));

        return self::SUCCESS;
    }

    private function notificationsEnabled(): bool
    {
        return filter_var(config('line.taiex_futures_notify_enabled', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{key: string, time: int, message: string}>
     */
    private function alertsFromPayload(array $payload, CarbonImmutable $now, int $lookbackMinutes): array
    {
        $minTimestamp = $now->subMinutes($lookbackMinutes)->timestamp;
        $alerts = [];

        foreach ($payload['chartRows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $alert = $this->priceAlert($row, $minTimestamp);
            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        foreach ($payload['fourHourMa5Rows'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $alert = $this->fourHourMa5Alert($row, $minTimestamp);
            if ($alert !== null) {
                $alerts[] = $alert;
            }
        }

        usort($alerts, fn (array $a, array $b): int => $a['time'] <=> $b['time']);

        return $alerts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{key: string, time: int, message: string}|null
     */
    private function priceAlert(array $row, int $minTimestamp): ?array
    {
        $time = (int) ($row['alertTime'] ?? $row['time'] ?? 0);
        if ($time < $minTimestamp) {
            return null;
        }

        $conditions = [];
        $conditionKeys = [];
        $gap = $this->numeric($row['gap'] ?? null);
        if ($gap !== null && abs($gap) >= self::GAP_THRESHOLD) {
            $conditions[] = sprintf(
                '差值 %s點 %s',
                $this->formatNumber($gap, 0, true),
                $this->thresholdText($gap, self::GAP_THRESHOLD, '點'),
            );
            $conditionKeys[] = 'gap';
        }

        if (($row['isSessionOpen'] ?? false) && $gap !== null && abs($gap) >= self::OPENING_GAP_THRESHOLD) {
            $conditions[] = sprintf(
                '開盤差值 %s點 %s',
                $this->formatNumber($gap, 0, true),
                $this->thresholdText($gap, self::OPENING_GAP_THRESHOLD, '點'),
            );
            $conditionKeys[] = 'opening-gap';
        }

        $biasRate = $this->numeric($row['biasRate'] ?? null);
        if ($biasRate !== null && abs($biasRate) >= self::BIAS_RATE_THRESHOLD) {
            $conditions[] = sprintf(
                '乖離率 %s %s',
                $this->formatPercent($biasRate),
                $this->thresholdText($biasRate * 100, self::BIAS_RATE_THRESHOLD * 100, '%'),
            );
            $conditionKeys[] = 'bias-rate';
        }

        if ($conditions === []) {
            return null;
        }

        $lines = [
            '台指期通知 ' . (string) ($row['alertLocalTime'] ?? $row['localTime'] ?? ''),
            ...$conditions,
            sprintf(
                '現價 %s / 日MA5 %s / 15K %s',
                $this->formatOptionalNumber($row['close'] ?? null, 0),
                $this->formatOptionalNumber($row['dailyMa5'] ?? null, 0),
                $this->formatOptionalNumber($row['movingAverage'] ?? null, 0),
            ),
            $this->dashboardUrl(),
        ];

        return [
            'key' => 'price:' . $time . ':' . substr(sha1(implode('|', $conditionKeys)), 0, 10),
            'time' => $time,
            'message' => implode("\n", $lines),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{key: string, time: int, message: string}|null
     */
    private function fourHourMa5Alert(array $row, int $minTimestamp): ?array
    {
        $time = (int) ($row['time'] ?? 0);
        if ($time < $minTimestamp) {
            return null;
        }

        $diff = $this->numeric($row['fourHourMa5Diff'] ?? null);
        if ($diff === null || abs($diff) < 0.000001) {
            return null;
        }

        $direction = $diff > 0 ? '高於' : '低於';
        $lines = [
            '台指期 4H MA5 通知 ' . (string) ($row['localTime'] ?? ''),
            sprintf(
                '目前價格 %s %s 4H MA5 %s',
                $this->formatOptionalNumber($row['close'] ?? null, 0),
                $direction,
                $this->formatOptionalNumber($row['fourHourMa5'] ?? null, 0),
            ),
            '價差 ' . $this->formatNumber($diff, 0, true) . '點',
            $this->dashboardUrl(),
        ];

        return [
            'key' => 'four-hour-ma5:' . $time,
            'time' => $time,
            'message' => implode("\n", $lines),
        ];
    }

    private function thresholdText(float $value, float $threshold, string $unit): string
    {
        $boundary = $value >= 0 ? $threshold : -$threshold;

        return sprintf(
            '%s %s%s',
            $value >= 0 ? '高於' : '低於',
            $this->formatNumber($boundary, $unit === '%' ? 2 : 0, true),
            $unit,
        );
    }

    private function formatPercent(float $value): string
    {
        return $this->formatNumber($value * 100, 2, true) . '%';
    }

    private function formatOptionalNumber(mixed $value, int $decimals): string
    {
        $number = $this->numeric($value);

        return $number === null ? '--' : $this->formatNumber($number, $decimals);
    }

    private function formatNumber(float $value, int $decimals = 0, bool $signed = false): string
    {
        return ($signed && $value >= 0 ? '+' : '') . number_format($value, $decimals);
    }

    private function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private function dashboardUrl(): string
    {
        return rtrim((string) config('app.url'), '/') . '/tw-stock/taiex-futures-kline';
    }
}
