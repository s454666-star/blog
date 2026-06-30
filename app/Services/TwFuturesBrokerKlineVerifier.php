<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TwFuturesBrokerKlineVerifier
{
    public const SOURCE_YUANTA = 'Yuanta Spark API futures K-line';

    /**
     * @param list<array<string, mixed>> $dailyRows
     * @return array<string, array<string, mixed>>
     */
    public function dailyRowsByDate(array $dailyRows, ?CarbonImmutable $now = null): array
    {
        if (! (bool) config('yuanta.futures_kline_enabled', false) || ! $this->canUseBrokerSource($now)) {
            return [];
        }

        $dates = collect($dailyRows)
            ->map(fn (array $row): ?string => $this->dateString($row['trade_date'] ?? null))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($dates->isEmpty()) {
            return [];
        }

        $from = (string) $dates->first();
        $to = (string) $dates->last();
        $rows = $this->queryRowsWithCache($from, $to, 'daily');

        return collect($rows)
            ->keyBy(fn (array $row): string => (string) $row['date'])
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $priceRows
     * @return array<string, array<string, mixed>>
     */
    public function klineRowsByStartedAt(array $priceRows, string $interval, ?CarbonImmutable $now = null): array
    {
        if (! (bool) config('yuanta.futures_kline_enabled', false) || ! $this->canUseBrokerSource($now)) {
            return [];
        }

        $dateTimes = collect($priceRows)
            ->map(fn (array $row): ?string => $this->dateTimeString($row['started_at'] ?? null))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($dateTimes->isEmpty()) {
            return [];
        }

        $from = CarbonImmutable::parse((string) $dateTimes->first(), 'Asia/Taipei')->toDateString();
        $to = CarbonImmutable::parse((string) $dateTimes->last(), 'Asia/Taipei')->toDateString();
        $rows = $this->queryRowsWithCache($from, $to, $interval);

        return collect($rows)
            ->keyBy(fn (array $row): string => (string) $row['started_at'])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryRowsWithCache(string $from, string $to, string $interval): array
    {
        $symbol = (string) config('yuanta.futures_kline_symbol', 'TXFPM1');
        $normalizedInterval = $this->normalizeInterval($interval);
        $cacheKey = 'tw-futures:broker-kline:yuanta:v2:' . sha1($symbol . '|' . $normalizedInterval . '|' . $from . '|' . $to);
        $ttl = max(30, (int) config('yuanta.futures_kline_cache_seconds', 600));

        try {
            /** @var list<array<string, mixed>> $rows */
            $rows = Cache::remember(
                $cacheKey,
                now()->addSeconds($ttl),
                fn (): array => $this->queryYuantaRows($from, $to, $symbol, $normalizedInterval),
            );

            return $rows;
        } catch (Throwable $exception) {
            Log::warning('Yuanta futures K-line verification unavailable.', [
                'message' => $this->redactSecrets($exception->getMessage()),
                'from' => $from,
                'to' => $to,
                'symbol' => $symbol,
                'interval' => $normalizedInterval,
            ]);

            return [];
        }
    }

    public function canUseBrokerSource(?CarbonImmutable $now = null): bool
    {
        $timezone = (string) config('yuanta.timezone', config('app.timezone', 'Asia/Taipei'));
        $now ??= CarbonImmutable::now($timezone);
        $now = $now->setTimezone($timezone);
        $date = $now->toDateString();
        $excludedStart = CarbonImmutable::parse(
            $date . ' ' . config('yuanta.futures_kline_excluded_start', '09:00'),
            $timezone,
        );
        $excludedEnd = CarbonImmutable::parse(
            $date . ' ' . config('yuanta.futures_kline_excluded_end', '13:30'),
            $timezone,
        );

        return ! ($now->greaterThanOrEqualTo($excludedStart) && $now->lessThan($excludedEnd));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryYuantaRows(string $from, string $to, string $symbol, string $interval): array
    {
        $script = (string) config('yuanta.futures_kline_script', base_path('scripts/yuanta_futures_kline_query.py'));
        $responsePayload = $this->runYuantaScript($script, [
            ...$this->yuantaProcessEnvironment(),
            'YUANTA_FUTURES_KLINE_SYMBOL' => $symbol,
            'YUANTA_FUTURES_KLINE_INTERVAL' => $interval,
            'YUANTA_FUTURES_KLINE_MARKET' => 'TAIFEX',
        ], [
            '--from',
            $from,
            '--to',
            $to,
            '--symbol',
            $symbol,
            '--interval',
            $interval,
            '--market',
            'TAIFEX',
        ]);

        $rows = [];
        foreach (($responsePayload['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->dateString($row['date'] ?? $row['timestamp'] ?? null);
            $close = $this->numberOrNull($row['close'] ?? null);
            if ($date === null || $close === null) {
                continue;
            }

            $startedAt = $this->dateTimeString($row['timestamp'] ?? null) ?? $date . ' 00:00:00';
            $rowPayload = [
                'source' => self::SOURCE_YUANTA,
                'provider' => 'yuanta',
                'date' => $date,
                'started_at' => $startedAt,
                'interval' => $interval,
                'symbol' => (string) ($responsePayload['symbol'] ?? $symbol),
                'market' => (string) ($responsePayload['market'] ?? 'TAIFEX'),
                'timestamp' => (string) ($row['timestamp'] ?? $date),
                'open_price' => $this->numberOrNull($row['open'] ?? null),
                'high_price' => $this->numberOrNull($row['high'] ?? null),
                'low_price' => $this->numberOrNull($row['low'] ?? null),
                'close_price' => $close,
                'volume_contracts' => $this->integerOrNull($row['volume'] ?? null),
            ];
            $rows[$interval === 'daily' ? $date : $startedAt] = $rowPayload;
        }

        return array_values($rows);
    }

    /**
     * @return array<string, string>
     */
    private function yuantaProcessEnvironment(): array
    {
        return array_map(fn (mixed $value): string => (string) $value, [
            'YUANTA_DOTNET_ROOT' => config('yuanta.dotnet_root'),
            'YUANTA_SDK_PATH' => config('yuanta.sdk_path'),
            'YUANTA_API_ENVIRONMENT' => config('yuanta.environment'),
            'YUANTA_ACCOUNT' => config('yuanta.account'),
            'YUANTA_PASSWORD' => config('yuanta.password'),
            'YUANTA_PFX_PATH' => config('yuanta.pfx_path'),
            'YUANTA_PFX_PASSWORD' => config('yuanta.pfx_password'),
        ]);
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $arguments
     * @return array<string, mixed>
     */
    private function runYuantaScript(string $script, array $env, array $arguments): array
    {
        if (! is_file($script)) {
            throw new RuntimeException('Yuanta futures K-line script is missing.');
        }

        $processEnv = array_filter($env, fn (string $value): bool => $value !== '');
        $process = new Process(
            [(string) config('yuanta.python_bin', 'python'), $script, ...$arguments],
            base_path(),
            $processEnv,
            null,
            max(20, (int) config('yuanta.futures_kline_timeout_seconds', 70)),
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Unknown Yuanta futures K-line query failure.';
            throw new RuntimeException($this->redactSecrets($error));
        }

        $payload = json_decode($process->getOutput(), true);
        if (! is_array($payload)) {
            throw new RuntimeException('Yuanta futures K-line query returned an unexpected payload.');
        }

        return $payload;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        try {
            return CarbonImmutable::parse((string) $value, 'Asia/Taipei')->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function dateTimeString(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('Asia/Taipei')->format('Y-m-d H:i:s');
        }

        try {
            return CarbonImmutable::parse((string) $value, 'Asia/Taipei')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeInterval(string $interval): string
    {
        $interval = strtolower(trim($interval));

        return match ($interval) {
            '1', '1m' => '1',
            '5', '5m' => '5',
            '15', '15m' => '15',
            '30', '30m' => '30',
            '60', '60m' => '60',
            'day', 'd', 'daily' => 'daily',
            default => 'daily',
        };
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function redactSecrets(string $message): string
    {
        foreach ([
            config('yuanta.account'),
            config('yuanta.password'),
            config('yuanta.pfx_password'),
        ] as $secret) {
            $secret = (string) $secret;
            if ($secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return $message;
    }
}
