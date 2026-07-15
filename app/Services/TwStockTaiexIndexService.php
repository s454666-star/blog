<?php

namespace App\Services;

use App\Models\TwStockInstitutionalFlow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TwStockTaiexIndexService
{
    private const TWSE_CHART_URL = 'https://mis.twse.com.tw/stock/api/getChartOhlcStatis.jsp';

    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%5ETWII';

    private const FEED_CACHE_SECONDS = 10;

    private const HISTORY_CACHE_MINUTES = 10;

    private const HISTORY_START_MONTH = 7;

    private const HISTORY_START_DAY = 1;

    private const DAILY_BAR_LIMIT = 180;

    /**
     * @return array<string, int|null>
     */
    public static function intervals(): array
    {
        return [
            '1m' => 1,
            '5m' => 5,
            '15m' => 15,
            '1d' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(string $interval): array
    {
        $intervals = self::intervals();
        if (! array_key_exists($interval, $intervals)) {
            throw new RuntimeException('不支援的 K 線週期。');
        }

        $feed = $this->fetchTwseFeed();
        $quote = $this->quoteFromFeed($feed);
        $bars = $interval === '1d'
            ? $this->dailyBars($quote)
            : $this->intradayBars($feed, $quote, (int) $intervals[$interval]);

        return [
            'symbol' => 'TAIEX',
            'name' => '發行量加權股價指數',
            'interval' => $interval,
            'intervalLabel' => match ($interval) {
                '1m' => '1 分 K',
                '5m' => '5 分 K',
                '15m' => '15 分 K',
                default => '日 K',
            },
            'refreshSeconds' => 15,
            'source' => $interval === '1d'
                ? '臺灣證券交易所（TWSE）'
                : 'TWSE 即時指數／Yahoo Finance 歷史分 K',
            'sourceNote' => $interval === '1d'
                ? '日 K 使用 TWSE 每日開高低收；當日資料以即時指數更新。'
                : '7/1 起歷史分 K 使用 Yahoo Finance 的 TAIEX 分鐘 OHLC；當日以 TWSE 分時指數補齊，最新指數每 15 秒更新。',
            'refreshedAt' => now('Asia/Taipei')->format('Y-m-d H:i:s'),
            'market' => $this->marketState($quote),
            'quote' => $quote,
            'bars' => $bars,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTwseFeed(): array
    {
        // The chart must remain usable on development machines that do not
        // have the production Redis extension. A short-lived file cache is
        // sufficient here because this application runs on a single host.
        return Cache::store('file')->remember(
            'tw-stock:taiex-index:twse-feed:v1',
            now()->addSeconds(self::FEED_CACHE_SECONDS),
            function (): array {
                $payload = Http::withHeaders([
                    'Accept' => 'application/json,text/plain,*/*',
                    'Referer' => 'https://mis.twse.com.tw/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                    ->timeout(15)
                    ->retry(1, 250)
                    ->get(self::TWSE_CHART_URL, [
                        'ex' => 'tse',
                        'ch' => 't00.tw',
                    ])
                    ->throw()
                    ->json();

                if (! is_array($payload) || (string) ($payload['rtcode'] ?? '') !== '0000') {
                    throw new RuntimeException('TWSE 加權指數分時資料回應異常。');
                }

                return $payload;
            },
        );
    }

    /**
     * @param array<string, mixed> $feed
     * @return array<string, mixed>
     */
    private function quoteFromFeed(array $feed): array
    {
        $info = is_array($feed['infoArray'][0] ?? null) ? $feed['infoArray'][0] : [];
        $latest = $this->number($info['z'] ?? $feed['lastIndex'] ?? null);
        $previousClose = $this->number($info['y'] ?? null);
        $change = $latest !== null && $previousClose !== null ? $latest - $previousClose : null;
        $date = preg_replace('/\D/', '', (string) ($info['d'] ?? '')) ?: null;
        $time = trim((string) ($info['t'] ?? '')) ?: null;
        $quotedAt = $this->quotedAt($date, $time);

        return [
            'date' => $date !== null && strlen($date) === 8
                ? substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2)
                : null,
            'time' => $time,
            'quotedAt' => $quotedAt?->format('Y-m-d H:i:s'),
            'quotedAtUnix' => $quotedAt?->timestamp,
            'latest' => $this->rounded($latest),
            'open' => $this->rounded($this->number($info['o'] ?? null)),
            'high' => $this->rounded($this->number($info['h'] ?? null)),
            'low' => $this->rounded($this->number($info['l'] ?? null)),
            'previousClose' => $this->rounded($previousClose),
            'change' => $this->rounded($change),
            'changeRate' => $change !== null && $previousClose !== null && $previousClose != 0.0
                ? round($change / $previousClose * 100, 2)
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>
     */
    private function marketState(array $quote): array
    {
        $now = CarbonImmutable::now('Asia/Taipei');
        $quoteDate = (string) ($quote['date'] ?? '');
        $isToday = $quoteDate === $now->toDateString();
        $isOpen = $isToday
            && $now->isWeekday()
            && $now->format('H:i:s') >= '09:00:00'
            && $now->format('H:i:s') <= '13:30:30';

        $label = '非交易時段';
        if ($isOpen) {
            $label = '盤中';
        } elseif ($isToday && $now->format('H:i:s') > '13:30:30') {
            $label = '已收盤';
        }

        return [
            'isOpen' => $isOpen,
            'label' => $label,
        ];
    }

    /**
     * @param array<string, mixed> $feed
     * @param array<string, mixed> $quote
     * @return list<array<string, mixed>>
     */
    private function intradayBars(array $feed, array $quote, int $intervalMinutes): array
    {
        $samples = $this->historicalMinuteSamples();
        foreach ($this->currentMinuteSamples($feed, $quote) as $timestamp => $sample) {
            $samples[$timestamp] = $sample;
        }

        ksort($samples);

        $seconds = max(1, $intervalMinutes) * 60;
        $bars = [];
        foreach ($samples as $sample) {
            $bucketTime = intdiv((int) $sample['time'], $seconds) * $seconds;
            if (! isset($bars[$bucketTime])) {
                $bars[$bucketTime] = [
                    'time' => $bucketTime,
                    'localTime' => CarbonImmutable::createFromTimestamp($bucketTime, 'Asia/Taipei')->format('Y-m-d H:i'),
                    'open' => (float) $sample['open'],
                    'high' => (float) $sample['high'],
                    'low' => (float) $sample['low'],
                    'close' => (float) $sample['close'],
                    'volume' => (int) $sample['volume'],
                ];
                continue;
            }

            $bars[$bucketTime]['high'] = max((float) $bars[$bucketTime]['high'], (float) $sample['high']);
            $bars[$bucketTime]['low'] = min((float) $bars[$bucketTime]['low'], (float) $sample['low']);
            $bars[$bucketTime]['close'] = (float) $sample['close'];
            $bars[$bucketTime]['volume'] += (int) $sample['volume'];
        }

        return array_values(array_map(fn (array $bar): array => $this->roundBar($bar), $bars));
    }

    /**
     * @param array<string, mixed> $feed
     * @param array<string, mixed> $quote
     * @return array<int, array<string, int|float>>
     */
    private function currentMinuteSamples(array $feed, array $quote): array
    {
        $samples = [];
        $previousClose = $this->number($quote['open'] ?? null);

        foreach (($feed['ohlcArray'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $close = $this->number($row['c'] ?? null);
            $rawTimestamp = $row['t'] ?? null;
            if ($close === null || ! is_numeric($rawTimestamp)) {
                continue;
            }

            $timestamp = (int) $rawTimestamp;
            if ($timestamp > 10_000_000_000) {
                $timestamp = intdiv($timestamp, 1000);
            }

            $open = $previousClose ?? $close;
            $samples[$timestamp] = [
                'time' => $timestamp,
                'open' => $open,
                'high' => max($open, $close),
                'low' => min($open, $close),
                'close' => $close,
                'volume' => max(0, (int) ($row['s'] ?? 0)),
            ];
            $previousClose = $close;
        }

        $quoteTimestamp = $quote['quotedAtUnix'] ?? null;
        $quoteLatest = $this->number($quote['latest'] ?? null);
        if (is_numeric($quoteTimestamp) && $quoteLatest !== null) {
            $timestamp = intdiv((int) $quoteTimestamp, 60) * 60;
            if (isset($samples[$timestamp])) {
                $samples[$timestamp]['high'] = max((float) $samples[$timestamp]['high'], $quoteLatest);
                $samples[$timestamp]['low'] = min((float) $samples[$timestamp]['low'], $quoteLatest);
                $samples[$timestamp]['close'] = $quoteLatest;
            } else {
                $open = $previousClose ?? $quoteLatest;
                $samples[$timestamp] = [
                    'time' => $timestamp,
                    'open' => $open,
                    'high' => max($open, $quoteLatest),
                    'low' => min($open, $quoteLatest),
                    'close' => $quoteLatest,
                    'volume' => 0,
                ];
            }
        }

        ksort($samples);

        return $samples;
    }

    /**
     * @return array<int, array<string, int|float>>
     */
    private function historicalMinuteSamples(): array
    {
        $now = CarbonImmutable::now('Asia/Taipei');
        $start = CarbonImmutable::create(
            $now->year,
            self::HISTORY_START_MONTH,
            self::HISTORY_START_DAY,
            0,
            0,
            0,
            'Asia/Taipei',
        );
        $end = $now->startOfDay();
        if ($end->lessThanOrEqualTo($start)) {
            return [];
        }

        $cacheKey = sprintf(
            'tw-stock:taiex-index:yahoo-minute:%s:%s:v1',
            $start->toDateString(),
            $end->subDay()->toDateString(),
        );

        return Cache::store('file')->remember(
            $cacheKey,
            now()->addMinutes(self::HISTORY_CACHE_MINUTES),
            fn (): array => $this->downloadHistoricalMinuteSamples($start, $end),
        );
    }

    /**
     * @return array<int, array<string, int|float>>
     */
    private function downloadHistoricalMinuteSamples(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $samples = [];
        $cursor = $start;

        while ($cursor->lessThan($end)) {
            $chunkEnd = $cursor->addDays(7);
            if ($chunkEnd->greaterThan($end)) {
                $chunkEnd = $end;
            }

            try {
                $payload = Http::withHeaders([
                    'Accept' => 'application/json,text/plain,*/*',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                    ->timeout(20)
                    ->retry(1, 250)
                    ->get(self::YAHOO_CHART_URL, [
                        'period1' => $cursor->utc()->timestamp,
                        'period2' => $chunkEnd->utc()->timestamp,
                        'interval' => '1m',
                        'includePrePost' => 'false',
                        'events' => 'div,splits',
                    ])
                    ->throw()
                    ->json();
            } catch (Throwable) {
                $cursor = $chunkEnd;
                continue;
            }

            $result = is_array($payload['chart']['result'][0] ?? null)
                ? $payload['chart']['result'][0]
                : [];
            $timestamps = is_array($result['timestamp'] ?? null) ? $result['timestamp'] : [];
            $quote = is_array($result['indicators']['quote'][0] ?? null)
                ? $result['indicators']['quote'][0]
                : [];

            foreach ($timestamps as $index => $rawTimestamp) {
                if (! is_numeric($rawTimestamp)) {
                    continue;
                }

                $timestamp = (int) $rawTimestamp;
                $open = $this->number($quote['open'][$index] ?? null);
                $high = $this->number($quote['high'][$index] ?? null);
                $low = $this->number($quote['low'][$index] ?? null);
                $close = $this->number($quote['close'][$index] ?? null);
                if ($open === null || $high === null || $low === null || $close === null) {
                    continue;
                }

                $samples[$timestamp] = [
                    'time' => $timestamp,
                    'open' => $open,
                    'high' => $high,
                    'low' => $low,
                    'close' => $close,
                    'volume' => max(0, (int) ($quote['volume'][$index] ?? 0)),
                ];
            }

            $cursor = $chunkEnd;
        }

        ksort($samples);

        return $samples;
    }

    /**
     * @param array<string, mixed> $quote
     * @return list<array<string, mixed>>
     */
    private function dailyBars(array $quote): array
    {
        $bars = TwStockInstitutionalFlow::query()
            ->whereNotNull('taiex_open_index')
            ->whereNotNull('taiex_high_index')
            ->whereNotNull('taiex_low_index')
            ->whereNotNull('taiex_close_index')
            ->orderByDesc('trade_date')
            ->limit(self::DAILY_BAR_LIMIT)
            ->get([
                'trade_date',
                'taiex_open_index',
                'taiex_high_index',
                'taiex_low_index',
                'taiex_close_index',
            ])
            ->reverse()
            ->values()
            ->map(function (TwStockInstitutionalFlow $row): array {
                $date = $row->trade_date->toDateString();

                return [
                    'time' => CarbonImmutable::parse($date . ' 13:30:00', 'Asia/Taipei')->timestamp,
                    'localTime' => $date,
                    'open' => (float) $row->taiex_open_index,
                    'high' => (float) $row->taiex_high_index,
                    'low' => (float) $row->taiex_low_index,
                    'close' => (float) $row->taiex_close_index,
                    'volume' => 0,
                ];
            })
            ->all();

        $quoteDate = (string) ($quote['date'] ?? '');
        $liveBar = $this->liveDailyBar($quoteDate, $quote);
        if ($liveBar !== null) {
            $existingIndex = null;
            foreach ($bars as $index => $bar) {
                if ((string) $bar['localTime'] === $quoteDate) {
                    $existingIndex = $index;
                    break;
                }
            }

            if ($existingIndex === null) {
                $bars[] = $liveBar;
            } else {
                $bars[$existingIndex] = $liveBar;
            }
        }

        return array_values(array_map(fn (array $bar): array => $this->roundBar($bar), array_slice($bars, -self::DAILY_BAR_LIMIT)));
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>|null
     */
    private function liveDailyBar(string $date, array $quote): ?array
    {
        $open = $this->number($quote['open'] ?? null);
        $high = $this->number($quote['high'] ?? null);
        $low = $this->number($quote['low'] ?? null);
        $close = $this->number($quote['latest'] ?? null);
        if ($date === '' || $open === null || $high === null || $low === null || $close === null) {
            return null;
        }

        try {
            $time = CarbonImmutable::parse($date . ' 13:30:00', 'Asia/Taipei')->timestamp;
        } catch (Throwable) {
            return null;
        }

        return [
            'time' => $time,
            'localTime' => $date,
            'open' => $open,
            'high' => $high,
            'low' => $low,
            'close' => $close,
            'volume' => 0,
        ];
    }

    private function quotedAt(?string $date, ?string $time): ?CarbonImmutable
    {
        if ($date === null || strlen($date) !== 8 || $time === null) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Ymd H:i:s', $date . ' ' . $time, 'Asia/Taipei');
        } catch (Throwable) {
            return null;
        }
    }

    private function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function rounded(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }

    /**
     * @param array<string, mixed> $bar
     * @return array<string, mixed>
     */
    private function roundBar(array $bar): array
    {
        foreach (['open', 'high', 'low', 'close'] as $field) {
            $bar[$field] = round((float) $bar[$field], 2);
        }

        return $bar;
    }
}
