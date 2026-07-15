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

    private const FINMIND_DATA_URL = 'https://api.finmindtrade.com/api/v4/data';

    private const CNYES_CHART_URL = 'https://ws.api.cnyes.com/ws/api/v1/charting/history';

    private const TWSE_DAILY_MARKET_URL = 'https://www.twse.com.tw/exchangeReport/FMTQIK';

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
                ? '臺灣證券交易所（TWSE）／鉅亨網即時成交額'
                : 'TWSE 即時指數／Yahoo Finance 歷史分 K／FinMind TWSE 成交額',
            'sourceNote' => $interval === '1d'
                ? '日 K 使用 TWSE 每日開高低收與成交金額；當日價格及成交額以即時資料更新。'
                : '7/1 起歷史分 K 使用 Yahoo Finance 分鐘 OHLC，成交額由 FinMind 提供的 TWSE 5 秒統計換算；當日以 TWSE 即時指數及鉅亨網分鐘成交額補齊，每 15 秒更新。',
            'volumeLabel' => '成交額',
            'volumeUnit' => 'TWD',
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
        $currentSamples = $this->currentMinuteSamples($feed, $quote);
        $currentTurnover = $this->currentTurnoverSnapshot((string) ($quote['date'] ?? ''));
        foreach ($currentSamples as $timestamp => $sample) {
            if (isset($currentTurnover['minutes'][$timestamp])) {
                $sample['volume'] = $currentTurnover['minutes'][$timestamp];
            }

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

        $samples = Cache::store('file')->remember(
            $cacheKey,
            now()->addMinutes(self::HISTORY_CACHE_MINUTES),
            fn (): array => $this->downloadHistoricalMinuteSamples($start, $end),
        );

        $turnoverByMinute = $this->historicalMinuteTurnover($start, $end);
        foreach ($samples as $timestamp => &$sample) {
            $sample['volume'] = $turnoverByMinute[$timestamp] ?? 0;
        }
        unset($sample);

        // TWSE books the closing auction at 13:30, while Yahoo's last
        // historical index candle is normally stamped 13:29. Preserve that
        // real closing turnover on the final visible candle.
        foreach ($turnoverByMinute as $timestamp => $turnover) {
            if ($turnover <= 0 || isset($samples[$timestamp])) {
                continue;
            }

            $time = CarbonImmutable::createFromTimestamp($timestamp, 'Asia/Taipei');
            $previousMinute = $timestamp - 60;
            if ($time->format('H:i') === '13:30' && isset($samples[$previousMinute])) {
                $samples[$previousMinute]['volume'] += $turnover;
            }
        }

        return $samples;
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
                    // Yahoo does not provide TAIEX historical minute volume.
                    // It is filled from TWSE market turnover after OHLC download.
                    'volume' => 0,
                ];
            }

            $cursor = $chunkEnd;
        }

        ksort($samples);

        return $samples;
    }

    /**
     * Convert TWSE's cumulative 5-second market turnover into per-minute TWD.
     * FinMind republishes this TWSE historical dataset and accepts date queries.
     *
     * @return array<int, int>
     */
    private function historicalMinuteTurnover(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $turnover = [];

        for ($date = $start; $date->lessThan($end); $date = $date->addDay()) {
            if ($date->isWeekend()) {
                continue;
            }

            $dateString = $date->toDateString();
            try {
                $daily = Cache::store('file')->remember(
                    'tw-stock:taiex-index:finmind-turnover:' . $dateString . ':v1',
                    now()->addDays(30),
                    fn (): array => $this->downloadHistoricalMinuteTurnover($dateString),
                );
            } catch (Throwable) {
                // Price candles remain available when the secondary turnover
                // source is temporarily unavailable. A failed response is not
                // cached, so the next 15-second refresh can retry.
                $daily = [];
            }

            foreach ($daily as $timestamp => $value) {
                $turnover[(int) $timestamp] = (int) $value;
            }
        }

        ksort($turnover);

        return $turnover;
    }

    /**
     * @return array<int, int>
     */
    private function downloadHistoricalMinuteTurnover(string $date): array
    {
        $payload = Http::withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ])
            ->timeout(20)
            ->retry(1, 250)
            ->get(self::FINMIND_DATA_URL, [
                'dataset' => 'TaiwanStockStatisticsOfOrderBookAndTrade',
                'start_date' => $date,
                'end_date' => $date,
            ])
            ->throw()
            ->json();

        if (! is_array($payload) || (int) ($payload['status'] ?? 0) !== 200) {
            throw new RuntimeException('FinMind TWSE 成交額回應異常。');
        }

        $cumulativeByMinute = [];
        foreach (($payload['data'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $time = substr((string) ($row['Time'] ?? ''), 0, 5);
            $cumulative = $this->number($row['TotalDealMoney'] ?? null);
            if (strlen($time) !== 5 || $cumulative === null) {
                continue;
            }

            try {
                $timestamp = CarbonImmutable::parse($date . ' ' . $time . ':00', 'Asia/Taipei')->timestamp;
            } catch (Throwable) {
                continue;
            }

            $cumulativeByMinute[$timestamp] = max(
                (float) ($cumulativeByMinute[$timestamp] ?? 0),
                $cumulative,
            );
        }

        ksort($cumulativeByMinute);
        $turnover = [];
        $previous = 0.0;
        foreach ($cumulativeByMinute as $timestamp => $cumulative) {
            // TotalDealMoney is published in NT$ millions.
            $turnover[$timestamp] = (int) round(max(0, $cumulative - $previous) * 1_000_000);
            $previous = max($previous, $cumulative);
        }

        return $turnover;
    }

    /**
     * @return array{minutes: array<int, int>, cumulative: int}
     */
    private function currentTurnoverSnapshot(string $date): array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['minutes' => [], 'cumulative' => 0];
        }

        return Cache::store('file')->remember(
            'tw-stock:taiex-index:cnyes-turnover:' . $date . ':v1',
            now()->addSeconds(self::FEED_CACHE_SECONDS),
            function () use ($date): array {
                try {
                    $start = CarbonImmutable::parse($date . ' 00:00:00', 'Asia/Taipei');
                    $end = $start->addDay()->subSecond();
                    if ($date === CarbonImmutable::now('Asia/Taipei')->toDateString()) {
                        $end = CarbonImmutable::now('Asia/Taipei');
                    }
                    $payload = Http::withHeaders([
                        'Accept' => 'application/json',
                        'Referer' => 'https://invest.cnyes.com/',
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    ])
                        ->timeout(15)
                        ->retry(1, 250)
                        ->get(self::CNYES_CHART_URL, [
                            'resolution' => '1',
                            'symbol' => 'TWS:TSE01:INDEX',
                            'from' => $start->timestamp,
                            'to' => $end->timestamp,
                            'quote' => 1,
                        ])
                        ->throw()
                        ->json();
                } catch (Throwable) {
                    return ['minutes' => [], 'cumulative' => 0];
                }

                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
                $timestamps = is_array($data['t'] ?? null) ? $data['t'] : [];
                $volumes = is_array($data['v'] ?? null) ? $data['v'] : [];
                $minutes = [];

                foreach ($timestamps as $index => $rawTimestamp) {
                    $volume = $this->number($volumes[$index] ?? null);
                    if (! is_numeric($rawTimestamp) || $volume === null) {
                        continue;
                    }

                    $timestamp = intdiv((int) $rawTimestamp, 60) * 60;
                    if (CarbonImmutable::createFromTimestamp($timestamp, 'Asia/Taipei')->toDateString() !== $date) {
                        continue;
                    }
                    $minutes[$timestamp] = (int) round(max(0, $volume));
                }

                ksort($minutes);
                $cumulative = $minutes === []
                    ? 0
                    : $this->number($data['quote']['800001'] ?? null);

                return [
                    'minutes' => $minutes,
                    'cumulative' => (int) round(max(0, $cumulative ?? array_sum($minutes))),
                ];
            },
        );
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

        if ($bars !== []) {
            $lastBar = $bars[array_key_last($bars)];
            $turnoverByDate = $this->dailyTurnoverByDate(
                (string) $bars[0]['localTime'],
                (string) ($quote['date'] ?? $lastBar['localTime']),
            );
            foreach ($bars as &$bar) {
                $bar['volume'] = $turnoverByDate[(string) $bar['localTime']] ?? 0;
            }
            unset($bar);
        }

        $quoteDate = (string) ($quote['date'] ?? '');
        $currentTurnover = $this->currentTurnoverSnapshot($quoteDate);
        $liveBar = $this->liveDailyBar($quoteDate, $quote, $currentTurnover['cumulative']);
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
     * @return array<string, int>
     */
    private function dailyTurnoverByDate(string $startDate, string $endDate): array
    {
        try {
            $start = CarbonImmutable::parse($startDate, 'Asia/Taipei')->startOfMonth();
            $end = CarbonImmutable::parse($endDate, 'Asia/Taipei')->startOfMonth();
        } catch (Throwable) {
            return [];
        }

        $turnover = [];
        for ($month = $start; $month->lessThanOrEqualTo($end); $month = $month->addMonth()) {
            $cacheKey = 'tw-stock:taiex-index:twse-daily-turnover:' . $month->format('Y-m') . ':v1';
            $expiresAt = $month->isSameMonth(CarbonImmutable::now('Asia/Taipei'))
                ? now()->addHour()
                : now()->addDays(30);
            try {
                $monthly = Cache::store('file')->remember(
                    $cacheKey,
                    $expiresAt,
                    fn (): array => $this->downloadDailyTurnover($month),
                );
            } catch (Throwable) {
                $monthly = [];
            }

            $turnover = array_replace($turnover, $monthly);
        }

        return $turnover;
    }

    /**
     * @return array<string, int>
     */
    private function downloadDailyTurnover(CarbonImmutable $month): array
    {
        $payload = Http::withHeaders([
            'Accept' => 'application/json',
            'Referer' => 'https://www.twse.com.tw/',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ])
            ->timeout(15)
            ->retry(1, 250)
            ->get(self::TWSE_DAILY_MARKET_URL, [
                'response' => 'json',
                'date' => $month->format('Ymd'),
            ])
            ->throw()
            ->json();

        if (! is_array($payload) || (string) ($payload['stat'] ?? '') !== 'OK') {
            throw new RuntimeException('TWSE 每日成交額回應異常。');
        }

        $turnover = [];
        foreach (($payload['data'] ?? []) as $row) {
            if (! is_array($row) || count($row) < 3) {
                continue;
            }

            $parts = explode('/', (string) $row[0]);
            if (count($parts) !== 3 || ! ctype_digit(implode('', $parts))) {
                continue;
            }

            $date = sprintf('%04d-%02d-%02d', (int) $parts[0] + 1911, (int) $parts[1], (int) $parts[2]);
            $value = str_replace(',', '', (string) $row[2]);
            if (! is_numeric($value)) {
                continue;
            }

            $turnover[$date] = max(0, (int) $value);
        }

        return $turnover;
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>|null
     */
    private function liveDailyBar(string $date, array $quote, int $turnover): ?array
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
            'volume' => max(0, $turnover),
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
