<?php

namespace App\Services;

use App\Models\TwFuturesHourlyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TwFuturesHourlyPriceFetcher
{
    private const TRADINGVIEW_SOCKET_HOST = 'data.tradingview.com';

    private const TRADINGVIEW_SOCKET_PORT = 443;

    private const SOURCE_NAME = 'TradingView chart websocket';

    private const SOURCE_QUOTE_NAME = 'TAIFEX official quote snapshot';

    private const SOURCE_VALIDATED_NAME = 'TradingView chart websocket + Yahoo Taiwan Finance 1m self-aggregate';

    private const SOURCE_YAHOO_VALIDATED_NAME = 'Yahoo Taiwan Finance 1m self-aggregate + TAIFEX official quote snapshot';

    private const TAIFEX_QUOTE_LIST_URL = 'https://mis.taifex.com.tw/futures/api/getQuoteList';

    private const DEFAULT_EXCHANGE = 'TAIFEX';

    private const DEFAULT_SYMBOL = 'TXF1!';

    private const DEFAULT_SYMBOL_NAME = '台指期近月連續';

    private const DEFAULT_TRADINGVIEW_SYMBOL = 'TAIFEX:TXF1!';

    private const DEFAULT_INTERVAL = '60';

    private const SUPPORTED_INTERVALS = ['5', '15', '30', '60'];

    private const SOCKET_TIMEOUT_SECONDS = 30;

    private const MORE_DATA_CHUNK_SIZE = 1000;

    private const MAX_MORE_DATA_REQUESTS = 80;

    private const FRESH_BAR_WAIT_SECONDS = 12;

    private const UPSERT_CHUNK_SIZE = 200;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRows(
        ?string $from = null,
        ?string $to = null,
        string $symbol = self::DEFAULT_SYMBOL,
        string $tradingViewSymbol = self::DEFAULT_TRADINGVIEW_SYMBOL,
        int $bars = 2200,
        string $interval = self::DEFAULT_INTERVAL,
    ): array {
        $interval = $this->normalizeInterval($interval);
        $fromDate = $from !== null && $from !== ''
            ? CarbonImmutable::parse($from, 'Asia/Taipei')->startOfDay()
            : null;
        $toDate = $to !== null && $to !== ''
            ? CarbonImmutable::parse($to, 'Asia/Taipei')->endOfDay()
            : null;

        $payload = $this->fetchTradingViewTimescale($tradingViewSymbol, $interval, $bars, $fromDate);
        $series = $payload['p'][1]['s1']['s'] ?? null;
        if (!is_array($series)) {
            return [];
        }

        $rows = [];
        foreach ($series as $index => $item) {
            if (!is_array($item) || !is_array($item['v'] ?? null) || count($item['v']) < 5) {
                continue;
            }

            $values = $item['v'];
            $startedAtUnix = (int) floor((float) $values[0]);
            $startedAt = CarbonImmutable::createFromTimestamp($startedAtUnix, 'UTC')->setTimezone('Asia/Taipei');
            if ($fromDate !== null && $startedAt->lessThan($fromDate)) {
                continue;
            }

            if ($toDate !== null && $startedAt->greaterThan($toDate)) {
                continue;
            }

            $close = $this->floatValue($values[4] ?? null);
            if ($close === null) {
                continue;
            }

            $rows[] = [
                'exchange' => self::DEFAULT_EXCHANGE,
                'symbol' => $symbol,
                'symbol_name' => self::DEFAULT_SYMBOL_NAME,
                'interval' => $interval,
                'started_at' => $startedAt->format('Y-m-d H:i:s'),
                'started_at_unix' => $startedAtUnix,
                'trade_date' => $this->tradeDate($startedAt),
                'session_type' => $this->sessionType($startedAt),
                'open_price' => $this->decimal($this->floatValue($values[1] ?? null) ?? $close),
                'high_price' => $this->decimal($this->floatValue($values[2] ?? null) ?? $close),
                'low_price' => $this->decimal($this->floatValue($values[3] ?? null) ?? $close),
                'close_price' => $this->decimal($close),
                'volume_contracts' => (int) round((float) ($values[5] ?? 0)),
                'source' => self::SOURCE_NAME,
                'source_payload' => [
                    'tradingview_symbol' => $tradingViewSymbol,
                    'interval' => $interval,
                    'series_index' => (int) ($item['i'] ?? $index),
                ],
                'fetched_at' => now(),
            ];
        }

        $rows = $this->mergeYahooLatestValidation(
            $rows,
            $fromDate,
            $toDate,
            $symbol,
            $interval,
        );

        return $this->appendCurrentSessionOpeningQuoteRow(
            $rows,
            $fromDate,
            $toDate,
            $symbol,
            $interval,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function mergeYahooLatestValidation(
        array $rows,
        ?CarbonImmutable $fromDate,
        ?CarbonImmutable $toDate,
        string $symbol,
        string $interval,
    ): array {
        if ($symbol !== self::DEFAULT_SYMBOL) {
            return $rows;
        }

        $yahooRows = app(TwFuturesYahooMinutePriceFetcher::class)->fetchLatestAggregatedRows($interval, $symbol);
        if ($yahooRows === []) {
            return $rows;
        }

        $rowsByStartedAt = [];
        foreach ($rows as $row) {
            $rowsByStartedAt[(string) $row['started_at']] = $row;
        }

        $minimumMinuteCount = (int) $interval;
        foreach ($yahooRows as $yahooRow) {
            $startedAt = CarbonImmutable::parse((string) $yahooRow['started_at'], 'Asia/Taipei');
            if ($fromDate !== null && $startedAt->lessThan($fromDate)) {
                continue;
            }

            if ($toDate !== null && $startedAt->greaterThan($toDate)) {
                continue;
            }

            if ((int) ($yahooRow['source_payload']['minute_count'] ?? 0) < $minimumMinuteCount) {
                continue;
            }

            $key = (string) $yahooRow['started_at'];
            $tradingViewRow = $rowsByStartedAt[$key] ?? null;
            if ($tradingViewRow === null) {
                $rowsByStartedAt[$key] = $this->withYahooOnlyValidation($yahooRow);
                continue;
            }

            $mismatches = $this->rowMismatches($tradingViewRow, $yahooRow);
            if ($mismatches === []) {
                $rowsByStartedAt[$key] = $this->withMatchedYahooValidation($tradingViewRow, $yahooRow);
                continue;
            }

            $rowsByStartedAt[$key] = $this->withYahooFallbackValidation($tradingViewRow, $yahooRow, $mismatches);
        }

        $mergedRows = array_values($rowsByStartedAt);
        usort(
            $mergedRows,
            fn (array $first, array $second): int => ((int) $first['started_at_unix']) <=> ((int) $second['started_at_unix']),
        );

        return $mergedRows;
    }

    /**
     * @return list<array{field: string, tradingview: int|float, yahoo: int|float}>
     */
    private function rowMismatches(array $tradingViewRow, array $yahooRow): array
    {
        $mismatches = [];
        foreach (['open_price', 'high_price', 'low_price', 'close_price'] as $field) {
            $tradingView = (float) $tradingViewRow[$field];
            $yahoo = (float) $yahooRow[$field];
            if (abs($tradingView - $yahoo) > 0.01) {
                $mismatches[] = [
                    'field' => $field,
                    'tradingview' => $tradingView,
                    'yahoo' => $yahoo,
                ];
            }
        }

        $tradingViewVolume = (int) $tradingViewRow['volume_contracts'];
        $yahooVolume = (int) $yahooRow['volume_contracts'];
        if ($tradingViewVolume !== $yahooVolume) {
            $mismatches[] = [
                'field' => 'volume_contracts',
                'tradingview' => $tradingViewVolume,
                'yahoo' => $yahooVolume,
            ];
        }

        return $mismatches;
    }

    private function withMatchedYahooValidation(array $tradingViewRow, array $yahooRow): array
    {
        $tradingViewRow['source'] = self::SOURCE_VALIDATED_NAME;
        $tradingViewRow['source_payload']['validation'] = [
            'status' => 'matched_yahoo_taifex_session_verified',
            'primary_source' => self::SOURCE_NAME,
            'secondary_source' => TwFuturesYahooMinutePriceFetcher::SOURCE_NAME,
            'mismatches' => [],
            'yahoo' => $this->yahooValidationPayload($yahooRow),
        ];

        return $tradingViewRow;
    }

    private function withYahooMismatchSkipValidation(array $tradingViewRow, array $yahooRow, array $mismatches): array
    {
        $tradingViewRow['skip_upsert'] = true;
        $tradingViewRow['source_payload']['validation'] = [
            'status' => 'tradingview_yahoo_mismatch_skipped_needs_third_source',
            'primary_source' => self::SOURCE_NAME,
            'secondary_source' => TwFuturesYahooMinutePriceFetcher::SOURCE_NAME,
            'mismatches' => $mismatches,
            'yahoo' => $this->yahooValidationPayload($yahooRow),
        ];

        return $tradingViewRow;
    }

    private function withYahooFallbackValidation(array $tradingViewRow, array $yahooRow, array $mismatches): array
    {
        $yahooRow = $this->withYahooOnlyValidation($yahooRow);
        $yahooRow['source_payload']['validation']['status'] = 'yahoo_taifex_session_used_after_tradingview_mismatch';
        $yahooRow['source_payload']['validation']['rejected_source'] = self::SOURCE_NAME;
        $yahooRow['source_payload']['validation']['mismatches'] = $mismatches;
        $yahooRow['source_payload']['validation']['tradingview'] = [
            'open_price' => (float) $tradingViewRow['open_price'],
            'high_price' => (float) $tradingViewRow['high_price'],
            'low_price' => (float) $tradingViewRow['low_price'],
            'close_price' => (float) $tradingViewRow['close_price'],
            'volume_contracts' => (int) $tradingViewRow['volume_contracts'],
        ];

        return $yahooRow;
    }

    private function withYahooOnlyValidation(array $yahooRow): array
    {
        $yahooRow['source'] = self::SOURCE_YAHOO_VALIDATED_NAME;
        $yahooRow['source_payload']['validation']['status'] = 'yahoo_only_taifex_session_verified';
        $yahooRow['source_payload']['validation']['primary_source'] = TwFuturesYahooMinutePriceFetcher::SOURCE_NAME;
        $yahooRow['source_payload']['validation']['secondary_source'] = 'TAIFEX official quote snapshot';

        return $yahooRow;
    }

    /**
     * @return array<string, mixed>
     */
    private function yahooValidationPayload(array $yahooRow): array
    {
        return [
            'open_price' => (float) $yahooRow['open_price'],
            'high_price' => (float) $yahooRow['high_price'],
            'low_price' => (float) $yahooRow['low_price'],
            'close_price' => (float) $yahooRow['close_price'],
            'volume_contracts' => (int) $yahooRow['volume_contracts'],
            'minute_count' => (int) ($yahooRow['source_payload']['minute_count'] ?? 0),
            'validation' => $yahooRow['source_payload']['validation'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function upsertRows(array $rows): int
    {
        $this->deleteSkippedRows($rows);

        $rows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! (bool) ($row['skip_upsert'] ?? false),
        ));

        if ($rows === []) {
            return 0;
        }

        $now = now();
        $payloads = array_map(function (array $row) use ($now): array {
            unset($row['skip_upsert']);
            $row['source_payload'] = json_encode($row['source_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $rows);

        foreach (array_chunk($payloads, self::UPSERT_CHUNK_SIZE) as $chunk) {
            TwFuturesHourlyPrice::query()->upsert(
                $chunk,
                ['exchange', 'symbol', 'interval', 'started_at'],
                [
                    'symbol_name',
                    'started_at_unix',
                    'trade_date',
                    'session_type',
                    'open_price',
                    'high_price',
                    'low_price',
                    'close_price',
                    'volume_contracts',
                    'source',
                    'source_payload',
                    'fetched_at',
                    'updated_at',
                ],
            );
        }

        return count($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function deleteSkippedRows(array $rows): void
    {
        $keys = [];
        foreach ($rows as $row) {
            if (! (bool) ($row['skip_upsert'] ?? false)) {
                continue;
            }

            foreach (['exchange', 'symbol', 'interval', 'started_at'] as $field) {
                if (! array_key_exists($field, $row)) {
                    continue 2;
                }
            }

            $keys[] = [
                'exchange' => (string) $row['exchange'],
                'symbol' => (string) $row['symbol'],
                'interval' => (string) $row['interval'],
                'started_at' => (string) $row['started_at'],
            ];
        }

        foreach (array_chunk($keys, self::UPSERT_CHUNK_SIZE) as $chunk) {
            TwFuturesHourlyPrice::query()
                ->where(function ($query) use ($chunk): void {
                    foreach ($chunk as $key) {
                        $query->orWhere(function ($query) use ($key): void {
                            $query
                                ->where('exchange', $key['exchange'])
                                ->where('symbol', $key['symbol'])
                                ->where('interval', $key['interval'])
                                ->where('started_at', $key['started_at']);
                        });
                    }
                })
                ->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTradingViewTimescale(
        string $tradingViewSymbol,
        string $interval,
        int $bars,
        ?CarbonImmutable $fromDate,
    ): array
    {
        $cacheKey = 'tw-futures:prices:tradingview:v2:' . sha1(serialize([
            $tradingViewSymbol,
            $interval,
            $bars,
            $fromDate?->toDateString(),
        ]));

        return Cache::remember(
            $cacheKey,
            $interval === '60' ? now()->addMinutes(8) : now()->addSeconds(30),
            fn (): array => $this->fetchTradingViewTimescaleUncached($tradingViewSymbol, $interval, $bars, $fromDate),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTradingViewTimescaleUncached(
        string $tradingViewSymbol,
        string $interval,
        int $bars,
        ?CarbonImmutable $fromDate,
    ): array
    {
        $socket = $this->openTradingViewSocket();

        try {
            $chartSession = $this->sessionId('cs');
            $symbolPayload = '=' . json_encode([
                'symbol' => $tradingViewSymbol,
                'adjustment' => 'splits',
                'session' => 'extended',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $this->sendTradingViewMessage($socket, 'set_auth_token', ['unauthorized_user_token']);
            $this->sendTradingViewMessage($socket, 'chart_create_session', [$chartSession, '']);
            $this->sendTradingViewMessage($socket, 'resolve_symbol', [$chartSession, 'symbol_1', $symbolPayload]);
            $this->sendTradingViewMessage($socket, 'create_series', [$chartSession, 's1', 's1', 'symbol_1', $interval, max(1, $bars)]);

            $latestTimescale = null;
            $seriesByUnix = [];
            $lastRequestedStartUnix = null;
            $moreDataRequests = 0;
            $waitingForMoreData = false;
            $waitingForFreshBar = false;
            $buffer = '';
            $deadline = microtime(true) + self::SOCKET_TIMEOUT_SECONDS;
            while (microtime(true) < $deadline) {
                $chunk = fread($socket, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('讀取 TradingView websocket 失敗。');
                }

                if ($chunk === '') {
                    usleep(50_000);
                    continue;
                }

                $buffer .= $chunk;
                while (($frame = $this->shiftWebSocketFrame($buffer)) !== null) {
                    if ($frame['opcode'] === 0x9) {
                        fwrite($socket, $this->webSocketFrame($frame['payload'], 0xA));
                        continue;
                    }

                    if ($frame['opcode'] !== 0x1) {
                        continue;
                    }

                    $payload = $frame['payload'];
                    if (str_starts_with($payload, '~h~')) {
                        fwrite($socket, $this->webSocketFrame($payload));
                        continue;
                    }

                    foreach ($this->tradingViewMessages($payload) as $message) {
                        $method = (string) ($message['m'] ?? '');
                        if (in_array($method, ['critical_error', 'symbol_error'], true)) {
                            throw new RuntimeException('TradingView 回應錯誤：' . json_encode($message['p'] ?? [], JSON_UNESCAPED_UNICODE));
                        }

                        if ($method === 'timescale_update') {
                            $latestTimescale = $this->mergeTimescaleSeries($latestTimescale ?? $message, $message, $seriesByUnix);
                            $waitingForMoreData = false;

                            if ($this->timescaleCoversFrom($latestTimescale, $fromDate)) {
                                if ($this->timescaleNeedsFreshBarWait($latestTimescale, $interval)) {
                                    if (! $waitingForFreshBar) {
                                        $waitingForFreshBar = true;
                                        $deadline = min($deadline, microtime(true) + self::FRESH_BAR_WAIT_SECONDS);
                                    }

                                    continue;
                                }

                                return $latestTimescale;
                            }

                            $earliestUnix = $this->timescaleEarliestUnix($latestTimescale);
                            if (
                                $earliestUnix === null
                                || $moreDataRequests >= self::MAX_MORE_DATA_REQUESTS
                                || $earliestUnix === $lastRequestedStartUnix
                            ) {
                                return $latestTimescale;
                            }

                            $lastRequestedStartUnix = $earliestUnix;
                            $moreDataRequests++;
                            $waitingForMoreData = true;
                            $this->sendTradingViewMessage($socket, 'request_more_data', [
                                $chartSession,
                                's1',
                                self::MORE_DATA_CHUNK_SIZE,
                            ]);
                            $deadline = microtime(true) + self::SOCKET_TIMEOUT_SECONDS;
                        }

                        if ($method === 'series_completed' && $latestTimescale !== null && ! $waitingForMoreData && ! $waitingForFreshBar) {
                            return $latestTimescale;
                        }
                    }
                }
            }
        } finally {
            fclose($socket);
        }

        if ($latestTimescale !== null) {
            return $latestTimescale;
        }

        throw new RuntimeException(sprintf('等待 TradingView %sK 資料逾時。', $interval));
    }

    /**
     * @param array<string, mixed> $baseMessage
     * @param array<string, mixed> $message
     * @param array<int, array<string, mixed>> $seriesByUnix
     * @return array<string, mixed>
     */
    private function mergeTimescaleSeries(array $baseMessage, array $message, array &$seriesByUnix): array
    {
        $series = $message['p'][1]['s1']['s'] ?? null;
        if (! is_array($series)) {
            return $baseMessage;
        }

        foreach ($series as $item) {
            if (! is_array($item)) {
                continue;
            }

            $values = $item['v'] ?? null;
            if (! is_array($values) || ! isset($values[0]) || ! is_numeric($values[0])) {
                continue;
            }

            $seriesByUnix[(int) floor((float) $values[0])] = $item;
        }

        ksort($seriesByUnix, SORT_NUMERIC);
        $baseMessage['p'][1]['s1']['s'] = array_values($seriesByUnix);

        return $baseMessage;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function timescaleCoversFrom(array $message, ?CarbonImmutable $fromDate): bool
    {
        if ($fromDate === null) {
            return true;
        }

        $earliestUnix = $this->timescaleEarliestUnix($message);
        if ($earliestUnix === null) {
            return false;
        }

        return CarbonImmutable::createFromTimestamp($earliestUnix, 'UTC')
            ->setTimezone('Asia/Taipei')
            ->lessThanOrEqualTo($fromDate);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function timescaleNeedsFreshBarWait(array $message, string $interval): bool
    {
        $expectedStartedAtUnix = $this->expectedCurrentBarStartedAtUnix($interval);
        if ($expectedStartedAtUnix === null) {
            return false;
        }

        $latestUnix = $this->timescaleLatestUnix($message);
        if ($latestUnix === null) {
            return false;
        }

        return $latestUnix < $expectedStartedAtUnix;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function timescaleEarliestUnix(array $message): ?int
    {
        $series = $message['p'][1]['s1']['s'] ?? null;
        if (! is_array($series) || $series === []) {
            return null;
        }

        $first = $series[0] ?? null;
        $values = is_array($first) ? ($first['v'] ?? null) : null;
        if (! is_array($values) || ! isset($values[0]) || ! is_numeric($values[0])) {
            return null;
        }

        return (int) floor((float) $values[0]);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function timescaleLatestUnix(array $message): ?int
    {
        $series = $message['p'][1]['s1']['s'] ?? null;
        if (! is_array($series) || $series === []) {
            return null;
        }

        $last = $series[array_key_last($series)] ?? null;
        $values = is_array($last) ? ($last['v'] ?? null) : null;
        if (! is_array($values) || ! isset($values[0]) || ! is_numeric($values[0])) {
            return null;
        }

        return (int) floor((float) $values[0]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function appendCurrentSessionOpeningQuoteRow(
        array $rows,
        ?CarbonImmutable $fromDate,
        ?CarbonImmutable $toDate,
        string $symbol,
        string $interval,
    ): array {
        if ($symbol !== self::DEFAULT_SYMBOL) {
            return $rows;
        }

        $now = CarbonImmutable::now('Asia/Taipei');
        $window = $this->currentSessionWindow($now);
        if ($window === null) {
            return $rows;
        }

        $expectedStartedAt = $this->expectedCurrentBarStartedAt($interval, $now);
        if ($expectedStartedAt === null || $expectedStartedAt->timestamp !== $window['start']->timestamp) {
            return $rows;
        }

        if ($fromDate !== null && $window['start']->lessThan($fromDate)) {
            return $rows;
        }

        if ($toDate !== null && $window['start']->greaterThan($toDate)) {
            return $rows;
        }

        foreach ($rows as $row) {
            if ((int) ($row['started_at_unix'] ?? 0) === $window['start']->timestamp) {
                return $rows;
            }
        }

        $quote = $this->fetchTaifexFrontMonthQuote($window['marketType']);
        if ($quote === null) {
            return $rows;
        }

        $quoteRow = $this->taifexQuoteToPriceRow($quote, $window['start'], $symbol, $interval);
        if ($quoteRow === null) {
            return $rows;
        }

        $rows[] = $quoteRow;
        usort(
            $rows,
            fn (array $first, array $second): int => ((int) $first['started_at_unix']) <=> ((int) $second['started_at_unix']),
        );

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTaifexFrontMonthQuote(string $marketType): ?array
    {
        try {
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
        } catch (Throwable) {
            return null;
        }

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
     * @return array<string, mixed>|null
     */
    private function taifexQuoteToPriceRow(
        array $quote,
        CarbonImmutable $startedAt,
        string $symbol,
        string $interval,
    ): ?array {
        $quoteAt = $this->taifexQuoteTimestamp($quote);
        $intervalMinutes = (int) $interval;
        if (
            $quoteAt === null
            || $quoteAt->lessThan($startedAt)
            || $quoteAt->greaterThanOrEqualTo($startedAt->addMinutes($intervalMinutes))
        ) {
            return null;
        }

        $open = $this->floatValue($quote['COpenPrice'] ?? null);
        $high = $this->floatValue($quote['CHighPrice'] ?? null);
        $low = $this->floatValue($quote['CLowPrice'] ?? null);
        $close = $this->floatValue($quote['CLastPrice'] ?? null);
        if ($open === null || $high === null || $low === null || $close === null) {
            return null;
        }

        return [
            'exchange' => self::DEFAULT_EXCHANGE,
            'symbol' => $symbol,
            'symbol_name' => self::DEFAULT_SYMBOL_NAME,
            'interval' => $interval,
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'started_at_unix' => $startedAt->timestamp,
            'trade_date' => $this->tradeDate($startedAt),
            'session_type' => $this->sessionType($startedAt),
            'open_price' => $this->decimal($open),
            'high_price' => $this->decimal($high),
            'low_price' => $this->decimal($low),
            'close_price' => $this->decimal($close),
            'volume_contracts' => (int) round((float) ($quote['CTotalVolume'] ?? 0)),
            'source' => self::SOURCE_QUOTE_NAME,
            'source_payload' => [
                'endpoint' => self::TAIFEX_QUOTE_LIST_URL,
                'symbol_id' => (string) ($quote['SymbolID'] ?? ''),
                'quote_date' => (string) ($quote['CDate'] ?? ''),
                'quote_time' => (string) ($quote['CTime'] ?? ''),
                'interval' => $interval,
                'snapshot_type' => 'session_opening_bar',
            ],
            'fetched_at' => now(),
        ];
    }

    /**
     * @param array<string, mixed> $quote
     */
    private function taifexQuoteTimestamp(array $quote): ?CarbonImmutable
    {
        $date = preg_replace('/\D/', '', (string) ($quote['CDate'] ?? ''));
        $time = preg_replace('/\D/', '', (string) ($quote['CTime'] ?? ''));
        if ($date === null || $time === null || strlen($date) !== 8 || strlen($time) < 4) {
            return null;
        }

        $time = str_pad(substr($time, 0, 6), 6, '0', STR_PAD_RIGHT);

        try {
            return CarbonImmutable::createFromFormat('Ymd His', $date . ' ' . $time, 'Asia/Taipei') ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function expectedCurrentBarStartedAtUnix(string $interval): ?int
    {
        return $this->expectedCurrentBarStartedAt($interval)?->timestamp;
    }

    private function expectedCurrentBarStartedAt(string $interval, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $intervalMinutes = (int) $interval;
        if ($intervalMinutes <= 0) {
            return null;
        }

        $now ??= CarbonImmutable::now('Asia/Taipei');
        $window = $this->currentSessionWindow($now);
        if ($window === null) {
            return null;
        }

        $elapsedSeconds = $now->timestamp - $window['start']->timestamp;
        $intervalSeconds = $intervalMinutes * 60;

        return $window['start']->addSeconds(intdiv($elapsedSeconds, $intervalSeconds) * $intervalSeconds);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, marketType: string}|null
     */
    private function currentSessionWindow(?CarbonImmutable $now = null): ?array
    {
        $now ??= CarbonImmutable::now('Asia/Taipei');
        $time = $now->format('H:i');

        if ($time >= '08:45' && $time < '13:45') {
            return [
                'start' => $now->setTime(8, 45),
                'end' => $now->setTime(13, 45),
                'marketType' => '0',
            ];
        }

        if ($time >= '15:00') {
            return [
                'start' => $now->setTime(15, 0),
                'end' => $now->addDay()->setTime(5, 0),
                'marketType' => '1',
            ];
        }

        if ($time < '05:00') {
            return [
                'start' => $now->subDay()->setTime(15, 0),
                'end' => $now->setTime(5, 0),
                'marketType' => '1',
            ];
        }

        return null;
    }

    private function normalizeInterval(string $interval): string
    {
        $interval = trim($interval);
        if (! in_array($interval, self::SUPPORTED_INTERVALS, true)) {
            throw new RuntimeException(sprintf('不支援的 TradingView K 線週期：%s。', $interval));
        }

        return $interval;
    }

    /**
     * @return resource
     */
    private function openTradingViewSocket()
    {
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => self::TRADINGVIEW_SOCKET_HOST,
                'SNI_enabled' => true,
            ],
        ]);

        $socket = stream_socket_client(
            'ssl://' . self::TRADINGVIEW_SOCKET_HOST . ':' . self::TRADINGVIEW_SOCKET_PORT,
            $errorCode,
            $errorMessage,
            self::SOCKET_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('連線 TradingView 失敗：%s (%d)', $errorMessage, $errorCode));
        }

        stream_set_timeout($socket, self::SOCKET_TIMEOUT_SECONDS);

        $key = base64_encode(random_bytes(16));
        $path = '/socket.io/websocket?from=chart%2F&date=' . (int) floor(microtime(true) * 1000);
        $headers = [
            'GET ' . $path . ' HTTP/1.1',
            'Host: ' . self::TRADINGVIEW_SOCKET_HOST,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
            'Origin: https://www.tradingview.com',
            'User-Agent: Mozilla/5.0',
            '',
            '',
        ];
        fwrite($socket, implode("\r\n", $headers));

        $header = '';
        while (!str_contains($header, "\r\n\r\n")) {
            $chunk = fread($socket, 4096);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('TradingView websocket 握手沒有回應。');
            }

            $header .= $chunk;
            if (strlen($header) > 16384) {
                throw new RuntimeException('TradingView websocket 握手回應過長。');
            }
        }

        if (!str_starts_with($header, 'HTTP/1.1 101')) {
            throw new RuntimeException('TradingView websocket 握手失敗：' . trim(strtok($header, "\r\n")));
        }

        return $socket;
    }

    /**
     * @param resource $socket
     * @param list<mixed> $params
     */
    private function sendTradingViewMessage($socket, string $method, array $params): void
    {
        $message = json_encode(['m' => $method, 'p' => $params], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        fwrite($socket, $this->webSocketFrame('~m~' . strlen($message) . '~m~' . $message));
    }

    private function webSocketFrame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $firstByte = 0x80 | ($opcode & 0x0F);
        if ($length < 126) {
            $header = pack('CC', $firstByte, 0x80 | $length);
        } elseif ($length < 65536) {
            $header = pack('CCn', $firstByte, 0x80 | 126, $length);
        } else {
            $header = pack('CCNN', $firstByte, 0x80 | 127, intdiv($length, 4294967296), $length % 4294967296);
        }

        $mask = random_bytes(4);
        $masked = '';
        for ($index = 0; $index < $length; $index++) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }

        return $header . $mask . $masked;
    }

    /**
     * @return array{opcode: int, payload: string}|null
     */
    private function shiftWebSocketFrame(string &$buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);
        $opcode = $firstByte & 0x0F;
        $length = $secondByte & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }

            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }

            $parts = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            $length = ((int) $parts['high'] * 4294967296) + (int) $parts['low'];
            $offset = 10;
        }

        $isMasked = ($secondByte & 0x80) === 0x80;
        $mask = '';
        if ($isMasked) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }

            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);

        if ($isMasked) {
            $unmasked = '';
            for ($index = 0; $index < $length; $index++) {
                $unmasked .= $payload[$index] ^ $mask[$index % 4];
            }
            $payload = $unmasked;
        }

        return [
            'opcode' => $opcode,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tradingViewMessages(string $payload): array
    {
        $messages = [];
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $prefix = strpos($payload, '~m~', $offset);
            if ($prefix === false) {
                break;
            }

            $lengthStart = $prefix + 3;
            $lengthEnd = strpos($payload, '~m~', $lengthStart);
            if ($lengthEnd === false) {
                break;
            }

            $messageLength = (int) substr($payload, $lengthStart, $lengthEnd - $lengthStart);
            $messageStart = $lengthEnd + 3;
            $json = substr($payload, $messageStart, $messageLength);
            $offset = $messageStart + $messageLength;

            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    private function sessionId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
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

    private function sessionType(CarbonImmutable $startedAt): string
    {
        $time = $startedAt->format('H:i');
        if ($time >= '08:45' && $time < '14:30') {
            return 'day';
        }

        if ($time >= '15:00' || $time < '05:30') {
            return 'night';
        }

        return 'break';
    }

    private function floatValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
