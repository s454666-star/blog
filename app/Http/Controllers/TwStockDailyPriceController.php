<?php

namespace App\Http\Controllers;

use App\Models\TwStockDailyPrice;
use App\Services\TwStockRealtimeQuoteService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TwStockDailyPriceController extends Controller
{
    private const STOCK_CACHE_TTL_SECONDS = 43200;

    private const REALTIME_REFRESH_SECONDS = 15;

    public function index(Request $request): View
    {
        $latestDate = $this->latestTradeDate();
        $allowedPerPage = [50, 100, 200, 500];
        $perPage = (int) $request->query('per_page', 100);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 100;
        }

        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $sort = (string) $request->query('sort', 'change');
        if (!in_array($sort, ['change', 'volume', 'price', 'amount'], true)) {
            $sort = 'change';
        }

        $query = TwStockDailyPrice::query()
            ->when($latestDate !== null, fn ($query) => $query->where('trade_date', $latestDate))
            ->whereNotNull('price_change_percent');

        $keyword = trim((string) $request->query('q', ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword): void {
                $query->where('stock_code', 'like', '%' . $keyword . '%')
                    ->orWhere('stock_name', 'like', '%' . $keyword . '%');
            });
        }

        $sortColumn = match ($sort) {
            'volume' => 'volume_lots',
            'price' => 'close_price',
            'amount' => 'price_change_amount',
            default => 'price_change_percent',
        };

        $rows = $query
            ->orderBy($sortColumn, $direction)
            ->orderByDesc('volume_lots')
            ->orderBy('stock_code')
            ->paginate($perPage)
            ->withQueryString();

        return view('tw-stock.daily-prices.index', [
            'rows' => $rows,
            'stockMetrics' => $this->stockMetrics($rows->getCollection()),
            'latestDate' => $latestDate,
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
            'keyword' => $keyword,
            'summary' => $this->latestDateSummary($latestDate),
            'initialMarket' => $this->marketStatus(),
            'realtimeUrl' => route('tw-stock.daily-prices.realtime'),
            'intradayBatchUrl' => route('tw-stock.daily-prices.intraday-data'),
            'previewUrlTemplate' => route('tw-stock.daily-prices.preview', ['stockCode' => '__CODE__']),
        ]);
    }

    public function realtime(Request $request, TwStockRealtimeQuoteService $quoteService): JsonResponse
    {
        $market = $this->marketStatus();
        if (!$market['isOpen']) {
            return response()->json([
                'servedAt' => $market['now'],
                'market' => $market,
                'source' => ['status' => 'closed', 'label' => '盤後正式日價'],
                'rows' => [],
                'summary' => null,
            ]);
        }

        $latestDate = $this->latestTradeDate();
        $query = TwStockDailyPrice::query()
            ->when($latestDate !== null, fn ($query) => $query->where('trade_date', $latestDate));
        $keyword = trim((string) $request->query('q', ''));
        if ($keyword !== '') {
            $query->where(function ($query) use ($keyword): void {
                $query->where('stock_code', 'like', '%' . $keyword . '%')
                    ->orWhere('stock_name', 'like', '%' . $keyword . '%');
            });
        }

        $storedRows = $query
            ->get([
                'exchange',
                'stock_code',
                'stock_name',
                'close_price',
                'volume_lots',
            ]);
        $quotePayload = $quoteService->officialMarketQuotes($storedRows
            ->map(fn (TwStockDailyPrice $row): array => [
                'code' => (string) $row->stock_code,
                'exchange' => (string) $row->exchange,
            ])
            ->all());
        $quotes = is_array($quotePayload['quotes'] ?? null) ? $quotePayload['quotes'] : [];
        $today = CarbonImmutable::now((string) config('app.timezone'))->toDateString();

        $rankingRows = $storedRows->map(function (TwStockDailyPrice $storedRow) use ($quotes, $today): array {
            $quote = is_array($quotes[(string) $storedRow->stock_code] ?? null)
                ? $quotes[(string) $storedRow->stock_code]
                : null;
            $quotedAt = $this->quoteTimestamp($quote['quotedAt'] ?? null);
            $lastPrice = $this->positiveFloat($quote['lastPrice'] ?? null);
            $previousClose = $this->positiveFloat($quote['previousClose'] ?? null)
                ?? $this->positiveFloat($storedRow->close_price);
            $isRealtime = $quote !== null
                && $quotedAt?->toDateString() === $today
                && $lastPrice !== null
                && $previousClose !== null;
            $closePrice = $isRealtime ? $lastPrice : $previousClose;
            $priceChangeAmount = $isRealtime ? $closePrice - $previousClose : 0.0;
            $priceChangePercent = $previousClose !== null && $previousClose > 0
                ? $priceChangeAmount / $previousClose * 100
                : 0.0;

            return [
                'exchange' => (string) $storedRow->exchange,
                'stock_code' => (string) $storedRow->stock_code,
                'stock_name' => (string) $storedRow->stock_name,
                'close_price' => $closePrice,
                'previous_close_price' => $previousClose,
                'price_change_amount' => $priceChangeAmount,
                'price_change_percent' => $priceChangePercent,
                'volume_lots' => $isRealtime
                    ? max(0, (int) round((float) ($quote['volumeLots'] ?? 0)))
                    : 0,
                'trade_date' => $today,
                'quoted_at' => $quotedAt?->toIso8601String(),
                'is_realtime' => $isRealtime,
            ];
        })->all();

        $sort = (string) $request->query('sort', 'change');
        if (!in_array($sort, ['change', 'volume', 'price', 'amount'], true)) {
            $sort = 'change';
        }
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';
        $sortKey = match ($sort) {
            'volume' => 'volume_lots',
            'price' => 'close_price',
            'amount' => 'price_change_amount',
            default => 'price_change_percent',
        };
        usort($rankingRows, function (array $left, array $right) use ($sortKey, $direction): int {
            $comparison = ((float) $left[$sortKey]) <=> ((float) $right[$sortKey]);
            if ($comparison !== 0) {
                return $direction === 'asc' ? $comparison : -$comparison;
            }

            $volumeComparison = ((int) $right['volume_lots']) <=> ((int) $left['volume_lots']);

            return $volumeComparison !== 0
                ? $volumeComparison
                : strcmp((string) $left['stock_code'], (string) $right['stock_code']);
        });

        $allowedPerPage = [50, 100, 200, 500];
        $perPage = (int) $request->query('per_page', 100);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 100;
        }
        $total = count($rankingRows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($lastPage, (int) $request->query('page', 1)));
        $pageRows = array_slice($rankingRows, ($page - 1) * $perPage, $perPage);
        $models = TwStockDailyPrice::hydrate($pageRows);
        $metrics = $this->stockMetrics($models);

        foreach ($pageRows as $index => &$row) {
            $row['rank'] = (($page - 1) * $perPage) + $index + 1;
            $row['metrics'] = $metrics[$this->stockKey((string) $row['exchange'], (string) $row['stock_code'])] ?? null;
            $row['detail_url'] = route('tw-stock.daily-prices.show', [
                'stockCode' => $row['stock_code'],
                'exchange' => $row['exchange'],
            ]);
        }
        unset($row);

        $changes = array_column($rankingRows, 'price_change_percent');

        return response()->json([
            'servedAt' => $quotePayload['servedAt'] ?? $market['now'],
            'market' => $market,
            'source' => $quotePayload['source'] ?? ['status' => 'unavailable', 'label' => '證交所即時報價'],
            'rows' => $pageRows,
            'summary' => [
                'total' => $total,
                'up' => count(array_filter($changes, fn (float|int $change): bool => $change > 0)),
                'down' => count(array_filter($changes, fn (float|int $change): bool => $change < 0)),
                'flat' => count(array_filter($changes, fn (float|int $change): bool => (float) $change === 0.0)),
                'maxChange' => $changes === [] ? null : max($changes),
                'minChange' => $changes === [] ? null : min($changes),
            ],
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => $lastPage,
            ],
        ]);
    }

    public function intraday(Request $request, string $stockCode, TwStockRealtimeQuoteService $quoteService): JsonResponse
    {
        $date = $this->requestedIntradayDate($request);

        return response()->json([
            ...$quoteService->intradayPrices([$stockCode], $date),
            'market' => $this->marketStatus(),
        ]);
    }

    public function intradayBatch(Request $request, TwStockRealtimeQuoteService $quoteService): JsonResponse
    {
        $codes = collect(explode(',', (string) $request->query('codes', '')))
            ->map(fn (string $code): string => strtoupper(trim($code)))
            ->filter(fn (string $code): bool => preg_match('/^[A-Z0-9]{2,12}$/', $code) === 1)
            ->unique()
            ->take(100)
            ->values()
            ->all();
        $date = $this->requestedIntradayDate($request);

        return response()->json([
            ...$quoteService->intradayPrices($codes, $date),
            'market' => $this->marketStatus(),
        ]);
    }

    public function preview(Request $request, string $stockCode): JsonResponse
    {
        $exchange = trim((string) $request->query('exchange', ''));
        $query = TwStockDailyPrice::query()->where('stock_code', $stockCode);
        if ($exchange !== '') {
            $query->where('exchange', $exchange);
        }
        $latest = $query->orderByDesc('trade_date')->firstOrFail();
        $rows = $this->stockDailyRows((string) $latest->exchange, (string) $latest->stock_code)
            ->take(-10)
            ->map(fn (TwStockDailyPrice $row): array => [
                'time' => $row->trade_date->toDateString(),
                'open' => (float) ($row->open_price ?? $row->close_price),
                'high' => (float) ($row->high_price ?? $row->close_price),
                'low' => (float) ($row->low_price ?? $row->close_price),
                'close' => (float) $row->close_price,
                'volume' => (int) $row->volume_lots,
            ])
            ->values()
            ->all();

        return response()->json([
            'stockCode' => (string) $latest->stock_code,
            'stockName' => (string) $latest->stock_name,
            'exchange' => (string) $latest->exchange,
            'rows' => $rows,
        ]);
    }

    /**
     * @param EloquentCollection<int, TwStockDailyPrice> $rows
     * @return array<string, array<string, mixed>>
     */
    private function stockMetrics(EloquentCollection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $stockCodes = $rows->pluck('stock_code')->filter()->unique()->values()->all();
        $exchanges = $rows->pluck('exchange')->filter()->unique()->values()->all();
        $quarterRows = DB::query()
            ->fromSub(function ($query) use ($stockCodes, $exchanges): void {
                $query->from('tw_stock_q1_financial_reports')
                    ->select(['exchange', 'stock_code', 'fiscal_year', 'quarter', 'q1_eps'])
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exchange, stock_code ORDER BY fiscal_year DESC, quarter DESC) as quarter_row_number')
                    ->whereIn('stock_code', $stockCodes)
                    ->whereIn('exchange', $exchanges);
            }, 'ranked_quarters')
            ->where('quarter_row_number', '<=', 4)
            ->orderBy('exchange')
            ->orderBy('stock_code')
            ->orderBy('quarter_row_number')
            ->get()
            ->groupBy(fn (object $row): string => $this->stockKey((string) $row->exchange, (string) $row->stock_code));
        $revenueRows = DB::query()
            ->fromSub(function ($query) use ($stockCodes, $exchanges): void {
                $query->from('tw_stock_monthly_revenues')
                    ->select([
                        'exchange',
                        'stock_code',
                        'revenue_year',
                        'revenue_month',
                        'monthly_revenue_thousands',
                        'year_over_year_percent',
                    ])
                    ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exchange, stock_code ORDER BY revenue_year DESC, revenue_month DESC) as revenue_row_number')
                    ->whereIn('stock_code', $stockCodes)
                    ->whereIn('exchange', $exchanges);
            }, 'ranked_revenues')
            ->where('revenue_row_number', '<=', 2)
            ->orderBy('exchange')
            ->orderBy('stock_code')
            ->orderBy('revenue_row_number')
            ->get()
            ->groupBy(fn (object $row): string => $this->stockKey((string) $row->exchange, (string) $row->stock_code));

        return $rows->mapWithKeys(function (TwStockDailyPrice $row) use ($quarterRows, $revenueRows): array {
            $key = $this->stockKey((string) $row->exchange, (string) $row->stock_code);
            $quarters = $quarterRows->get($key, collect())->values();
            $quarterCount = $quarters->count();
            $hasFourQuarters = $quarterCount === 4
                && $quarters->every(fn (object $quarter): bool => $quarter->q1_eps !== null && is_numeric($quarter->q1_eps));
            $trailingEps = $hasFourQuarters
                ? (float) $quarters->sum(fn (object $quarter): float => (float) $quarter->q1_eps)
                : null;
            $closePrice = $this->positiveFloat($row->close_price);
            $revenues = $revenueRows->get($key, collect())->values();

            return [$key => [
                'trailingPe' => $trailingEps !== null && $trailingEps > 0 && $closePrice !== null
                    ? $closePrice / $trailingEps
                    : null,
                'trailingEps' => $trailingEps,
                'trailingQuarterCount' => $quarterCount,
                'trailingPeriod' => $quarterCount === 4
                    ? sprintf(
                        '%d Q%d–%d Q%d',
                        (int) $quarters->get(3)->fiscal_year,
                        (int) $quarters->get(3)->quarter,
                        (int) $quarters->first()->fiscal_year,
                        (int) $quarters->first()->quarter,
                    )
                    : null,
                'latestRevenue' => $this->monthlyRevenueMetric($revenues->get(0)),
                'previousRevenue' => $this->monthlyRevenueMetric($revenues->get(1)),
            ]];
        })->all();
    }

    /**
     * @return array{period: string, revenueThousands: int|null, yoyPercent: float|null}|null
     */
    private function monthlyRevenueMetric(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return [
            'period' => sprintf('%04d/%02d', (int) $row->revenue_year, (int) $row->revenue_month),
            'revenueThousands' => $row->monthly_revenue_thousands === null ? null : (int) $row->monthly_revenue_thousands,
            'yoyPercent' => $row->year_over_year_percent === null ? null : (float) $row->year_over_year_percent,
        ];
    }

    private function stockKey(string $exchange, string $stockCode): string
    {
        return $exchange . '|' . $stockCode;
    }

    private function marketStatus(): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $now = CarbonImmutable::now($timezone);
        $start = $now->startOfDay()->setTime(9, 0);
        $end = $now->startOfDay()->setTime(13, 35);
        $isOpen = $now->isWeekday() && $now->betweenIncluded($start, $end);

        return [
            'isOpen' => $isOpen,
            'status' => $isOpen ? 'open' : 'closed',
            'label' => $isOpen ? '盤中即時排行' : '盤後正式排行',
            'now' => $now->toIso8601String(),
            'timezone' => $timezone,
            'refreshSeconds' => $isOpen ? self::REALTIME_REFRESH_SECONDS : null,
        ];
    }

    private function quoteTimestamp(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    public function show(Request $request, string $stockCode): View
    {
        $exchange = (string) $request->query('exchange', '');
        $query = TwStockDailyPrice::query()->where('stock_code', $stockCode);
        if ($exchange !== '') {
            $query->where('exchange', $exchange);
        }

        $latestQuery = clone $query;
        $latest = $latestQuery
            ->orderByDesc('trade_date')
            ->firstOrFail();

        $rows = $this->stockDailyRows((string) $latest->exchange, (string) $latest->stock_code);

        $chartRows = $rows->map(fn (TwStockDailyPrice $row): array => [
            'time' => $row->trade_date->toDateString(),
            'open' => $row->open_price ?? $row->close_price,
            'high' => $row->high_price ?? $row->close_price,
            'low' => $row->low_price ?? $row->close_price,
            'close' => $row->close_price,
            'volume' => $row->volume_lots,
            'changePercent' => $row->price_change_percent,
        ])->values();

        $windowRows = $rows->take(-60);

        return view('tw-stock.daily-prices.show', [
            'latest' => $latest,
            'rows' => $rows,
            'recentRows' => $windowRows,
            'chartRows' => $chartRows,
            'stats' => [
                'firstDate' => $rows->first()?->trade_date?->toDateString(),
                'lastDate' => $rows->last()?->trade_date?->toDateString(),
                'rowCount' => $rows->count(),
                'high' => $rows->max('high_price'),
                'low' => $rows->min('low_price'),
                'avgVolume' => (int) round((float) $rows->avg('volume_lots')),
                'rank' => $this->latestRank($latest),
            ],
        ]);
    }

    private function latestRank(TwStockDailyPrice $latest): ?int
    {
        if ($latest->price_change_percent === null) {
            return null;
        }

        $tradeDate = $latest->trade_date->toDateString();

        return (int) Cache::remember(
            'tw-stock:daily-prices:latest-rank:v1:' . sha1(serialize([
                $tradeDate,
                (float) $latest->price_change_percent,
                (int) $latest->volume_lots,
                $this->dailyPriceCacheVersion(tradeDate: $tradeDate),
            ])),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): int => TwStockDailyPrice::query()
                ->where('trade_date', $latest->trade_date)
                ->whereNotNull('price_change_percent')
                ->where(function ($query) use ($latest): void {
                    $query->where('price_change_percent', '>', $latest->price_change_percent)
                        ->orWhere(function ($query) use ($latest): void {
                            $query->where('price_change_percent', '=', $latest->price_change_percent)
                                ->where('volume_lots', '>', $latest->volume_lots);
                        });
                })
                ->count() + 1,
        );
    }

    private function latestTradeDate(): ?string
    {
        $latestDate = TwStockDailyPrice::query()->max('trade_date');
        $latestDate = $latestDate === null ? null : (string) $latestDate;

        return Cache::remember(
            'tw-stock:daily-prices:latest-date:v2:' . ($latestDate ?? 'none'),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): ?string => $latestDate,
        );
    }

    private function requestedIntradayDate(Request $request): string
    {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $today = CarbonImmutable::now($timezone)->toDateString();
        $fallback = $this->latestTradeDate() ?? $today;
        $requested = trim((string) $request->query('date', $fallback));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested) !== 1) {
            return $fallback;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $requested, $timezone);

            return $date !== false
                && $date->toDateString() === $requested
                && $date->toDateString() <= $today
                    ? $requested
                    : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * @return array{total: int, up: int, down: int, flat: int, maxChange: mixed, minChange: mixed}
     */
    private function latestDateSummary(?string $latestDate): array
    {
        if ($latestDate === null) {
            return [
                'total' => 0,
                'up' => 0,
                'down' => 0,
                'flat' => 0,
                'maxChange' => null,
                'minChange' => null,
            ];
        }

        return Cache::remember(
            'tw-stock:daily-prices:latest-summary:v1:' . $latestDate . ':' . $this->dailyPriceCacheVersion(tradeDate: $latestDate),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            function () use ($latestDate): array {
                $row = TwStockDailyPrice::query()
                    ->where('trade_date', $latestDate)
                    ->whereNotNull('price_change_percent')
                    ->selectRaw('COUNT(*) as total')
                    ->selectRaw('SUM(CASE WHEN price_change_percent > 0 THEN 1 ELSE 0 END) as up')
                    ->selectRaw('SUM(CASE WHEN price_change_percent < 0 THEN 1 ELSE 0 END) as down')
                    ->selectRaw('SUM(CASE WHEN price_change_percent = 0 THEN 1 ELSE 0 END) as flat')
                    ->selectRaw('MAX(price_change_percent) as max_change')
                    ->selectRaw('MIN(price_change_percent) as min_change')
                    ->toBase()
                    ->first();

                return [
                    'total' => (int) ($row->total ?? 0),
                    'up' => (int) ($row->up ?? 0),
                    'down' => (int) ($row->down ?? 0),
                    'flat' => (int) ($row->flat ?? 0),
                    'maxChange' => $row->max_change ?? null,
                    'minChange' => $row->min_change ?? null,
                ];
            },
        );
    }

    /**
     * @return EloquentCollection<int, TwStockDailyPrice>
     */
    private function stockDailyRows(string $exchange, string $stockCode): EloquentCollection
    {
        $records = Cache::remember(
            'tw-stock:daily-prices:stock-rows:v2:' . sha1(serialize([
                $exchange,
                $stockCode,
                $this->dailyPriceCacheVersion(exchange: $exchange, stockCode: $stockCode),
            ])),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => TwStockDailyPrice::query()
                ->where('exchange', $exchange)
                ->where('stock_code', $stockCode)
                ->orderBy('trade_date')
                ->get()
                ->map(fn (TwStockDailyPrice $row): array => $row->getAttributes())
                ->all(),
        );

        return TwStockDailyPrice::hydrate($records);
    }

    private function dailyPriceCacheVersion(?string $tradeDate = null, ?string $exchange = null, ?string $stockCode = null): string
    {
        $query = TwStockDailyPrice::query();

        if ($tradeDate !== null) {
            $query->where('trade_date', $tradeDate);
        }

        if ($exchange !== null) {
            $query->where('exchange', $exchange);
        }

        if ($stockCode !== null) {
            $query->where('stock_code', $stockCode);
        }

        $row = $query
            ->selectRaw('COUNT(*) as row_count, MAX(trade_date) as max_trade_date, MAX(updated_at) as max_updated_at, MAX(fetched_at) as max_fetched_at, MAX(id) as max_id')
            ->toBase()
            ->first();

        return implode('|', [
            (int) ($row->row_count ?? 0),
            (string) ($row->max_trade_date ?? ''),
            (string) ($row->max_updated_at ?? ''),
            (string) ($row->max_fetched_at ?? ''),
            (string) ($row->max_id ?? ''),
        ]);
    }
}
