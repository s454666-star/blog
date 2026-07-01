<?php

namespace App\Http\Controllers;

use App\Models\TwStockMonthlyRevenue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TwStockMonthlyRevenueController extends Controller
{
    private const DEFAULT_MOM_THRESHOLD = 30.0;

    private const DEFAULT_YOY_THRESHOLD = 30.0;

    private const DEFAULT_SUM_THRESHOLD = 60.0;

    private const SORTS = [
        'stock' => 'stock_code',
        'exchange' => 'exchange',
        'revenue' => 'monthly_revenue_thousands',
        'mom' => 'month_over_month_percent',
        'yoy' => 'year_over_year_percent',
        'sum' => 'mom_yoy_sum_percent',
        'cumulative' => 'cumulative_yoy_percent',
        'day_change' => 'one_day_change_percent',
        'five_day' => 'five_day_change_percent',
    ];

    public function index(Request $request): View
    {
        [$year, $month] = $this->resolvePeriod($request);
        [$sort, $direction] = $this->resolveSort($request);
        $thresholds = [
            'mom' => $this->threshold($request->query('mom_gt'), self::DEFAULT_MOM_THRESHOLD),
            'yoy' => $this->threshold($request->query('yoy_gt'), self::DEFAULT_YOY_THRESHOLD),
            'sum' => $this->threshold($request->query('sum_gt'), self::DEFAULT_SUM_THRESHOLD),
        ];

        $baseQuery = TwStockMonthlyRevenue::query()
            ->where('revenue_year', $year)
            ->where('revenue_month', $month)
            ->whereNotNull('monthly_revenue_thousands')
            ->whereNotNull('month_over_month_percent')
            ->whereNotNull('year_over_year_percent')
            ->whereNotNull('mom_yoy_sum_percent')
            ->where('month_over_month_percent', '>', $thresholds['mom'])
            ->where('year_over_year_percent', '>', $thresholds['yoy'])
            ->where('mom_yoy_sum_percent', '>', $thresholds['sum']);

        $totalMatches = (clone $baseQuery)->count();
        $rows = $baseQuery
            ->orderBy(self::SORTS[$sort], $direction)
            ->orderByDesc('monthly_revenue_thousands')
            ->orderBy('stock_code')
            ->limit(100)
            ->get();

        $periodRows = TwStockMonthlyRevenue::query()
            ->where('revenue_year', $year)
            ->where('revenue_month', $month)
            ->get();

        return view('tw-stock.monthly-revenue-rankings', [
            'rows' => $rows,
            'year' => $year,
            'month' => $month,
            'availablePeriods' => $this->availablePeriods(),
            'thresholds' => $thresholds,
            'sort' => $sort,
            'direction' => $direction,
            'totalMatches' => $totalMatches,
            'summary' => $this->summary($periodRows, $rows, $totalMatches),
        ]);
    }

    /**
     * @return array{int, int}
     */
    private function resolvePeriod(Request $request): array
    {
        $period = trim((string) $request->query('period', ''));
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $period, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            if ($year >= 2000 && $month >= 1 && $month <= 12) {
                return [$year, $month];
            }
        }

        $year = (int) $request->query('year', 0);
        $month = (int) $request->query('month', 0);
        if ($year >= 2000 && $month >= 1 && $month <= 12) {
            return [$year, $month];
        }

        $latest = TwStockMonthlyRevenue::query()
            ->selectRaw('revenue_year, revenue_month')
            ->orderByDesc('revenue_year')
            ->orderByDesc('revenue_month')
            ->first();

        if ($latest !== null) {
            return [(int) $latest->revenue_year, (int) $latest->revenue_month];
        }

        $default = now((string) config('app.timezone'))->subMonthNoOverflow();

        return [(int) $default->year, (int) $default->month];
    }

    /**
     * @return array{string, string}
     */
    private function resolveSort(Request $request): array
    {
        $sort = (string) $request->query('sort', 'sum');
        if (!array_key_exists($sort, self::SORTS)) {
            $sort = 'sum';
        }

        $direction = strtolower((string) $request->query('direction', 'desc'));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        return [$sort, $direction];
    }

    private function threshold(mixed $value, float $default): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        return max(-9999.0, min(99999.0, (float) $value));
    }

    /**
     * @return Collection<int, array{year: int, month: int, label: string}>
     */
    private function availablePeriods(): Collection
    {
        return TwStockMonthlyRevenue::query()
            ->selectRaw('revenue_year, revenue_month')
            ->groupBy('revenue_year', 'revenue_month')
            ->orderByDesc('revenue_year')
            ->orderByDesc('revenue_month')
            ->limit(18)
            ->get()
            ->map(fn (TwStockMonthlyRevenue $row): array => [
                'year' => (int) $row->revenue_year,
                'month' => (int) $row->revenue_month,
                'label' => sprintf('%04d/%02d', (int) $row->revenue_year, (int) $row->revenue_month),
            ]);
    }

    /**
     * @param Collection<int, TwStockMonthlyRevenue> $periodRows
     * @param Collection<int, TwStockMonthlyRevenue> $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $periodRows, Collection $rows, int $totalMatches): array
    {
        $latestAnnouncedDate = $periodRows
            ->pluck('announced_date')
            ->filter()
            ->max();
        $latestPriceDate = $periodRows
            ->pluck('latest_price_date')
            ->filter()
            ->max();

        return [
            'announced_count' => $periodRows->count(),
            'twse_count' => $periodRows->where('exchange', 'TWSE')->count(),
            'tpex_count' => $periodRows->where('exchange', 'TPEx')->count(),
            'visible_count' => $rows->count(),
            'total_matches' => $totalMatches,
            'latest_announced_date' => $latestAnnouncedDate?->toDateString(),
            'latest_price_date' => $latestPriceDate?->toDateString(),
            'max_sum' => $rows->max('mom_yoy_sum_percent'),
            'max_revenue' => $rows->max('monthly_revenue_thousands'),
        ];
    }
}
