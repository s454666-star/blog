<?php

namespace App\Services;

use App\Models\TwStockAnnualFinancialComparison;
use App\Models\TwStockDailyPrice;
use App\Models\TwStockQ1FinancialReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockDailyPriceFetcher
{
    private const TPEX_DAILY_PRICE_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes';

    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s';

    private const LATEST_PRICE_CACHE_TTL_SECONDS = 600;

    private const HISTORICAL_PRICE_CACHE_TTL_SECONDS = 2592000;

    public function __construct(
        private readonly TwStockTwseDailyQuoteService $twseDailyQuoteService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchLatestRows(): array
    {
        return $this->withFetchedAt([
            ...$this->fetchLatestTwseRows(),
            ...$this->fetchLatestTpexRows(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function knownStockCandidates(?string $exchange = null, ?string $stockCode = null): array
    {
        $candidates = [];
        foreach ($this->fetchLatestRows() as $row) {
            $candidates[$row['exchange'] . ':' . $row['stock_code']] = [
                'exchange' => $row['exchange'],
                'stock_code' => $row['stock_code'],
                'stock_name' => $row['stock_name'],
            ];
        }

        foreach ($this->databaseCandidates() as $candidate) {
            $candidates[$candidate['exchange'] . ':' . $candidate['stock_code']] = $candidate;
        }

        $rows = array_values($candidates);
        $rows = array_values(array_filter($rows, function (array $candidate) use ($exchange, $stockCode): bool {
            if ($exchange !== null && $exchange !== '' && $candidate['exchange'] !== $exchange) {
                return false;
            }

            if ($stockCode !== null && $stockCode !== '' && $candidate['stock_code'] !== $stockCode) {
                return false;
            }

            return true;
        }));

        if ($rows === [] && $exchange !== null && $exchange !== '' && $stockCode !== null && $stockCode !== '') {
            $rows[] = [
                'exchange' => $exchange,
                'stock_code' => $stockCode,
                'stock_name' => $stockCode,
            ];
        }

        usort($rows, fn (array $left, array $right): int => [
            $left['exchange'],
            $left['stock_code'],
        ] <=> [
            $right['exchange'],
            $right['stock_code'],
        ]);

        return $rows;
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    public function fetchHistoricalRows(array $candidate, string $from, string $to): array
    {
        $exchange = (string) ($candidate['exchange'] ?? '');
        $stockCode = (string) ($candidate['stock_code'] ?? '');
        $stockName = (string) ($candidate['stock_name'] ?? $stockCode);
        if ($exchange === '' || $stockCode === '') {
            return [];
        }

        $symbol = $stockCode . ($exchange === 'TPEx' ? '.TWO' : '.TW');
        $fromDate = CarbonImmutable::parse($from, 'Asia/Taipei')->startOfDay();
        $toDate = CarbonImmutable::parse($to, 'Asia/Taipei')->endOfDay();

        $rows = Cache::remember(
            'tw-stock:daily-prices:yahoo-historical:v1:' . sha1(serialize([
                $symbol,
                $fromDate->toDateString(),
                $toDate->toDateString(),
            ])),
            now()->addSeconds($this->historicalPriceCacheTtl($toDate)),
            function () use ($symbol, $fromDate, $toDate, $exchange, $stockCode, $stockName): array {
                try {
                    $response = $this->http()
                        ->get(sprintf(self::YAHOO_CHART_URL, $symbol), [
                            'period1' => $fromDate->timestamp,
                            'period2' => $toDate->addDay()->timestamp,
                            'interval' => '1d',
                            'events' => 'history',
                        ])
                        ->throw()
                        ->json();
                } catch (Throwable $e) {
                    report($e);

                    return [];
                }

                $result = $response['chart']['result'][0] ?? null;
                $timestamps = $result['timestamp'] ?? null;
                $quote = $result['indicators']['quote'][0] ?? null;
                if (!is_array($timestamps) || !is_array($quote)) {
                    return [];
                }

                $opens = $quote['open'] ?? [];
                $highs = $quote['high'] ?? [];
                $lows = $quote['low'] ?? [];
                $closes = $quote['close'] ?? [];
                $volumes = $quote['volume'] ?? [];
                $rows = [];
                foreach ($timestamps as $index => $timestamp) {
                    $tradeDate = CarbonImmutable::createFromTimestamp((int) $timestamp, 'UTC')
                        ->setTimezone('Asia/Taipei')
                        ->toDateString();
                    $close = $this->parseDecimal($closes[$index] ?? null);
                    if ($close === null) {
                        continue;
                    }

                    $previousClose = $rows === [] ? null : (float) $rows[count($rows) - 1]['close_price'];
                    $changeAmount = $previousClose === null ? null : $close - $previousClose;
                    $rows[] = [
                        'exchange' => $exchange,
                        'stock_code' => $stockCode,
                        'stock_name' => $stockName,
                        'trade_date' => $tradeDate,
                        'open_price' => $this->decimal($this->parseDecimal($opens[$index] ?? null)),
                        'high_price' => $this->decimal($this->parseDecimal($highs[$index] ?? null)),
                        'low_price' => $this->decimal($this->parseDecimal($lows[$index] ?? null)),
                        'close_price' => $this->decimal($close),
                        'previous_close_price' => $this->decimal($previousClose),
                        'price_change_amount' => $this->decimal($changeAmount),
                        'price_change_percent' => $previousClose !== null && $previousClose > 0.0
                            ? $this->decimal(($changeAmount / $previousClose) * 100)
                            : null,
                        'volume_lots' => (int) floor((float) ($volumes[$index] ?? 0) / 1000),
                        'volume_shares' => (int) ($volumes[$index] ?? 0),
                        'trade_value' => null,
                        'transaction_count' => null,
                        'source' => 'Yahoo Finance chart public endpoint',
                        'source_payload' => ['symbol' => $symbol],
                    ];
                }

                return $rows;
            },
        );

        return $this->withFetchedAt($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function upsertRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = now();
        $payloads = array_map(function (array $row) use ($now): array {
            $row['source_payload'] = json_encode($row['source_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $rows);

        TwStockDailyPrice::query()->upsert(
            $payloads,
            ['exchange', 'stock_code', 'trade_date'],
            [
                'stock_name',
                'open_price',
                'high_price',
                'low_price',
                'close_price',
                'previous_close_price',
                'price_change_amount',
                'price_change_percent',
                'volume_lots',
                'volume_shares',
                'trade_value',
                'transaction_count',
                'source',
                'source_payload',
                'fetched_at',
                'updated_at',
            ],
        );

        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLatestTwseRows(): array
    {
        return Cache::remember(
            'tw-stock:daily-prices:twse-latest-rows:v2',
            now()->addSeconds(self::LATEST_PRICE_CACHE_TTL_SECONDS),
            function (): array {
                $rows = [];
                foreach ($this->twseDailyQuoteService->fetchRows() as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $stockCode = trim((string) ($row['Code'] ?? ''));
                    $close = $this->parseDecimal($row['ClosingPrice'] ?? null);
                    $date = $this->parseRocDate((string) ($row['Date'] ?? ''));
                    if (!$this->isCommonStockCode($stockCode) || $date === null || $close === null) {
                        continue;
                    }

                    $change = $this->parseDecimal($row['Change'] ?? null);
                    $previousClose = $change === null ? null : $close - $change;
                    $volumeShares = $this->parseInteger($row['TradeVolume'] ?? null) ?? 0;
                    $rows[] = $this->latestPayload([
                        'exchange' => 'TWSE',
                        'stock_code' => $stockCode,
                        'stock_name' => trim((string) ($row['Name'] ?? '')),
                        'trade_date' => $date,
                        'open_price' => $this->parseDecimal($row['OpeningPrice'] ?? null),
                        'high_price' => $this->parseDecimal($row['HighestPrice'] ?? null),
                        'low_price' => $this->parseDecimal($row['LowestPrice'] ?? null),
                        'close_price' => $close,
                        'previous_close_price' => $previousClose,
                        'price_change_amount' => $change,
                        'volume_shares' => $volumeShares,
                        'trade_value' => $this->parseInteger($row['TradeValue'] ?? null),
                        'transaction_count' => $this->parseInteger($row['Transaction'] ?? null),
                        'source' => 'TWSE STOCK_DAY_ALL',
                        'source_payload' => ['row' => $row],
                    ]);
                }

                return $rows;
            },
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchLatestTpexRows(): array
    {
        return Cache::remember(
            'tw-stock:daily-prices:tpex-latest-rows:v1',
            now()->addSeconds(self::LATEST_PRICE_CACHE_TTL_SECONDS),
            function (): array {
                try {
                    $response = $this->http()->get(self::TPEX_DAILY_PRICE_URL)->throw()->json();
                } catch (Throwable $e) {
                    report($e);

                    return [];
                }

                if (!is_array($response)) {
                    return [];
                }

                $rows = [];
                foreach ($response as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $stockCode = trim((string) ($row['SecuritiesCompanyCode'] ?? ''));
                    $close = $this->parseDecimal($row['Close'] ?? null);
                    $date = $this->parseRocDate((string) ($row['Date'] ?? ''));
                    if (!$this->isCommonStockCode($stockCode) || $date === null || $close === null) {
                        continue;
                    }

                    $change = $this->parseDecimal($row['Change'] ?? null);
                    $previousClose = $change === null ? null : $close - $change;
                    $volumeShares = $this->parseInteger($row['TradingShares'] ?? null) ?? 0;
                    $rows[] = $this->latestPayload([
                        'exchange' => 'TPEx',
                        'stock_code' => $stockCode,
                        'stock_name' => trim((string) ($row['CompanyName'] ?? '')),
                        'trade_date' => $date,
                        'open_price' => $this->parseDecimal($row['Open'] ?? null),
                        'high_price' => $this->parseDecimal($row['High'] ?? null),
                        'low_price' => $this->parseDecimal($row['Low'] ?? null),
                        'close_price' => $close,
                        'previous_close_price' => $previousClose,
                        'price_change_amount' => $change,
                        'volume_shares' => $volumeShares,
                        'trade_value' => $this->parseInteger($row['TransactionAmount'] ?? null),
                        'transaction_count' => $this->parseInteger($row['TransactionNumber'] ?? null),
                        'source' => 'TPEx tpex_mainboard_quotes',
                        'source_payload' => ['row' => $row],
                    ]);
                }

                return $rows;
            },
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function latestPayload(array $row): array
    {
        $previousClose = $this->parseDecimal($row['previous_close_price'] ?? null);
        $change = $this->parseDecimal($row['price_change_amount'] ?? null);

        return [
            'exchange' => $row['exchange'],
            'stock_code' => $row['stock_code'],
            'stock_name' => $row['stock_name'],
            'trade_date' => $row['trade_date'],
            'open_price' => $this->decimal($this->parseDecimal($row['open_price'] ?? null)),
            'high_price' => $this->decimal($this->parseDecimal($row['high_price'] ?? null)),
            'low_price' => $this->decimal($this->parseDecimal($row['low_price'] ?? null)),
            'close_price' => $this->decimal($this->parseDecimal($row['close_price'] ?? null)),
            'previous_close_price' => $this->decimal($previousClose),
            'price_change_amount' => $this->decimal($change),
            'price_change_percent' => $previousClose !== null && $previousClose > 0.0 && $change !== null
                ? $this->decimal(($change / $previousClose) * 100)
                : null,
            'volume_lots' => (int) floor((int) ($row['volume_shares'] ?? 0) / 1000),
            'volume_shares' => (int) ($row['volume_shares'] ?? 0),
            'trade_value' => $row['trade_value'],
            'transaction_count' => $row['transaction_count'],
            'source' => $row['source'],
            'source_payload' => $row['source_payload'],
            'fetched_at' => now(),
        ];
    }

    /**
     * @return list<array{exchange: string, stock_code: string, stock_name: string}>
     */
    private function databaseCandidates(): array
    {
        return Cache::remember(
            'tw-stock:daily-prices:database-candidates:v1:' . $this->databaseCandidatesCacheVersion(),
            now()->addSeconds(self::HISTORICAL_PRICE_CACHE_TTL_SECONDS),
            function (): array {
                $rows = [];
                foreach ([
                    TwStockQ1FinancialReport::query()->select('exchange', 'stock_code', 'stock_name')->distinct()->get(),
                    TwStockAnnualFinancialComparison::query()->select('exchange', 'stock_code', 'stock_name')->distinct()->get(),
                    TwStockDailyPrice::query()->select('exchange', 'stock_code', 'stock_name')->distinct()->get(),
                ] as $collection) {
                    foreach ($collection as $row) {
                        $stockCode = (string) $row->stock_code;
                        $exchange = (string) $row->exchange;
                        if (!$this->isCommonStockCode($stockCode) || !in_array($exchange, ['TWSE', 'TPEx'], true)) {
                            continue;
                        }

                        $rows[] = [
                            'exchange' => $exchange,
                            'stock_code' => $stockCode,
                            'stock_name' => (string) $row->stock_name,
                        ];
                    }
                }

                return $rows;
            },
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withFetchedAt(array $rows): array
    {
        $now = now();

        return array_map(function (array $row) use ($now): array {
            $row['fetched_at'] = $now;

            return $row;
        }, $rows);
    }

    private function historicalPriceCacheTtl(CarbonImmutable $toDate): int
    {
        return $toDate->isBefore(CarbonImmutable::today('Asia/Taipei'))
            ? self::HISTORICAL_PRICE_CACHE_TTL_SECONDS
            : self::LATEST_PRICE_CACHE_TTL_SECONDS;
    }

    private function databaseCandidatesCacheVersion(): string
    {
        $versions = [];
        foreach ([TwStockQ1FinancialReport::class, TwStockAnnualFinancialComparison::class, TwStockDailyPrice::class] as $modelClass) {
            try {
                $row = $modelClass::query()
                    ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as max_updated_at, MAX(id) as max_id')
                    ->toBase()
                    ->first();
                $versions[] = implode(':', [
                    $modelClass,
                    (int) ($row->row_count ?? 0),
                    (string) ($row->max_updated_at ?? ''),
                    (string) ($row->max_id ?? ''),
                ]);
            } catch (Throwable) {
                $versions[] = $modelClass . ':missing';
            }
        }

        return sha1(implode('|', $versions));
    }

    private function isCommonStockCode(string $stockCode): bool
    {
        return preg_match('/^[1-9]\d{3}$/', $stockCode) === 1;
    }

    private function parseRocDate(string $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim($value)) ?? '';
        if (strlen($normalized) !== 7) {
            return null;
        }

        $year = (int) substr($normalized, 0, 3) + 1911;
        $month = (int) substr($normalized, 3, 2);
        $day = (int) substr($normalized, 5, 2);

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseDecimal(mixed $value): ?float
    {
        $normalized = str_replace([',', "\xc2\xa0", ' ', '%', '+'], '', trim((string) $value));

        if ($normalized === '' || $normalized === '-' || $normalized === '－' || $normalized === '--') {
            return null;
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function parseInteger(mixed $value): ?int
    {
        $normalized = str_replace([',', "\xc2\xa0", ' '], '', trim((string) $value));

        if ($normalized === '' || $normalized === '-' || $normalized === '－' || !is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    private function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 4, '.', '');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(20)
            ->retry(2, 300)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'Mozilla/5.0']);
    }
}
