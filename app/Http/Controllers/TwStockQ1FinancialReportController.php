<?php

namespace App\Http\Controllers;

use App\Models\TwStockAnnualFinancialComparison;
use App\Models\TwStockDailyPrice;
use App\Models\TwStockQ1FinancialReport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;

class TwStockQ1FinancialReportController extends Controller
{
    private const DASHBOARD_MIN_VOLUME_LOTS = 1000;

    private const RECENT_HIGH_LOOKBACK_TRADING_DAYS = 44;

    private const RECENT_HIGH_SIGNAL_TRADING_DAYS = 3;

    private const RECENT_HIGH_QUERY_MONTHS = 4;

    private const ANNUAL_COMPARISON_START_YEAR = 2020;

    private const ANNUAL_COMPARISON_END_YEAR = 2025;

    private const CURRENT_CONTEXT_YEAR = 2026;

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
        $availableValuationGroups = $this->availableValuationGroups($year);
        $valuationGroups = $this->selectedValuationGroups($request->query('valuation_groups', []), $availableValuationGroups);
        $sortableColumns = $this->sortableColumns();
        $sort = (string) $request->query('sort', 'score');
        if (!array_key_exists($sort, $sortableColumns)) {
            $sort = 'score';
        }

        $direction = strtolower((string) $request->query('direction', 'desc'));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $baseQuery = $this->reportQuery($year, $search, $priceMin, $priceMax, $valuationGroups);
        $groupTotalRows = TwStockQ1FinancialReport::query()
            ->where('fiscal_year', $year)
            ->where('quarter', 1)
            ->where('volume_lots', '>=', self::DASHBOARD_MIN_VOLUME_LOTS)
            ->whereNotNull('q1_revenue_billion')
            ->whereNotNull('q1_eps')
            ->whereNotNull('price_change_1d_percent')
            ->whereNotNull('price_change_5d_percent')
            ->whereNotNull('price_change_20d_percent')
            ->count();
        $matchingRows = $this->attachRecentTwoMonthHighFlags((clone $baseQuery)->get());
        $rows = $this->paginateRows(
            $request,
            $this->sortRows($matchingRows, $sort, $direction, $groupTotalRows),
            $perPage,
        );

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
            'valuationGroups' => $valuationGroups,
            'availableValuationGroups' => $availableValuationGroups,
            'sort' => $sort,
            'direction' => $direction,
            'sortableColumns' => $sortableColumns,
            'availableYears' => $availableYears,
            'rows' => $rows,
            'totalRows' => (clone $baseQuery)->count(),
            'groupTotalRows' => $groupTotalRows,
            'lastFetchedAt' => (clone $baseQuery)->max('fetched_at'),
            'latestPriceDate' => (clone $baseQuery)->max('latest_price_date'),
            'topScoreRow' => (clone $baseQuery)->orderByDesc('q1_revenue_score')->first(),
            'topRevenueRow' => (clone $baseQuery)->orderByDesc('q1_revenue_billion')->first(),
            'topGrowthRow' => (clone $baseQuery)->orderByDesc('q1_revenue_yoy_percent')->first(),
        ]);
    }

    public function annualComparison(Request $request): View
    {
        $allowedPerPage = [50, 100, 200, 500];
        $perPage = (int) $request->query('per_page', 100);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 100;
        }

        $sort = (string) $request->query('sort', 'eps');
        if (!in_array($sort, ['revenue', 'eps'], true)) {
            $sort = 'eps';
        }

        $filters = collect([
            'revenue_growth',
            'eps_growth',
            'current_q1_eps_yoy',
            'end_year_revenue_yoy',
            'current_q1_revenue_yoy',
            'recent_two_month_high',
            'net_margin',
        ])
            ->filter(fn (string $filter): bool => $request->boolean($filter))
            ->values()
            ->all();
        $search = trim((string) $request->query('q', ''));

        $baseQuery = TwStockAnnualFinancialComparison::query()
            ->where('context_year', self::CURRENT_CONTEXT_YEAR)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->where('stock_code', 'like', $like)
                        ->orWhere('stock_name', 'like', $like);
                });
            });
        $recentTwoMonthHighKeys = $this->recentTwoMonthHighKeys((clone $baseQuery)->get(['exchange', 'stock_code']));

        foreach ($filters as $filter) {
            match ($filter) {
                'revenue_growth' => $baseQuery->where('revenue_filter_pass', true),
                'eps_growth' => $baseQuery->where('eps_filter_pass', true),
                'current_q1_eps_yoy' => $baseQuery->where('current_q1_eps_yoy_percent', '>', 5),
                'end_year_revenue_yoy' => $baseQuery->where('end_year_revenue_yoy_percent', '>', 15),
                'current_q1_revenue_yoy' => $baseQuery->where('current_q1_revenue_yoy_percent', '>', 8),
                'recent_two_month_high' => $this->applyStockKeyFilter($baseQuery, $recentTwoMonthHighKeys),
                'net_margin' => $baseQuery->where('net_margin_filter_pass', true),
                default => null,
            };
        }

        $sortColumn = $sort === 'eps' ? 'eps_yoy_sum' : 'revenue_yoy_sum';
        $stocks = (clone $baseQuery)
            ->orderByRaw($sortColumn . ' IS NULL')
            ->orderByDesc($sortColumn)
            ->orderBy('stock_code')
            ->paginate($perPage)
            ->withQueryString();

        return view('tw-stock.annual-comparison', [
            'stocks' => $stocks,
            'sort' => $sort,
            'filters' => $filters,
            'search' => $search,
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'summary' => [
                'total' => (clone $baseQuery)->count(),
                'revenuePass' => (clone $baseQuery)->where('revenue_filter_pass', true)->count(),
                'epsPass' => (clone $baseQuery)->where('eps_filter_pass', true)->count(),
                'currentQ1EpsYoyPass' => (clone $baseQuery)->where('current_q1_eps_yoy_percent', '>', 5)->count(),
                'endYearRevenueYoyPass' => (clone $baseQuery)->where('end_year_revenue_yoy_percent', '>', 15)->count(),
                'currentQ1RevenueYoyPass' => (clone $baseQuery)->where('current_q1_revenue_yoy_percent', '>', 8)->count(),
                'recentTwoMonthHighPass' => $this->applyStockKeyFilter(clone $baseQuery, $recentTwoMonthHighKeys)->count(),
                'netMarginPass' => (clone $baseQuery)->where('net_margin_filter_pass', true)->count(),
            ],
            'recentTwoMonthHighKeys' => $recentTwoMonthHighKeys,
            'years' => range(self::ANNUAL_COMPARISON_START_YEAR + 1, self::ANNUAL_COMPARISON_END_YEAR),
        ]);
    }

    /**
     * @param list<string> $valuationGroups
     */
    private function reportQuery(int $year, string $search, ?float $priceMin, ?float $priceMax, array $valuationGroups): Builder
    {
        return TwStockQ1FinancialReport::query()
            ->where('fiscal_year', $year)
            ->where('quarter', 1)
            ->where('volume_lots', '>=', self::DASHBOARD_MIN_VOLUME_LOTS)
            ->whereNotNull('q1_revenue_billion')
            ->whereNotNull('q1_eps')
            ->whereNotNull('price_change_1d_percent')
            ->whereNotNull('price_change_5d_percent')
            ->whereNotNull('price_change_20d_percent')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->where('stock_code', 'like', $like)
                        ->orWhere('stock_name', 'like', $like)
                        ->orWhere('industry', 'like', $like);
                });
            })
            ->when($priceMin !== null, fn (Builder $query): Builder => $query->where('latest_close_price', '>=', $priceMin))
            ->when($priceMax !== null, fn (Builder $query): Builder => $query->where('latest_close_price', '<=', $priceMax))
            ->when($valuationGroups !== [], fn (Builder $query): Builder => $query->whereIn('valuation_group', $valuationGroups));
    }

    /**
     * @return array<string, string>
     */
    private function sortableColumns(): array
    {
        return [
            'rank' => '排名',
            'group' => '分組',
            'stock' => '股票',
            'score' => 'Q1整體財報評分',
            'revenue' => 'Q1營收(億)',
            'revenue_yoy' => '營收YoY',
            'eps' => 'Q1 EPS',
            'eps_yoy' => 'EPS YoY',
            'gross_margin' => '毛利率',
            'operating_margin' => '營益率',
            'net_margin' => '淨利率',
            'roe' => 'ROE',
            'recent_two_month_high' => '近3日兩月新高',
            'price' => '股價',
            'expected_price' => '預期股價',
            'change_1d' => '1日',
            'change_5d' => '5日',
            'change_20d' => '20日',
            'month_1' => '近1月營收',
            'month_2' => '近2月營收',
            'month_3' => '近3月營收',
            'month_4' => '近4月營收',
        ];
    }

    /**
     * @param Collection<int, TwStockQ1FinancialReport> $rows
     * @return Collection<int, TwStockQ1FinancialReport>
     */
    private function sortRows(Collection $rows, string $sort, string $direction, int $groupTotalRows): Collection
    {
        return $rows
            ->sort(function (TwStockQ1FinancialReport $left, TwStockQ1FinancialReport $right) use ($sort, $direction, $groupTotalRows): int {
                $leftValue = $this->sortValue($left, $sort, $groupTotalRows);
                $rightValue = $this->sortValue($right, $sort, $groupTotalRows);
                $comparison = $this->compareSortValues($leftValue, $rightValue);

                if ($comparison !== 0) {
                    if ($leftValue === null || $rightValue === null) {
                        return $comparison;
                    }

                    return $direction === 'asc' ? $comparison : -$comparison;
                }

                return $this->compareSortValues((int) $left->rank, (int) $right->rank)
                    ?: strcmp((string) $left->stock_code, (string) $right->stock_code);
            })
            ->values();
    }

    private function sortValue(TwStockQ1FinancialReport $row, string $sort, int $groupTotalRows): mixed
    {
        return match ($sort) {
            'rank' => (int) $row->rank,
            'group' => $this->groupSortValue((int) $row->rank, $groupTotalRows),
            'stock' => (string) $row->stock_code,
            'score' => $this->numericSortValue($row->q1_revenue_score),
            'revenue' => $this->numericSortValue($row->q1_revenue_billion),
            'revenue_yoy' => $this->numericSortValue($row->q1_revenue_yoy_percent),
            'eps' => $this->numericSortValue($row->q1_eps),
            'eps_yoy' => $this->numericSortValue($row->q1_eps_yoy_percent),
            'gross_margin' => $this->numericSortValue($row->q1_gross_margin_percent),
            'operating_margin' => $this->numericSortValue($row->q1_operating_margin_percent),
            'net_margin' => $this->numericSortValue($row->q1_net_margin_percent),
            'roe' => $this->numericSortValue($row->roe_percent),
            'recent_two_month_high' => $row->getAttribute('recent_two_month_high') ? 1 : 0,
            'price' => $this->numericSortValue($row->latest_close_price),
            'expected_price' => $this->numericSortValue($row->expectedPriceChangePercent()),
            'change_1d' => $this->numericSortValue($row->price_change_1d_percent),
            'change_5d' => $this->numericSortValue($row->price_change_5d_percent),
            'change_20d' => $this->numericSortValue($row->price_change_20d_percent),
            'month_1' => $this->monthlyRevenueSortValue($row, 0),
            'month_2' => $this->monthlyRevenueSortValue($row, 1),
            'month_3' => $this->monthlyRevenueSortValue($row, 2),
            'month_4' => $this->monthlyRevenueSortValue($row, 3),
            default => $this->numericSortValue($row->q1_revenue_score),
        };
    }

    private function compareSortValues(mixed $left, mixed $right): int
    {
        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        return strnatcasecmp((string) $left, (string) $right);
    }

    private function numericSortValue(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @param Collection<int, TwStockQ1FinancialReport> $rows
     * @return Collection<int, TwStockQ1FinancialReport>
     */
    private function attachRecentTwoMonthHighFlags(Collection $rows): Collection
    {
        $recentTwoMonthHighKeys = $this->recentTwoMonthHighKeys($rows);

        return $rows->each(function ($row) use ($recentTwoMonthHighKeys): void {
            $row->setAttribute(
                'recent_two_month_high',
                isset($recentTwoMonthHighKeys[$this->stockKey((string) $row->exchange, (string) $row->stock_code)]),
            );
        });
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<string, true>
     */
    private function recentTwoMonthHighKeys(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $stockCodes = $rows
            ->pluck('stock_code')
            ->map(fn ($value): string => (string) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $exchanges = $rows
            ->pluck('exchange')
            ->map(fn ($value): string => (string) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($stockCodes === [] || $exchanges === []) {
            return [];
        }

        $latestDate = TwStockDailyPrice::query()
            ->whereIn('stock_code', $stockCodes)
            ->whereIn('exchange', $exchanges)
            ->max('trade_date');
        if ($latestDate === null) {
            return [];
        }

        $startDate = Carbon::parse($latestDate)
            ->subMonths(self::RECENT_HIGH_QUERY_MONTHS)
            ->toDateString();
        $dailyRowsByStock = TwStockDailyPrice::query()
            ->whereIn('stock_code', $stockCodes)
            ->whereIn('exchange', $exchanges)
            ->where('trade_date', '>=', $startDate)
            ->whereNotNull('high_price')
            ->orderBy('exchange')
            ->orderBy('stock_code')
            ->orderByDesc('trade_date')
            ->get(['exchange', 'stock_code', 'trade_date', 'high_price'])
            ->groupBy(fn (TwStockDailyPrice $row): string => $this->stockKey((string) $row->exchange, (string) $row->stock_code));

        $flags = [];
        foreach ($rows as $row) {
            $stockKey = $this->stockKey((string) $row->exchange, (string) $row->stock_code);
            $dailyRows = $dailyRowsByStock
                ->get($stockKey, collect())
                ->take(self::RECENT_HIGH_LOOKBACK_TRADING_DAYS)
                ->values();

            if ($dailyRows->isEmpty()) {
                continue;
            }

            $lookbackHigh = $dailyRows->max(fn (TwStockDailyPrice $dailyRow): float => (float) $dailyRow->high_price);
            $recentHigh = $dailyRows
                ->take(self::RECENT_HIGH_SIGNAL_TRADING_DAYS)
                ->max(fn (TwStockDailyPrice $dailyRow): float => (float) $dailyRow->high_price);

            if ($recentHigh !== null && $lookbackHigh !== null && (float) $recentHigh >= (float) $lookbackHigh) {
                $flags[$stockKey] = true;
            }
        }

        return $flags;
    }

    /**
     * @param array<string, true> $stockKeys
     */
    private function applyStockKeyFilter(Builder $query, array $stockKeys): Builder
    {
        if ($stockKeys === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($stockKeys): void {
            foreach (array_keys($stockKeys) as $stockKey) {
                [$exchange, $stockCode] = explode('|', $stockKey, 2);
                $query->orWhere(function (Builder $query) use ($exchange, $stockCode): void {
                    $query->where('exchange', $exchange)
                        ->where('stock_code', $stockCode);
                });
            }
        });
    }

    private function stockKey(string $exchange, string $stockCode): string
    {
        return $exchange . '|' . $stockCode;
    }

    private function groupSortValue(int $rank, int $total): int
    {
        $total = max(1, $total);
        $frontCutoff = (int) ceil($total / 3);
        $middleCutoff = (int) ceil($total * 2 / 3);

        return match (true) {
            $rank <= $frontCutoff => 1,
            $rank <= $middleCutoff => 2,
            default => 3,
        };
    }

    private function monthlyRevenueSortValue(TwStockQ1FinancialReport $row, int $index): ?float
    {
        $monthlyRevenueRows = collect($row->recent_monthly_revenues ?? [])->values();
        $monthlyRevenue = $monthlyRevenueRows->get($index);
        if (!is_array($monthlyRevenue)) {
            return null;
        }

        return $this->numericSortValue($monthlyRevenue['revenue_yoy_percent'] ?? null);
    }

    /**
     * @param Collection<int, TwStockQ1FinancialReport> $rows
     * @return LengthAwarePaginator<int, TwStockQ1FinancialReport>
     */
    private function paginateRows(Request $request, Collection $rows, int $perPage): LengthAwarePaginator
    {
        $page = max(1, LengthAwarePaginator::resolveCurrentPage());

        return new LengthAwarePaginator(
            $rows->slice(($page - 1) * $perPage, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    private function priceFilterValue(mixed $value): ?float
    {
        $value = trim((string) $value);
        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return max(0.0, (float) $value);
    }

    /**
     * @return list<string>
     */
    private function availableValuationGroups(int $year): array
    {
        return TwStockQ1FinancialReport::query()
            ->where('fiscal_year', $year)
            ->where('quarter', 1)
            ->where('volume_lots', '>=', self::DASHBOARD_MIN_VOLUME_LOTS)
            ->whereNotNull('q1_revenue_billion')
            ->whereNotNull('q1_eps')
            ->whereNotNull('price_change_1d_percent')
            ->whereNotNull('price_change_5d_percent')
            ->whereNotNull('price_change_20d_percent')
            ->whereNotNull('valuation_group')
            ->select('valuation_group')
            ->distinct()
            ->orderBy('valuation_group')
            ->pluck('valuation_group')
            ->map(fn ($value): string => (string) $value)
            ->values()
            ->all();
    }

    /**
     * @param list<string> $availableValuationGroups
     * @return list<string>
     */
    private function selectedValuationGroups(mixed $value, array $availableValuationGroups): array
    {
        $values = is_array($value) ? $value : [$value];
        $available = array_fill_keys($availableValuationGroups, true);

        return collect($values)
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '' && isset($available[$item]))
            ->unique()
            ->values()
            ->all();
    }
}
