<?php

namespace App\Services;

use App\Models\TwStockMonthlyRevenue;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockMonthlyRevenueFetcher
{
    private const MOPS_MONTHLY_REVENUE_DOWNLOAD_URL = 'https://mopsov.twse.com.tw/server-java/FileDownLoad';

    private const MARKETS = [
        'sii' => 'TWSE',
        'otc' => 'TPEx',
    ];

    public function __construct(private readonly TwStockDailyPriceFetcher $dailyPriceFetcher)
    {
    }

    /**
     * @return array{fetched_rows: int, stored_rows: int, refreshed_price_rows: int, period: string}
     */
    public function fetchAndStore(int $year, int $month, bool $refreshPrices = true): array
    {
        $refreshedPriceRows = 0;
        if ($refreshPrices) {
            $refreshedPriceRows = $this->dailyPriceFetcher->upsertRows($this->dailyPriceFetcher->fetchLatestRows());
        }

        $rows = $this->fetchRows($year, $month);
        $storedRows = $this->storeRows($rows);

        return [
            'fetched_rows' => count($rows),
            'stored_rows' => $storedRows,
            'refreshed_price_rows' => $refreshedPriceRows,
            'period' => sprintf('%04d-%02d', $year, $month),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRows(int $year, int $month): array
    {
        $rocYear = $year - 1911;
        if ($rocYear <= 0 || $month < 1 || $month > 12) {
            return [];
        }

        $rows = [];
        foreach (self::MARKETS as $market => $exchange) {
            try {
                $csv = $this->http()
                    ->asForm()
                    ->post(self::MOPS_MONTHLY_REVENUE_DOWNLOAD_URL, [
                        'step' => '9',
                        'functionName' => 'show_file2',
                        'filePath' => sprintf('/t21/%s/', $market),
                        'fileName' => sprintf('t21sc03_%d_%d.csv', $rocYear, $month),
                    ])
                    ->throw()
                    ->body();
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($this->parseCsv($csv, $year, $month, $market, $exchange) as $row) {
                $rows[] = $row;
            }
        }

        $prices = $this->pricePerformanceByStock($rows);

        return array_map(function (array $row) use ($prices): array {
            $price = $prices[$this->stockKey($row['exchange'], $row['stock_code'])] ?? [];

            return array_merge($row, [
                'latest_price_date' => $price['latest_price_date'] ?? null,
                'latest_close_price' => $price['latest_close_price'] ?? null,
                'one_day_change_percent' => $price['one_day_change_percent'] ?? null,
                'five_day_change_percent' => $price['five_day_change_percent'] ?? null,
            ]);
        }, $rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function storeRows(array $rows): int
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

        TwStockMonthlyRevenue::query()->upsert(
            $payloads,
            ['exchange', 'stock_code', 'revenue_year', 'revenue_month'],
            [
                'announced_date',
                'stock_name',
                'industry',
                'monthly_revenue_thousands',
                'previous_month_revenue_thousands',
                'last_year_month_revenue_thousands',
                'month_over_month_percent',
                'year_over_year_percent',
                'mom_yoy_sum_percent',
                'cumulative_revenue_thousands',
                'last_year_cumulative_revenue_thousands',
                'cumulative_yoy_percent',
                'latest_price_date',
                'latest_close_price',
                'one_day_change_percent',
                'five_day_change_percent',
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
    public function parseCsv(string $csv, int $year, int $month, string $market, string $exchange): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', trim($csv)) ?? trim($csv);
        $lines = preg_split('/\r\n|\n|\r/', $csv);
        if (!is_array($lines) || $lines === []) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines), ',', '"', '\\');
        if ($header === []) {
            return [];
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
        $indexes = array_flip($header);
        $required = [
            '出表日期',
            '公司代號',
            '公司名稱',
            '營業收入-當月營收',
            '營業收入-上月營收',
            '營業收入-去年當月營收',
            '營業收入-上月比較增減(%)',
            '營業收入-去年同月增減(%)',
            '累計營業收入-當月累計營收',
            '累計營業收入-去年累計營收',
            '累計營業收入-前期比較增減(%)',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $indexes)) {
                return [];
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, ',', '"', '\\');
            $stockCode = trim((string) ($columns[$indexes['公司代號']] ?? ''));
            if (!$this->isCommonStockCode($stockCode)) {
                continue;
            }

            $stockName = trim((string) ($columns[$indexes['公司名稱']] ?? ''));
            if ($stockName === '') {
                continue;
            }

            $mom = $this->decimal($this->parseDecimal($columns[$indexes['營業收入-上月比較增減(%)']] ?? null), 4, 100000);
            $yoy = $this->decimal($this->parseDecimal($columns[$indexes['營業收入-去年同月增減(%)']] ?? null), 4, 100000);
            $sum = $mom !== null && $yoy !== null
                ? $this->decimal((float) $mom + (float) $yoy, 4, 200000)
                : null;

            $rows[] = [
                'revenue_year' => $year,
                'revenue_month' => $month,
                'announced_date' => $this->parseRocDate((string) ($columns[$indexes['出表日期']] ?? '')),
                'exchange' => $exchange,
                'stock_code' => $stockCode,
                'stock_name' => $stockName,
                'industry' => trim((string) ($columns[$indexes['產業別']] ?? '')) ?: null,
                'monthly_revenue_thousands' => $this->parseInteger($columns[$indexes['營業收入-當月營收']] ?? null),
                'previous_month_revenue_thousands' => $this->parseInteger($columns[$indexes['營業收入-上月營收']] ?? null),
                'last_year_month_revenue_thousands' => $this->parseInteger($columns[$indexes['營業收入-去年當月營收']] ?? null),
                'month_over_month_percent' => $mom,
                'year_over_year_percent' => $yoy,
                'mom_yoy_sum_percent' => $sum,
                'cumulative_revenue_thousands' => $this->parseInteger($columns[$indexes['累計營業收入-當月累計營收']] ?? null),
                'last_year_cumulative_revenue_thousands' => $this->parseInteger($columns[$indexes['累計營業收入-去年累計營收']] ?? null),
                'cumulative_yoy_percent' => $this->decimal(
                    $this->parseDecimal($columns[$indexes['累計營業收入-前期比較增減(%)']] ?? null),
                    4,
                    100000,
                ),
                'source' => 'MOPS monthly revenue CSV',
                'source_payload' => [
                    'source_url' => self::MOPS_MONTHLY_REVENUE_DOWNLOAD_URL,
                    'market' => $market,
                    'file_path' => sprintf('/t21/%s/', $market),
                    'file_name' => sprintf('t21sc03_%d_%d.csv', $year - 1911, $month),
                    'raw_row' => array_combine(
                        $header,
                        array_pad(array_slice($columns, 0, count($header)), count($header), null),
                    ) ?: null,
                ],
                'fetched_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function pricePerformanceByStock(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $stockCodes = collect($rows)->pluck('stock_code')->filter()->unique()->values()->all();
        $exchanges = collect($rows)->pluck('exchange')->filter()->unique()->values()->all();
        if ($stockCodes === [] || $exchanges === []) {
            return [];
        }

        $rankedRows = DB::query()
            ->fromSub(function ($query) use ($stockCodes, $exchanges): void {
                $query->from('tw_stock_daily_prices')
                    ->select([
                        'exchange',
                        'stock_code',
                        'trade_date',
                        'close_price',
                        'price_change_percent',
                    ])
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exchange, stock_code ORDER BY trade_date DESC) as price_row_number')
                    ->whereIn('stock_code', $stockCodes)
                    ->whereIn('exchange', $exchanges)
                    ->whereNotNull('close_price');
            }, 'ranked_prices')
            ->where('price_row_number', '<=', 6)
            ->orderBy('exchange')
            ->orderBy('stock_code')
            ->orderBy('price_row_number')
            ->get();

        return $rankedRows
            ->groupBy(fn (object $row): string => $this->stockKey((string) $row->exchange, (string) $row->stock_code))
            ->map(function (Collection $prices): array {
                $latest = $prices->firstWhere('price_row_number', 1);
                $baseline = $prices->firstWhere('price_row_number', 6);
                if ($latest === null) {
                    return [];
                }

                $fiveDayChange = null;
                if ($baseline !== null && (float) $baseline->close_price > 0.0) {
                    $fiveDayChange = (((float) $latest->close_price - (float) $baseline->close_price) / (float) $baseline->close_price) * 100;
                }

                return [
                    'latest_price_date' => (string) $latest->trade_date,
                    'latest_close_price' => $this->decimal((float) $latest->close_price, 4),
                    'one_day_change_percent' => $this->decimal($this->parseDecimal($latest->price_change_percent), 4, 1000),
                    'five_day_change_percent' => $this->decimal($fiveDayChange, 4, 1000),
                ];
            })
            ->all();
    }

    private function stockKey(mixed $exchange, mixed $stockCode): string
    {
        return (string) $exchange . ':' . (string) $stockCode;
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

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function parseInteger(mixed $value): ?int
    {
        $decimal = $this->parseDecimal($value);

        return $decimal === null ? null : (int) round($decimal);
    }

    private function decimal(?float $value, int $precision, ?float $maxAbs = null): ?string
    {
        if ($value === null || !is_finite($value)) {
            return null;
        }

        if ($maxAbs !== null && abs($value) > $maxAbs) {
            return null;
        }

        return number_format($value, $precision, '.', '');
    }

    private function http(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(2, 500)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0']);
    }
}
