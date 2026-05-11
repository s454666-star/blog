<?php

namespace App\Http\Controllers;

use App\Models\TwStockDailyPrice;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TwStockDailyPriceController extends Controller
{
    private const STOCK_CACHE_TTL_SECONDS = 43200;

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
            'latestDate' => $latestDate,
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
            'keyword' => $keyword,
            'summary' => $this->latestDateSummary($latestDate),
        ]);
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
        return Cache::remember(
            'tw-stock:daily-prices:latest-date:v1:' . $this->dailyPriceCacheVersion(),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): ?string => TwStockDailyPrice::query()->max('trade_date'),
        );
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
                $baseQuery = TwStockDailyPrice::query()
                    ->where('trade_date', $latestDate)
                    ->whereNotNull('price_change_percent');

                return [
                    'total' => (clone $baseQuery)->count(),
                    'up' => (clone $baseQuery)->where('price_change_percent', '>', 0)->count(),
                    'down' => (clone $baseQuery)->where('price_change_percent', '<', 0)->count(),
                    'flat' => (clone $baseQuery)->where('price_change_percent', '=', 0)->count(),
                    'maxChange' => (clone $baseQuery)->max('price_change_percent'),
                    'minChange' => (clone $baseQuery)->min('price_change_percent'),
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
