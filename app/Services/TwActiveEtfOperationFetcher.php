<?php

namespace App\Services;

use App\Models\TwActiveEtf;
use App\Models\TwActiveEtfOperationItem;
use App\Models\TwActiveEtfOperationReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use RuntimeException;

class TwActiveEtfOperationFetcher
{
    private const TWSE_ACTIVE_ETF_URL = 'https://www.twse.com.tw/rwd/zh/ETF/activeList';

    private const TPEX_ETF_LIST_URL = 'https://www.tpex.org.tw/www/zh-tw/ETF/list';

    private const TPEX_DAILY_QUOTE_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes';

    private const TWSE_MIS_URL = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp';

    private const CMONEY_FORUM_URL = 'https://www.cmoney.tw/forum/stock/%s';

    private const CMONEY_DTNO_URL = 'https://customreport.cmoney.tw/app/v2/dtno/JsonCsv';

    private const CMONEY_ACTIVE_ETF_OPERATION_DTNO = 140141644;

    private const CMONEY_TOKEN_CACHE_SECONDS = 1200;

    private const TPEX_ETF_TYPES = [
        'domestic' => '國內成分股 ETF',
        'foreign' => '國外成分股 ETF',
        'bond' => '債券及固定收益 ETF',
    ];

    public function __construct(
        private readonly TwStockTwseDailyQuoteService $twseDailyQuoteService,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchActiveEtfList(): array
    {
        $rows = $this->mergeActiveEtfRows([
            ...$this->fetchTwseActiveEtfRows(),
            ...$this->fetchTpexActiveEtfRows(),
        ]);
        $quotes = $this->fetchQuoteSnapshots($rows);

        return array_map(function (array $row) use ($quotes): array {
            $quote = $quotes[(string) $row['stock_code']] ?? [];

            return [...$row, ...$quote];
        }, $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTwseActiveEtfRows(): array
    {
        $payload = $this->http()
            ->get(self::TWSE_ACTIVE_ETF_URL, ['response' => 'json'])
            ->throw()
            ->json();

        if (!is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            throw new RuntimeException('TWSE 主動式 ETF 清單回應格式不正確。');
        }

        $rows = [];
        foreach (($payload['data'] ?? []) as $row) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }

            $code = strtoupper(trim((string) $row[0]));
            if ($code === '') {
                continue;
            }

            $rows[] = [
                'stock_code' => $code,
                'stock_name' => trim((string) $row[1]),
                'exchange' => 'TWSE',
                'management_type' => trim((string) $row[2]),
                'etf_category' => trim((string) $row[3]),
                'is_active' => true,
                'source' => 'TWSE activeList',
                'source_payload' => [
                    'fields' => $payload['fields'] ?? null,
                    'row' => $row,
                ],
                'fetched_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTpexActiveEtfRows(): array
    {
        $rows = [];
        foreach (self::TPEX_ETF_TYPES as $type => $category) {
            $payload = $this->http()
                ->asForm()
                ->post(self::TPEX_ETF_LIST_URL, [
                    'type' => $type,
                    'response' => 'json',
                ])
                ->throw()
                ->json();

            if (!is_array($payload) || ($payload['stat'] ?? null) !== 'ok' || !is_array($payload['tables'] ?? null)) {
                throw new RuntimeException('TPEx ETF 清單回應格式不正確。');
            }

            foreach ($payload['tables'] as $table) {
                if (!is_array($table) || !is_array($table['data'] ?? null)) {
                    continue;
                }

                foreach ($table['data'] as $row) {
                    if (!is_array($row) || count($row) < 2) {
                        continue;
                    }

                    $code = strtoupper(trim((string) $row[0]));
                    if (!$this->isActiveEtfCode($code)) {
                        continue;
                    }

                    $rows[] = [
                        'stock_code' => $code,
                        'stock_name' => trim((string) $row[1]),
                        'exchange' => 'TPEx',
                        'management_type' => '主動式',
                        'etf_category' => $category,
                        'is_active' => true,
                        'source' => 'TPEx ETF/list',
                        'source_payload' => [
                            'type' => $type,
                            'fields' => $table['fields'] ?? null,
                            'row' => $row,
                        ],
                        'fetched_at' => now(),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function mergeActiveEtfRows(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $code = (string) ($row['stock_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $merged[$code] = $row;
        }

        ksort($merged, SORT_NATURAL);

        return array_values($merged);
    }

    /**
     * @param list<array<string, mixed>> $etfs
     * @return array<string, array<string, mixed>>
     */
    private function fetchQuoteSnapshots(array $etfs): array
    {
        $daily = $this->fetchDailyQuoteSnapshots($etfs);
        $realtime = $this->fetchMisQuoteSnapshots($etfs);

        foreach ($realtime as $code => $quote) {
            $dailyQuote = $daily[$code] ?? null;
            if (
                $dailyQuote !== null
                && ($dailyQuote['quote_date'] ?? null) === ($quote['quote_date'] ?? null)
                && ($dailyQuote['trade_value'] ?? null) !== null
            ) {
                $quote['trade_value'] = $dailyQuote['trade_value'];
                $quote['transaction_count'] = $dailyQuote['transaction_count'] ?? null;
                $quote['quote_source'] = $quote['quote_source'] . ' + ' . $dailyQuote['quote_source'];
                $quote['quote_payload']['daily_quote_payload'] = $dailyQuote['quote_payload'] ?? null;
                $quote['quote_payload']['trade_value_estimated'] = false;
            }

            $daily[$code] = $quote;
        }

        return $daily;
    }

    /**
     * @param list<array<string, mixed>> $etfs
     * @return array<string, array<string, mixed>>
     */
    private function fetchMisQuoteSnapshots(array $etfs): array
    {
        $channels = collect($etfs)
            ->map(function (array $etf): ?string {
                $code = strtoupper(trim((string) ($etf['stock_code'] ?? '')));
                if ($code === '') {
                    return null;
                }

                $prefix = (string) ($etf['exchange'] ?? '') === 'TPEx' ? 'otc' : 'tse';

                return $prefix . '_' . $code . '.tw';
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($channels === []) {
            return [];
        }

        $snapshots = [];
        foreach (array_chunk($channels, 35) as $chunk) {
            $payload = $this->http()
                ->get(self::TWSE_MIS_URL, [
                    'ex_ch' => implode('|', $chunk),
                    'json' => '1',
                    'delay' => '0',
                    '_' => (string) floor(microtime(true) * 1000),
                ])
                ->throw()
                ->json();

            $rows = is_array($payload) ? ($payload['msgArray'] ?? []) : [];
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $snapshot = $this->misRowToQuoteSnapshot($row);
                if ($snapshot !== null) {
                    $snapshots[(string) $snapshot['stock_code']] = $snapshot;
                }
            }
        }

        return array_map(function (array $snapshot): array {
            unset($snapshot['stock_code']);

            return $snapshot;
        }, $snapshots);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function misRowToQuoteSnapshot(array $row): ?array
    {
        $code = strtoupper(trim((string) ($row['c'] ?? '')));
        $close = $this->parseMarketNumber($row['z'] ?? null)
            ?? $this->parseMarketNumber($row['pz'] ?? null)
            ?? $this->midQuotePrice($row['b'] ?? null, $row['a'] ?? null);
        $previousClose = $this->parseMarketNumber($row['y'] ?? null);
        $quoteDate = $this->parseYmdStringDate((string) ($row['d'] ?? ''));
        if ($code === '' || $close === null || $previousClose === null || $quoteDate === null) {
            return null;
        }

        $volumeLots = $this->parseMarketInteger($row['v'] ?? null);
        $volumeShares = $volumeLots === null ? null : $volumeLots * 1000;
        $change = $close - $previousClose;
        $tradeValue = $volumeLots === null ? null : (int) round($close * $volumeLots * 1000);

        return [
            'stock_code' => $code,
            'exchange' => (string) ($row['ex'] ?? '') === 'otc' ? 'TPEx' : 'TWSE',
            'quote_date' => $quoteDate,
            'close_price' => $this->decimal($close),
            'previous_close_price' => $this->decimal($previousClose),
            'price_change_amount' => $this->decimal($change),
            'price_change_percent' => $previousClose > 0.0 ? $this->decimal(($change / $previousClose) * 100) : null,
            'volume_lots' => $volumeLots,
            'volume_shares' => $volumeShares,
            'trade_value' => $tradeValue,
            'transaction_count' => null,
            'quote_source' => 'TWSE MIS stockInfo',
            'quote_payload' => [
                'row' => $row,
                'trade_value_estimated' => true,
            ],
            'quote_fetched_at' => now(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $etfs
     * @return array<string, array<string, mixed>>
     */
    private function fetchDailyQuoteSnapshots(array $etfs): array
    {
        $codesByExchange = collect($etfs)
            ->groupBy(fn (array $etf): string => (string) ($etf['exchange'] ?? ''))
            ->map(fn (Collection $group): array => $group
                ->pluck('stock_code')
                ->map(fn (mixed $code): string => strtoupper(trim((string) $code)))
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->all();

        return [
            ...$this->fetchTwseDailyQuoteSnapshots($codesByExchange['TWSE'] ?? []),
            ...$this->fetchTpexDailyQuoteSnapshots($codesByExchange['TPEx'] ?? []),
        ];
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchTwseDailyQuoteSnapshots(array $codes): array
    {
        $allowed = array_flip($codes);
        if ($allowed === []) {
            return [];
        }

        $snapshots = [];
        foreach ($this->twseDailyQuoteService->fetchRows() as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['Code'] ?? '')));
            if (!isset($allowed[$code])) {
                continue;
            }

            $snapshot = $this->dailyQuoteSnapshotFromRow(
                $code,
                'TWSE',
                trim((string) ($row['Name'] ?? '')),
                $this->parseRocDate((string) ($row['Date'] ?? '')),
                $row['ClosingPrice'] ?? null,
                $row['Change'] ?? null,
                $row['TradeVolume'] ?? null,
                $row['TradeValue'] ?? null,
                $row['Transaction'] ?? null,
                'TWSE STOCK_DAY_ALL',
                $row,
            );

            if ($snapshot !== null) {
                $snapshots[$code] = $snapshot;
            }
        }

        return $snapshots;
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchTpexDailyQuoteSnapshots(array $codes): array
    {
        $allowed = array_flip($codes);
        if ($allowed === []) {
            return [];
        }

        $payload = $this->http()
            ->get(self::TPEX_DAILY_QUOTE_URL)
            ->throw()
            ->json();
        if (!is_array($payload)) {
            return [];
        }

        $snapshots = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['SecuritiesCompanyCode'] ?? '')));
            if (!isset($allowed[$code])) {
                continue;
            }

            $snapshot = $this->dailyQuoteSnapshotFromRow(
                $code,
                'TPEx',
                trim((string) ($row['CompanyName'] ?? '')),
                $this->parseRocDate((string) ($row['Date'] ?? '')),
                $row['Close'] ?? null,
                $row['Change'] ?? null,
                $row['TradingShares'] ?? null,
                $row['TransactionAmount'] ?? null,
                $row['TransactionNumber'] ?? null,
                'TPEx tpex_mainboard_quotes',
                $row,
            );

            if ($snapshot !== null) {
                $snapshots[$code] = $snapshot;
            }
        }

        return $snapshots;
    }

    /**
     * @param array<string, mixed> $sourceRow
     * @return array<string, mixed>|null
     */
    private function dailyQuoteSnapshotFromRow(
        string $code,
        string $exchange,
        string $name,
        ?string $quoteDate,
        mixed $closeValue,
        mixed $changeValue,
        mixed $volumeSharesValue,
        mixed $tradeValue,
        mixed $transactionCount,
        string $source,
        array $sourceRow,
    ): ?array {
        $close = $this->parseMarketNumber($closeValue);
        if ($quoteDate === null || $close === null) {
            return null;
        }

        $change = $this->parseMarketNumber($changeValue);
        $previousClose = $change === null ? null : $close - $change;
        $volumeShares = $this->parseMarketInteger($volumeSharesValue);

        return [
            'exchange' => $exchange,
            'stock_name' => $name,
            'quote_date' => $quoteDate,
            'close_price' => $this->decimal($close),
            'previous_close_price' => $this->decimal($previousClose),
            'price_change_amount' => $this->decimal($change),
            'price_change_percent' => $previousClose !== null && $previousClose > 0.0 && $change !== null
                ? $this->decimal(($change / $previousClose) * 100)
                : null,
            'volume_lots' => $volumeShares === null ? null : (int) floor($volumeShares / 1000),
            'volume_shares' => $volumeShares,
            'trade_value' => $this->parseMarketInteger($tradeValue),
            'transaction_count' => $this->parseMarketInteger($transactionCount),
            'quote_source' => $source,
            'quote_payload' => [
                'row' => $sourceRow,
                'trade_value_estimated' => false,
            ],
            'quote_fetched_at' => now(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function syncActiveEtfs(array $rows): int
    {
        $codes = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['stock_code'],
            $rows,
        )));

        return DB::transaction(function () use ($rows, $codes): int {
            if ($codes !== []) {
                TwActiveEtf::query()
                    ->whereNotIn('stock_code', $codes)
                    ->update(['is_active' => false]);
            }

            $saved = 0;
            foreach ($rows as $row) {
                TwActiveEtf::query()->updateOrCreate(
                    ['stock_code' => $row['stock_code']],
                    $row,
                );
                $saved++;
            }

            return $saved;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchOperationReports(
        string $etfCode,
        string $etfName,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?string $bearerToken = null,
    ): array {
        $code = strtoupper(trim($etfCode));
        $token = $bearerToken ?: $this->fetchCmoneyGuestToken($code);
        $payload = $this->http()
            ->withToken($token)
            ->asJson()
            ->post(self::CMONEY_DTNO_URL, [
                'Dtno' => self::CMONEY_ACTIVE_ETF_OPERATION_DTNO,
                'Params' => 'AssignID=' . $code,
            ])
            ->throw()
            ->json();

        if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
            throw new RuntimeException('CMoney 主動式 ETF 操作日報回應格式不正確。');
        }

        $reports = [];
        foreach ($payload['rows'] as $row) {
            if (!is_array($row) || count($row) < 7) {
                continue;
            }

            $date = $this->parseYmdDate((string) $row[0]);
            if ($date === null) {
                continue;
            }

            if ($from !== null && $date->lt(CarbonImmutable::parse($from->toDateString()))) {
                continue;
            }

            if ($to !== null && $date->gt(CarbonImmutable::parse($to->toDateString()))) {
                continue;
            }

            $dateKey = $date->toDateString();
            if (!isset($reports[$dateKey])) {
                $reports[$dateKey] = [
                    'etf_code' => $code,
                    'etf_name' => $etfName,
                    'operation_date' => $dateKey,
                    'source_kind' => 'cmoney_dtno',
                    'source_url' => sprintf(self::CMONEY_FORUM_URL, rawurlencode($code)),
                    'source_row_count' => 0,
                    'changed_row_count' => 0,
                    'source_payload' => [
                        'dtno' => self::CMONEY_ACTIVE_ETF_OPERATION_DTNO,
                        'columns' => $payload['columns'] ?? null,
                    ],
                    'items' => [],
                    'fetched_at' => now(),
                ];
            }

            $reports[$dateKey]['source_row_count']++;
            $item = $this->operationItemFromRow($code, $etfName, $dateKey, $row);
            if ($item === null) {
                continue;
            }

            $reports[$dateKey]['items'][] = $item;
            $reports[$dateKey]['changed_row_count']++;
        }

        krsort($reports);

        return array_values($reports);
    }

    /**
     * @param array<string, mixed> $reportPayload
     */
    public function storeReport(array $reportPayload): TwActiveEtfOperationReport
    {
        return DB::transaction(function () use ($reportPayload): TwActiveEtfOperationReport {
            $items = $reportPayload['items'] ?? [];
            unset($reportPayload['items']);

            $report = TwActiveEtfOperationReport::query()->updateOrCreate(
                [
                    'etf_code' => $reportPayload['etf_code'],
                    'operation_date' => $reportPayload['operation_date'],
                ],
                $reportPayload,
            );

            TwActiveEtfOperationItem::query()
                ->where('report_id', $report->id)
                ->delete();

            foreach ($items as $item) {
                $report->items()->create($item);
            }

            return $report->refresh();
        });
    }

    public function fetchCmoneyGuestToken(string $referenceCode = '00403A'): string
    {
        $cacheKey = 'tw-stock:active-etf:cmoney-token:v1:' . strtoupper($referenceCode);

        return Cache::remember($cacheKey, now()->addSeconds(self::CMONEY_TOKEN_CACHE_SECONDS), function () use ($referenceCode): string {
            $html = $this->http()
                ->get(sprintf(self::CMONEY_FORUM_URL, rawurlencode(strtoupper($referenceCode))))
                ->throw()
                ->body();

            if (preg_match('/tokens:\{at:"([^"]+)"/', $html, $matches) !== 1) {
                throw new RuntimeException('無法從 CMoney 頁面取得訪客 token。');
            }

            return $matches[1];
        });
    }

    /**
     * @param list<mixed> $row
     * @return array<string, mixed>|null
     */
    private function operationItemFromRow(string $etfCode, string $etfName, string $dateKey, array $row): ?array
    {
        $tag = trim((string) ($row[6] ?? ''));
        $action = $this->actionFromTag($tag);
        if ($action === null) {
            return null;
        }

        $stockCode = trim((string) ($row[2] ?? ''));
        $stockName = trim((string) ($row[3] ?? ''));
        if ($stockCode === '' || $stockName === '') {
            return null;
        }

        return [
            'etf_code' => $etfCode,
            'etf_name' => $etfName,
            'operation_date' => $dateKey,
            'stock_code' => $stockCode,
            'stock_name' => $stockName,
            'action' => $action,
            'action_label' => $this->actionLabel($action),
            'change_shares' => $this->parseInteger($row[4] ?? null),
            'change_lots' => $this->parseDecimal($row[5] ?? null),
            'source_status' => trim((string) ($row[1] ?? '')),
            'source_payload' => [
                'row' => $row,
            ],
            'fetched_at' => now(),
        ];
    }

    private function actionFromTag(string $tag): ?string
    {
        return match ($tag) {
            '新增', '建倉' => 'new',
            '加碼' => 'add',
            '減碼' => 'reduce',
            '刪除', '清倉' => 'remove',
            default => null,
        };
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'new' => '新增',
            'add' => '加碼',
            'reduce' => '減碼',
            'remove' => '刪除',
            default => $action,
        };
    }

    private function parseYmdDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if (!preg_match('/^\d{8}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Ymd', $value, (string) config('app.timezone'));

        return $date instanceof CarbonImmutable ? $date->startOfDay() : null;
    }

    private function parseYmdStringDate(string $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim($value)) ?? '';
        if (strlen($normalized) !== 8) {
            return null;
        }

        $year = (int) substr($normalized, 0, 4);
        $month = (int) substr($normalized, 4, 2);
        $day = (int) substr($normalized, 6, 2);
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
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

    private function isActiveEtfCode(string $code): bool
    {
        return preg_match('/^\d{5}[AD]$/', strtoupper(trim($code))) === 1;
    }

    private function parseMarketNumber(mixed $value): ?float
    {
        $normalized = str_replace([',', "\xc2\xa0", ' ', '+', '%'], '', trim((string) $value));

        if ($normalized === '' || $normalized === '-' || $normalized === '－' || $normalized === '--') {
            return null;
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function parseMarketInteger(mixed $value): ?int
    {
        $number = $this->parseMarketNumber($value);

        return $number === null ? null : (int) round($number);
    }

    private function midQuotePrice(mixed $bidValue, mixed $askValue): ?float
    {
        $bid = $this->firstOrderBookPrice($bidValue);
        $ask = $this->firstOrderBookPrice($askValue);

        if ($bid !== null && $ask !== null) {
            return round(($bid + $ask) / 2, 4);
        }

        return $bid ?? $ask;
    }

    private function firstOrderBookPrice(mixed $value): ?float
    {
        $first = explode('_', (string) $value)[0] ?? null;

        return $this->parseMarketNumber($first);
    }

    private function decimal(?float $value): ?string
    {
        return $value === null ? null : number_format($value, 4, '.', '');
    }

    private function parseInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) str_replace([',', ' ', "\xc2\xa0"], '', (string) $value);
    }

    private function parseDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace([',', ' ', "\xc2\xa0"], '', (string) $value);

        return number_format((float) $normalized, 3, '.', '');
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/json,text/html,application/xhtml+xml',
            'Referer' => 'https://www.cmoney.tw/forum/',
        ])
            ->timeout(30)
            ->retry(2, 500);
    }
}
