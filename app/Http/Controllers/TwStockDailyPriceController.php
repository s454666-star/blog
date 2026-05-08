<?php

namespace App\Http\Controllers;

use App\Models\TwStockDailyPrice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TwStockDailyPriceController extends Controller
{
    public function index(Request $request): View
    {
        $latestDate = TwStockDailyPrice::query()->max('trade_date');
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

        $baseQuery = TwStockDailyPrice::query()
            ->when($latestDate !== null, fn ($query) => $query->where('trade_date', $latestDate))
            ->whereNotNull('price_change_percent');

        return view('tw-stock.daily-prices.index', [
            'rows' => $rows,
            'latestDate' => $latestDate,
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'sort' => $sort,
            'direction' => $direction,
            'keyword' => $keyword,
            'summary' => [
                'total' => (clone $baseQuery)->count(),
                'up' => (clone $baseQuery)->where('price_change_percent', '>', 0)->count(),
                'down' => (clone $baseQuery)->where('price_change_percent', '<', 0)->count(),
                'flat' => (clone $baseQuery)->where('price_change_percent', '=', 0)->count(),
                'maxChange' => (clone $baseQuery)->max('price_change_percent'),
                'minChange' => (clone $baseQuery)->min('price_change_percent'),
            ],
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

        $rows = TwStockDailyPrice::query()
            ->where('exchange', $latest->exchange)
            ->where('stock_code', $latest->stock_code)
            ->orderBy('trade_date')
            ->get();

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

        return TwStockDailyPrice::query()
            ->where('trade_date', $latest->trade_date)
            ->whereNotNull('price_change_percent')
            ->where(function ($query) use ($latest): void {
                $query->where('price_change_percent', '>', $latest->price_change_percent)
                    ->orWhere(function ($query) use ($latest): void {
                        $query->where('price_change_percent', '=', $latest->price_change_percent)
                            ->where('volume_lots', '>', $latest->volume_lots);
                    });
            })
            ->count() + 1;
    }
}
