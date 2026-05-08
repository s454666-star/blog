<?php

namespace App\Http\Controllers;

use App\Models\TwStockQ1FinancialReport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TwStockQ1FinancialReportController extends Controller
{
    public function index(Request $request): View
    {
        $allowedPerPage = [50, 250, 500];
        $perPage = (int) $request->query('per_page', 250);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 250;
        }

        $latestYear = (int) (TwStockQ1FinancialReport::query()->max('fiscal_year') ?? now()->year);
        $year = (int) $request->query('year', $latestYear);
        $search = trim((string) $request->query('q', ''));
        $priceMin = $this->priceFilterValue($request->query('price_min'));
        $priceMax = $this->priceFilterValue($request->query('price_max'));

        $baseQuery = $this->reportQuery($year, $search, $priceMin, $priceMax);
        $rows = (clone $baseQuery)
            ->orderByDesc('q1_revenue_score')
            ->orderBy('rank')
            ->orderBy('stock_code')
            ->paginate($perPage)
            ->withQueryString();

        $availableYears = TwStockQ1FinancialReport::query()
            ->select('fiscal_year')
            ->distinct()
            ->orderByDesc('fiscal_year')
            ->pluck('fiscal_year')
            ->map(fn ($value): int => (int) $value)
            ->all();

        return view('tw-stock.q1-financial-reports', [
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'year' => $year,
            'search' => $search,
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'availableYears' => $availableYears,
            'rows' => $rows,
            'totalRows' => (clone $baseQuery)->count(),
            'groupTotalRows' => TwStockQ1FinancialReport::query()
                ->where('fiscal_year', $year)
                ->where('quarter', 1)
                ->count(),
            'lastFetchedAt' => (clone $baseQuery)->max('fetched_at'),
            'latestPriceDate' => (clone $baseQuery)->max('latest_price_date'),
            'topScoreRow' => (clone $baseQuery)->orderByDesc('q1_revenue_score')->first(),
            'topRevenueRow' => (clone $baseQuery)->orderByDesc('q1_revenue_billion')->first(),
            'topGrowthRow' => (clone $baseQuery)->orderByDesc('q1_revenue_yoy_percent')->first(),
        ]);
    }

    private function reportQuery(int $year, string $search, ?float $priceMin, ?float $priceMax): Builder
    {
        return TwStockQ1FinancialReport::query()
            ->where('fiscal_year', $year)
            ->where('quarter', 1)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->where('stock_code', 'like', $like)
                        ->orWhere('stock_name', 'like', $like)
                        ->orWhere('industry', 'like', $like);
                });
            })
            ->when($priceMin !== null, fn (Builder $query): Builder => $query->where('latest_close_price', '>=', $priceMin))
            ->when($priceMax !== null, fn (Builder $query): Builder => $query->where('latest_close_price', '<=', $priceMax));
    }

    private function priceFilterValue(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return max(0.0, (float) $value);
    }
}
