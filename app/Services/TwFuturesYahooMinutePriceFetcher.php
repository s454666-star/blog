<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwFuturesYahooMinutePriceFetcher
{
    public const SOURCE_NAME = 'Yahoo Taiwan Finance 1m self-aggregate';

    private const YAHOO_CHART_URL = 'https://tw.stock.yahoo.com/_td-stock/api/resource/FinanceChartService.ApacLibraCharts;symbols=%5B%22WTX%26%22%5D;type=tick;range=1d;period=1m';

    private const TAIFEX_QUOTE_LIST_URL = 'https://mis.taifex.com.tw/futures/api/getQuoteList';

    private const DEFAULT_EXCHANGE = 'TAIFEX';

    private const DEFAULT_SYMBOL = 'TXF1!';

    private const DEFAULT_SYMBOL_NAME = '台指期近月連續';

    private const YAHOO_SYMBOL = 'WTX&';

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchLatestAggregatedRows(string $interval, string $symbol = self::DEFAULT_SYMBOL): array
    {
        $intervalMinutes = (int) $interval;
        if ($intervalMinutes <= 0) {
            return [];
        }

        try {
            $minutes = $this->fetchYahooMinutes();
            if ($minutes === []) {
                return [];
            }

            $quote = $this->fetchMatchingClosedTaifexQuote((float) $minutes[array_key_last($minutes)]['close']);
            if ($quote === null) {
                return [];
            }

            $window = $this->officialSessionWindow($quote);
            if ($window === null) {
                return [];
            }

            $lastYahooMinuteEndedAt = $minutes[array_key_last($minutes)]['ended_at'];
            if (! $lastYahooMinuteEndedAt instanceof CarbonImmutable) {
                return [];
            }

            $shiftSeconds = $window['end']->timestamp - $lastYahooMinuteEndedAt->timestamp;
            if (abs($shiftSeconds) > 7 * 24 * 60 * 60) {
                return [];
            }

            $rows = $this->aggregateMinutes($minutes, $intervalMinutes, $symbol, $window, $shiftSeconds, $quote);
            if ($rows === [] || ! $this->sessionMatchesQuote($rows, $quote)) {
                return [];
            }

            return array_values($rows);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{ended_at: CarbonImmutable, open: float, high: float, low: float, close: float, volume: int}>
     */
    private function fetchYahooMinutes(): array
    {
        $response = Http::timeout(15)
            ->retry(1, 250)
            ->acceptJson()
            ->get(self::YAHOO_CHART_URL, [
                'bkt' => '',
                'device' => 'desktop',
                'ecma' => 'modern',
                'feature' => 'enableGAMAds,enableGAMEdgeToEdge,enableEvPlayer,enableTxnToken,useCG,useCGV2',
                'intl' => 'tw',
                'lang' => 'zh-Hant-TW',
                'partner' => 'none',
                'region' => 'TW',
                'site' => 'finance',
                'tz' => 'Asia/Taipei',
                'ver' => '1.4.886',
                'returnMeta' => 'true',
            ]);

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            return [];
        }

        $chart = $payload['data'][0]['chart'] ?? null;
        $timestamps = is_array($chart) ? ($chart['timestamp'] ?? null) : null;
        $quote = is_array($chart) ? ($chart['indicators']['quote'][0] ?? null) : null;
        if (! is_array($timestamps) || ! is_array($quote)) {
            return [];
        }

        $minutes = [];
        foreach ($timestamps as $index => $timestamp) {
            if (! is_numeric($timestamp)) {
                continue;
            }

            $close = $this->floatValue($quote['close'][$index] ?? null);
            if ($close === null) {
                continue;
            }

            $minutes[] = [
                'ended_at' => CarbonImmutable::createFromTimestamp((int) $timestamp, 'UTC')->setTimezone('Asia/Taipei'),
                'open' => $this->floatValue($quote['open'][$index] ?? null) ?? $close,
                'high' => $this->floatValue($quote['high'][$index] ?? null) ?? $close,
                'low' => $this->floatValue($quote['low'][$index] ?? null) ?? $close,
                'close' => $close,
                'volume' => (int) round((float) ($quote['volume'][$index] ?? 0)),
            ];
        }

        return $minutes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchMatchingClosedTaifexQuote(float $latestYahooClose): ?array
    {
        foreach (['1', '0'] as $marketType) {
            $quote = $this->fetchTaifexFrontMonthQuote($marketType);
            if ($quote === null || ! $this->isClosedQuote($quote, $marketType)) {
                continue;
            }

            $close = $this->floatValue($quote['CLastPrice'] ?? null);
            if ($close !== null && abs($close - $latestYahooClose) <= 0.01) {
                $quote['market_type'] = $marketType;

                return $quote;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTaifexFrontMonthQuote(string $marketType): ?array
    {
        $response = Http::timeout(10)
            ->retry(1, 250)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'Origin' => 'https://mis.taifex.com.tw',
                'Referer' => $marketType === '1'
                    ? 'https://mis.taifex.com.tw/futures/AfterHoursSession/EquityIndices/FuturesDomestic'
                    : 'https://mis.taifex.com.tw/futures/RegularSession/EquityIndices/FuturesDomestic',
            ])
            ->post(self::TAIFEX_QUOTE_LIST_URL, [
                'MarketType' => $marketType,
                'SymbolType' => 'F',
                'KindID' => '1',
                'CID' => '',
                'ExpireMonth' => '',
                'RowSize' => '全部',
                'PageNo' => '',
                'SortColumn' => '',
                'AscDesc' => 'A',
            ]);

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();
        if (! is_array($payload) || (string) ($payload['RtCode'] ?? '') !== '0') {
            return null;
        }

        $quotes = $payload['RtData']['QuoteList'] ?? null;
        if (! is_array($quotes)) {
            return null;
        }

        foreach ($quotes as $quote) {
            if (! is_array($quote)) {
                continue;
            }

            $symbolId = (string) ($quote['SymbolID'] ?? '');
            if (
                preg_match('/^TXF[A-Z]\d-[FM]$/', $symbolId) === 1
                && $this->floatValue($quote['CLastPrice'] ?? null) !== null
            ) {
                return $quote;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function isClosedQuote(array $quote, string $marketType): bool
    {
        $time = $this->quoteTimeText($quote);
        if ($time === null) {
            return false;
        }

        if ($marketType === '1') {
            return $time >= '04:55' && $time <= '05:05';
        }

        return $time >= '13:40' && $time <= '14:05';
    }

    /**
     * @param array<string, mixed> $quote
     * @return array{start: CarbonImmutable, end: CarbonImmutable, session_type: string}|null
     */
    private function officialSessionWindow(array $quote): ?array
    {
        $date = preg_replace('/\D/', '', (string) ($quote['CDate'] ?? ''));
        if ($date === null || strlen($date) !== 8) {
            return null;
        }

        $marketType = (string) ($quote['market_type'] ?? '');
        $sessionDate = CarbonImmutable::createFromFormat('Ymd', $date, 'Asia/Taipei');
        if (! $sessionDate instanceof CarbonImmutable) {
            return null;
        }

        if ($marketType === '1') {
            return [
                'start' => $sessionDate->setTime(15, 0),
                'end' => $sessionDate->addDay()->setTime(5, 0),
                'session_type' => 'night',
            ];
        }

        return [
            'start' => $sessionDate->setTime(8, 45),
            'end' => $sessionDate->setTime(13, 45),
            'session_type' => 'day',
        ];
    }

    /**
     * @param list<array{ended_at: CarbonImmutable, open: float, high: float, low: float, close: float, volume: int}> $minutes
     * @param array{start: CarbonImmutable, end: CarbonImmutable, session_type: string} $window
     * @param array<string, mixed> $quote
     * @return array<string, array<string, mixed>>
     */
    private function aggregateMinutes(array $minutes, int $intervalMinutes, string $symbol, array $window, int $shiftSeconds, array $quote): array
    {
        $rows = [];
        foreach ($minutes as $minute) {
            $startedAt = $minute['ended_at']
                ->addSeconds($shiftSeconds)
                ->subMinute();

            if ($startedAt->lessThan($window['start']) || $startedAt->greaterThanOrEqualTo($window['end'])) {
                continue;
            }

            $elapsedSeconds = $startedAt->timestamp - $window['start']->timestamp;
            $barStartedAt = $window['start']->addSeconds(intdiv($elapsedSeconds, $intervalMinutes * 60) * $intervalMinutes * 60);
            $key = $barStartedAt->format('Y-m-d H:i:s');

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'exchange' => self::DEFAULT_EXCHANGE,
                    'symbol' => $symbol,
                    'symbol_name' => self::DEFAULT_SYMBOL_NAME,
                    'interval' => (string) $intervalMinutes,
                    'started_at' => $key,
                    'started_at_unix' => $barStartedAt->timestamp,
                    'trade_date' => $this->tradeDate($barStartedAt),
                    'session_type' => $window['session_type'],
                    'open_price' => $this->decimal((float) $minute['open']),
                    'high_price' => $this->decimal((float) $minute['high']),
                    'low_price' => $this->decimal((float) $minute['low']),
                    'close_price' => $this->decimal((float) $minute['close']),
                    'volume_contracts' => 0,
                    'source' => self::SOURCE_NAME,
                    'source_payload' => [
                        'yahoo_symbol' => self::YAHOO_SYMBOL,
                        'interval' => (string) $intervalMinutes,
                        'minute_count' => 0,
                        'timestamp_shift_seconds' => $shiftSeconds,
                        'official_session_start' => $window['start']->format('Y-m-d H:i:s'),
                        'official_session_end' => $window['end']->format('Y-m-d H:i:s'),
                        'validation' => [
                            'status' => 'taifex_session_quote_matched',
                            'primary_source' => self::SOURCE_NAME,
                            'secondary_source' => 'TAIFEX official quote snapshot',
                            'taifex_symbol_id' => (string) ($quote['SymbolID'] ?? ''),
                            'taifex_quote_date' => (string) ($quote['CDate'] ?? ''),
                            'taifex_quote_time' => (string) ($quote['CTime'] ?? ''),
                        ],
                    ],
                    'fetched_at' => now(),
                ];
            }

            $rows[$key]['high_price'] = $this->decimal(max((float) $rows[$key]['high_price'], (float) $minute['high']));
            $rows[$key]['low_price'] = $this->decimal(min((float) $rows[$key]['low_price'], (float) $minute['low']));
            $rows[$key]['close_price'] = $this->decimal((float) $minute['close']);
            $rows[$key]['volume_contracts'] += (int) $minute['volume'];
            $rows[$key]['source_payload']['minute_count']++;
        }

        ksort($rows);

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, mixed> $quote
     */
    private function sessionMatchesQuote(array $rows, array $quote): bool
    {
        if ($rows === []) {
            return false;
        }

        $first = reset($rows);
        $last = end($rows);
        if (! is_array($first) || ! is_array($last)) {
            return false;
        }

        $summary = [
            'open' => (float) $first['open_price'],
            'high' => max(array_map(fn (array $row): float => (float) $row['high_price'], $rows)),
            'low' => min(array_map(fn (array $row): float => (float) $row['low_price'], $rows)),
            'close' => (float) $last['close_price'],
            'volume' => array_sum(array_map(fn (array $row): int => (int) $row['volume_contracts'], $rows)),
        ];

        $official = [
            'open' => $this->floatValue($quote['COpenPrice'] ?? null),
            'high' => $this->floatValue($quote['CHighPrice'] ?? null),
            'low' => $this->floatValue($quote['CLowPrice'] ?? null),
            'close' => $this->floatValue($quote['CLastPrice'] ?? null),
            'volume' => (int) round((float) ($quote['CTotalVolume'] ?? 0)),
        ];

        foreach (['open', 'high', 'low', 'close'] as $field) {
            if ($official[$field] === null || abs((float) $official[$field] - (float) $summary[$field]) > 0.01) {
                return false;
            }
        }

        return abs((int) $official['volume'] - (int) $summary['volume']) <= 0;
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function quoteTimeText(array $quote): ?string
    {
        $time = preg_replace('/\D/', '', (string) ($quote['CTime'] ?? ''));
        if ($time === null || strlen($time) < 4) {
            return null;
        }

        return substr($time, 0, 2) . ':' . substr($time, 2, 2);
    }

    private function tradeDate(CarbonImmutable $startedAt): string
    {
        $candidate = (int) $startedAt->format('H') >= 15
            ? $startedAt->addDay()
            : $startedAt;

        while ($candidate->isWeekend()) {
            $candidate = $candidate->addDay();
        }

        return $candidate->toDateString();
    }

    private function floatValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
