<?php

namespace App\Http\Controllers;

use App\Models\TwStockAnnualFinancialComparison;
use App\Models\TwStockCompanyProfile;
use App\Models\TwStockDailyPrice;
use App\Models\TwStockDailyTurnoverRate;
use App\Models\TwStockQ1FinancialReport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TwStockQ1FinancialReportController extends Controller
{
    private const DASHBOARD_MIN_VOLUME_LOTS = 1000;

    private const RECENT_HIGH_LOOKBACK_TRADING_DAYS = 44;

    private const RECENT_HIGH_SIGNAL_TRADING_DAYS = 3;

    private const RECENT_HIGH_QUERY_MONTHS = 4;

    private const ANNUAL_COMPARISON_START_YEAR = 2020;

    private const ANNUAL_COMPARISON_END_YEAR = 2025;

    private const CURRENT_CONTEXT_YEAR = 2026;

    private const ANNUAL_REVENUE_WEIGHTED_YOY_THRESHOLD = 52.0;

    private const ANNUAL_EPS_WEIGHTED_YOY_THRESHOLD = 34.0;

    private const ANNUAL_CURRENT_Q1_EPS_YOY_THRESHOLD = 5.0;

    private const ANNUAL_END_YEAR_REVENUE_YOY_THRESHOLD = 15.0;

    private const ANNUAL_CURRENT_Q1_REVENUE_YOY_THRESHOLD = 7.0;

    private const ANNUAL_NET_MARGIN_THRESHOLD = 15.0;

    private const ANNUAL_WEEKLY_TURNOVER_THRESHOLD = 5.0;

    private const STOCK_CACHE_TTL_SECONDS = 43200;

    /**
     * @var array<string, string>
     */
    private array $q1CacheVersionMemo = [];

    public function index(Request $request): View
    {
        $allowedPerPage = [50, 250, 500];
        $perPage = (int) $request->query('per_page', 250);
        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 250;
        }

        $latestYear = $this->latestQ1Year();
        $year = (int) $request->query('year', $latestYear);
        $search = trim((string) $request->query('q', ''));
        $priceMin = $this->priceFilterValue($request->query('price_min'));
        $priceMax = $this->priceFilterValue($request->query('price_max'));
        $availableValuationGroups = $this->availableValuationGroups($year);
        $valuationGroups = $this->selectedValuationGroups($request->query('valuation_groups', []), $availableValuationGroups);
        $recentTwoMonthHighOnly = $request->boolean('recent_two_month_high');
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
        $groupTotalRows = $this->groupTotalRows($year);
        $matchingRows = $this->attachRecentTwoMonthHighFlags((clone $baseQuery)->get());
        if ($recentTwoMonthHighOnly) {
            $matchingRows = $matchingRows
                ->filter(fn (TwStockQ1FinancialReport $row): bool => (bool) $row->getAttribute('recent_two_month_high'))
                ->values();
        }

        $latestCreatedStockKeys = $this->latestCreatedStockKeys($year);
        $latestCreatedRowsCount = $this->latestCreatedRowsCount($matchingRows, $latestCreatedStockKeys);

        $rows = $this->paginateRows(
            $request,
            $this->sortRows($matchingRows, $sort, $direction, $groupTotalRows),
            $perPage,
        );

        $availableYears = $this->availableQ1Years();

        return view('tw-stock.q1-financial-reports', [
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'year' => $year,
            'search' => $search,
            'priceMin' => $priceMin,
            'priceMax' => $priceMax,
            'valuationGroups' => $valuationGroups,
            'availableValuationGroups' => $availableValuationGroups,
            'recentTwoMonthHighOnly' => $recentTwoMonthHighOnly,
            'sort' => $sort,
            'direction' => $direction,
            'sortableColumns' => $sortableColumns,
            'availableYears' => $availableYears,
            'rows' => $rows,
            'totalRows' => $matchingRows->count(),
            'groupTotalRows' => $groupTotalRows,
            'lastFetchedAt' => $matchingRows->max('fetched_at'),
            'latestPriceDate' => $matchingRows->max('latest_price_date'),
            'latestCreatedStockKeys' => $latestCreatedStockKeys,
            'latestCreatedRowsCount' => $latestCreatedRowsCount,
            'topScoreRow' => $this->topQ1RowBy($matchingRows, 'q1_revenue_score'),
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
            'weekly_turnover',
        ])
            ->filter(fn (string $filter): bool => $request->boolean($filter))
            ->values()
            ->all();
        $search = trim((string) $request->query('q', ''));
        $thresholds = $this->annualComparisonThresholds($request);

        $baseRows = $this->annualComparisonBaseRows(self::CURRENT_CONTEXT_YEAR, $search);
        $weeklyTurnoverDates = $this->annualComparisonWeeklyTurnoverDates();
        $valuationGroupByStockKey = $this->annualComparisonValuationGroupMap($baseRows);
        $availableValuationGroups = $this->availableAnnualComparisonValuationGroups($valuationGroupByStockKey);
        $valuationGroups = $this->selectedValuationGroups($request->query('valuation_groups', []), $availableValuationGroups);
        $baseRows = $this->attachAnnualComparisonValuationGroups($baseRows, $valuationGroupByStockKey);
        if ($valuationGroups !== []) {
            $selectedValuationGroups = array_fill_keys($valuationGroups, true);
            $baseRows = $baseRows
                ->filter(fn (TwStockAnnualFinancialComparison $row): bool => isset($selectedValuationGroups[(string) $row->getAttribute('valuation_group')]))
                ->values();
        }
        $baseRows = $this->attachAnnualComparisonWeeklyTurnover($baseRows, $weeklyTurnoverDates);

        $recentTwoMonthHighKeys = $this->recentTwoMonthHighKeys($baseRows);
        $filteredRows = $this->filterAnnualComparisonRows($baseRows, $filters, $recentTwoMonthHighKeys, $thresholds);
        $stocks = $this->hydrateAnnualComparisonPage(
            $this->paginateRows(
                $request,
                $this->sortAnnualComparisonRows($filteredRows, $sort),
                $perPage,
            ),
            $weeklyTurnoverDates,
        );

        return view('tw-stock.annual-comparison', [
            'stocks' => $stocks,
            'sort' => $sort,
            'filters' => $filters,
            'search' => $search,
            'valuationGroups' => $valuationGroups,
            'availableValuationGroups' => $availableValuationGroups,
            'allowedPerPage' => $allowedPerPage,
            'perPage' => $perPage,
            'summary' => $this->annualComparisonSummary($filteredRows, $recentTwoMonthHighKeys, $thresholds),
            'recentTwoMonthHighKeys' => $recentTwoMonthHighKeys,
            'thresholds' => $thresholds,
            'weeklyTurnoverDates' => $weeklyTurnoverDates,
            'years' => range(self::ANNUAL_COMPARISON_START_YEAR + 1, self::ANNUAL_COMPARISON_END_YEAR),
        ]);
    }

    /**
     * @return list<string>
     */
    private function annualComparisonListColumns(): array
    {
        return [
            'id',
            'exchange',
            'stock_code',
            'stock_name',
            'revenue_yoy_sum',
            'eps_yoy_sum',
            'revenue_filter_pass',
            'eps_filter_pass',
            'net_margin_filter_pass',
            'recent_net_margin_average',
            'last_two_year_net_margin_average',
            'current_q1_eps_yoy_percent',
            'current_q1_revenue_yoy_percent',
            'end_year_revenue_yoy_percent',
        ];
    }

    /**
     * @return array<string, float>
     */
    private function annualComparisonThresholds(Request $request): array
    {
        return [
            'revenue_growth' => $this->thresholdValue($request->query('revenue_growth_threshold'), self::ANNUAL_REVENUE_WEIGHTED_YOY_THRESHOLD),
            'eps_growth' => $this->thresholdValue($request->query('eps_growth_threshold'), self::ANNUAL_EPS_WEIGHTED_YOY_THRESHOLD),
            'current_q1_eps_yoy' => $this->thresholdValue($request->query('current_q1_eps_yoy_threshold'), self::ANNUAL_CURRENT_Q1_EPS_YOY_THRESHOLD),
            'end_year_revenue_yoy' => $this->thresholdValue($request->query('end_year_revenue_yoy_threshold'), self::ANNUAL_END_YEAR_REVENUE_YOY_THRESHOLD),
            'current_q1_revenue_yoy' => $this->thresholdValue($request->query('current_q1_revenue_yoy_threshold'), self::ANNUAL_CURRENT_Q1_REVENUE_YOY_THRESHOLD),
            'net_margin' => $this->thresholdValue($request->query('net_margin_threshold'), self::ANNUAL_NET_MARGIN_THRESHOLD),
            'weekly_turnover' => $this->thresholdValue($request->query('weekly_turnover_threshold'), self::ANNUAL_WEEKLY_TURNOVER_THRESHOLD),
        ];
    }

    private function latestQ1Year(): int
    {
        $latestYear = (int) (TwStockQ1FinancialReport::query()->max('fiscal_year') ?? now()->year);

        return (int) Cache::remember(
            'tw-stock:q1:latest-year:v2:' . $latestYear,
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): int => $latestYear,
        );
    }

    /**
     * @return list<int>
     */
    private function availableQ1Years(): array
    {
        return Cache::remember(
            'tw-stock:q1:available-years:v1:' . $this->q1CacheVersion(),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => TwStockQ1FinancialReport::query()
                ->select('fiscal_year')
                ->distinct()
                ->orderByDesc('fiscal_year')
                ->pluck('fiscal_year')
                ->map(fn ($value): int => (int) $value)
                ->all(),
        );
    }

    private function groupTotalRows(int $year): int
    {
        return (int) Cache::remember(
            'tw-stock:q1:group-total:v1:' . $year . ':' . $this->q1CacheVersion($year),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): int => TwStockQ1FinancialReport::query()
                ->where('fiscal_year', $year)
                ->where('quarter', 1)
                ->where('volume_lots', '>=', self::DASHBOARD_MIN_VOLUME_LOTS)
                ->whereNotNull('q1_revenue_billion')
                ->whereNotNull('q1_eps')
                ->whereNotNull('price_change_1d_percent')
                ->whereNotNull('price_change_5d_percent')
                ->whereNotNull('price_change_20d_percent')
                ->count(),
        );
    }

    /**
     * @return Collection<int, TwStockAnnualFinancialComparison>
     */
    private function annualComparisonBaseRows(int $contextYear, string $search): Collection
    {
        $key = 'tw-stock:annual-comparison:list:v2:' . sha1(serialize([
            $contextYear,
            $search,
            $this->annualComparisonListColumns(),
            $this->annualComparisonCacheVersion($contextYear),
        ]));

        $records = Cache::remember(
            $key,
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            function () use ($contextYear, $search): array {
                return TwStockAnnualFinancialComparison::query()
                    ->where('context_year', $contextYear)
                    ->when($search !== '', function (Builder $query) use ($search): void {
                        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                        $query->where(function (Builder $query) use ($like): void {
                            $query->where('stock_code', 'like', $like)
                                ->orWhere('stock_name', 'like', $like);
                            });
                    })
                    ->get($this->annualComparisonListColumns())
                    ->map(fn (TwStockAnnualFinancialComparison $row): array => $row->getAttributes())
                    ->all();
            },
        );

        return TwStockAnnualFinancialComparison::hydrate($records);
    }

    private function q1CacheVersion(?int $year = null): string
    {
        $cacheKey = $year === null ? 'all' : 'year:' . $year;
        if (isset($this->q1CacheVersionMemo[$cacheKey])) {
            return $this->q1CacheVersionMemo[$cacheKey];
        }

        $query = TwStockQ1FinancialReport::query();

        if ($year !== null) {
            $query
                ->where('fiscal_year', $year)
                ->where('quarter', 1);
        }

        $row = $query
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as max_updated_at, MAX(fetched_at) as max_fetched_at, MAX(id) as max_id')
            ->toBase()
            ->first();

        return $this->q1CacheVersionMemo[$cacheKey] = implode('|', [
            (int) ($row->row_count ?? 0),
            (string) ($row->max_updated_at ?? ''),
            (string) ($row->max_fetched_at ?? ''),
            (string) ($row->max_id ?? ''),
        ]);
    }

    /**
     * @return array<string, true>
     */
    private function latestCreatedStockKeys(int $year): array
    {
        return Cache::remember(
            'tw-stock:q1:latest-created-stock-keys:v1:' . $year . ':' . $this->q1CacheVersion($year),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            function () use ($year): array {
                $baseQuery = $this->reportQuery($year, '', null, null, []);
                $latest = (clone $baseQuery)
                    ->selectRaw('MAX(created_at) as max_created_at, MAX(fetched_at) as max_fetched_at')
                    ->toBase()
                    ->first();

                if (($latest->max_created_at ?? null) === null) {
                    return [];
                }

                $latestCreatedAt = Carbon::parse($latest->max_created_at);
                $latestFetchedAt = ($latest->max_fetched_at ?? null) === null
                    ? null
                    : Carbon::parse($latest->max_fetched_at);

                if ($latestFetchedAt !== null && $latestCreatedAt->toDateString() !== $latestFetchedAt->toDateString()) {
                    return [];
                }

                return (clone $baseQuery)
                    ->whereDate('created_at', $latestCreatedAt->toDateString())
                    ->orderByDesc('created_at')
                    ->get(['exchange', 'stock_code'])
                    ->mapWithKeys(fn (TwStockQ1FinancialReport $row): array => [
                        $this->stockKey((string) $row->exchange, (string) $row->stock_code) => true,
                    ])
                    ->all();
            },
        );
    }

    /**
     * @param Collection<int, TwStockQ1FinancialReport> $rows
     * @param array<string, true> $latestCreatedStockKeys
     */
    private function latestCreatedRowsCount(Collection $rows, array $latestCreatedStockKeys): int
    {
        if ($latestCreatedStockKeys === []) {
            return 0;
        }

        return $rows
            ->filter(fn (TwStockQ1FinancialReport $row): bool => isset($latestCreatedStockKeys[$this->stockKey((string) $row->exchange, (string) $row->stock_code)]))
            ->count();
    }

    private function annualComparisonCacheVersion(int $contextYear): string
    {
        $row = TwStockAnnualFinancialComparison::query()
            ->where('context_year', $contextYear)
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as max_updated_at, MAX(generated_at) as max_generated_at, MAX(id) as max_id')
            ->toBase()
            ->first();

        return implode('|', [
            (int) ($row->row_count ?? 0),
            (string) ($row->max_updated_at ?? ''),
            (string) ($row->max_generated_at ?? ''),
            (string) ($row->max_id ?? ''),
        ]);
    }

    /**
     * @param list<int> $ids
     */
    private function annualComparisonIdsCacheVersion(array $ids): string
    {
        $row = TwStockAnnualFinancialComparison::query()
            ->whereIn('id', $ids)
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as max_updated_at, MAX(generated_at) as max_generated_at, MAX(id) as max_id')
            ->toBase()
            ->first();

        return implode('|', [
            (int) ($row->row_count ?? 0),
            (string) ($row->max_updated_at ?? ''),
            (string) ($row->max_generated_at ?? ''),
            (string) ($row->max_id ?? ''),
        ]);
    }

    /**
     * @param list<string> $stockCodes
     * @param list<string> $exchanges
     */
    private function companyProfileCacheVersion(array $stockCodes, array $exchanges): string
    {
        $row = TwStockCompanyProfile::query()
            ->whereIn('stock_code', $stockCodes)
            ->whereIn('exchange', $exchanges)
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as max_updated_at, MAX(fetched_at) as max_fetched_at, MAX(id) as max_id')
            ->toBase()
            ->first();

        return implode('|', [
            (int) ($row->row_count ?? 0),
            (string) ($row->max_updated_at ?? ''),
            (string) ($row->max_fetched_at ?? ''),
            (string) ($row->max_id ?? ''),
        ]);
    }

    private function thresholdValue(mixed $value, float $default): float
    {
        $value = trim((string) $value);

        return $value === '' || !is_numeric($value) ? $default : (float) $value;
    }

    /**
     * @param LengthAwarePaginator<int, TwStockAnnualFinancialComparison> $stocks
     * @param list<string> $weeklyTurnoverDates
     * @return LengthAwarePaginator<int, TwStockAnnualFinancialComparison>
     */
    private function hydrateAnnualComparisonPage(LengthAwarePaginator $stocks, array $weeklyTurnoverDates): LengthAwarePaginator
    {
        $ids = $stocks->getCollection()
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return $stocks;
        }

        $fullRowRecords = Cache::remember(
            'tw-stock:annual-comparison:full-rows:v2:' . sha1(serialize([
                $ids,
                $this->annualComparisonIdsCacheVersion($ids),
            ])),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => TwStockAnnualFinancialComparison::query()
                ->whereIn('id', $ids)
                ->get()
                ->map(fn (TwStockAnnualFinancialComparison $row): array => $row->getAttributes())
                ->all(),
        );
        $fullRows = TwStockAnnualFinancialComparison::hydrate($fullRowRecords)->keyBy('id');

        $pageRows = $this->attachAnnualComparisonWeeklyTurnover(
            $stocks->getCollection()
                ->map(fn (TwStockAnnualFinancialComparison $stock): ?TwStockAnnualFinancialComparison => $fullRows->get($stock->id))
                ->filter()
                ->values(),
            $weeklyTurnoverDates,
        );

        $stocks->setCollection($this->attachAnnualComparisonValuationMeta(
            $pageRows,
        ));

        return $stocks;
    }

    /**
     * @param Collection<int, TwStockAnnualFinancialComparison> $stocks
     * @return Collection<int, TwStockAnnualFinancialComparison>
     */
    private function attachAnnualComparisonValuationMeta(Collection $stocks): Collection
    {
        if ($stocks->isEmpty()) {
            return $stocks;
        }

        $stockCodes = $stocks
            ->pluck('stock_code')
            ->map(fn ($value): string => (string) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $exchanges = $stocks
            ->pluck('exchange')
            ->map(fn ($value): string => (string) $value)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $profileRecords = Cache::remember(
            'tw-stock:annual-comparison:valuation-profiles:v2:' . sha1(serialize([
                collect($stockCodes)->sort()->values()->all(),
                collect($exchanges)->sort()->values()->all(),
                $this->companyProfileCacheVersion($stockCodes, $exchanges),
            ])),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => TwStockCompanyProfile::query()
                ->whereIn('stock_code', $stockCodes)
                ->whereIn('exchange', $exchanges)
                ->get(['exchange', 'stock_code', 'valuation_group', 'valuation_group_pe'])
                ->map(fn (TwStockCompanyProfile $profile): array => $profile->getAttributes())
                ->all(),
        );
        $profiles = TwStockCompanyProfile::hydrate($profileRecords)
            ->keyBy(fn (TwStockCompanyProfile $profile): string => $this->stockKey((string) $profile->exchange, (string) $profile->stock_code));

        return $stocks->each(function (TwStockAnnualFinancialComparison $stock) use ($profiles): void {
            $profile = $profiles->get($this->stockKey((string) $stock->exchange, (string) $stock->stock_code));
            $valuationGroupPe = $this->nullableFloat($profile?->valuation_group_pe);
            $expectedPrice = $this->annualExpectedPrice($stock, $valuationGroupPe);

            $stock->setAttribute('valuation_group', $profile?->valuation_group);
            $stock->setAttribute('valuation_group_pe', $valuationGroupPe);
            $stock->setAttribute('expected_price', $expectedPrice);
            $stock->setAttribute('expected_price_change_percent', $this->annualExpectedPriceChangePercent($stock, $expectedPrice));
        });
    }

    /**
     * @param Collection<int, TwStockAnnualFinancialComparison> $rows
     * @return array<string, string>
     */
    private function annualComparisonValuationGroupMap(Collection $rows): array
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

        $stockKeys = $rows
            ->map(fn (TwStockAnnualFinancialComparison $row): string => $this->stockKey((string) $row->exchange, (string) $row->stock_code))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $profileRecords = Cache::remember(
            'tw-stock:annual-comparison:valuation-group-map:v1:' . sha1(serialize([
                $stockKeys,
                $this->companyProfileCacheVersion($stockCodes, $exchanges),
            ])),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => TwStockCompanyProfile::query()
                ->whereIn('stock_code', $stockCodes)
                ->whereIn('exchange', $exchanges)
                ->whereNotNull('valuation_group')
                ->get(['exchange', 'stock_code', 'valuation_group'])
                ->map(fn (TwStockCompanyProfile $profile): array => $profile->getAttributes())
                ->all(),
        );

        return TwStockCompanyProfile::hydrate($profileRecords)
            ->mapWithKeys(fn (TwStockCompanyProfile $profile): array => [
                $this->stockKey((string) $profile->exchange, (string) $profile->stock_code) => (string) $profile->valuation_group,
            ])
            ->filter(fn (string $valuationGroup): bool => $valuationGroup !== '')
            ->all();
    }

    /**
     * @param array<string, string> $valuationGroupByStockKey
     * @return list<string>
     */
    private function availableAnnualComparisonValuationGroups(array $valuationGroupByStockKey): array
    {
        return collect($valuationGroupByStockKey)
            ->values()
            ->unique()
            ->sortBy(fn (string $valuationGroup): string => $valuationGroup, SORT_NATURAL)
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, TwStockAnnualFinancialComparison> $rows
     * @param array<string, string> $valuationGroupByStockKey
     * @return Collection<int, TwStockAnnualFinancialComparison>
     */
    private function attachAnnualComparisonValuationGroups(Collection $rows, array $valuationGroupByStockKey): Collection
    {
        return $rows->each(function (TwStockAnnualFinancialComparison $row) use ($valuationGroupByStockKey): void {
            $row->setAttribute(
                'valuation_group',
                $valuationGroupByStockKey[$this->stockKey((string) $row->exchange, (string) $row->stock_code)] ?? null,
            );
        });
    }

    /**
     * @return list<string>
     */
    private function annualComparisonWeeklyTurnoverDates(): array
    {
        return Cache::remember(
            'tw-stock:annual-comparison:weekly-turnover-dates:v1:' . $this->dailyTurnoverGlobalCacheVersion(),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => collect(TwStockDailyTurnoverRate::query()
                ->select('trade_date')
                ->distinct()
                ->orderByDesc('trade_date')
                ->limit(5)
                ->pluck('trade_date'))
                ->map(fn ($date): string => Carbon::parse($date)->toDateString())
                ->reverse()
                ->values()
                ->all(),
        );
    }

    private function dailyTurnoverGlobalCacheVersion(): string
    {
        $row = TwStockDailyTurnoverRate::query()
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

    /**
     * @param Collection<int, TwStockAnnualFinancialComparison> $rows
     * @param list<string> $dates
     * @return Collection<int, TwStockAnnualFinancialComparison>
     */
    private function attachAnnualComparisonWeeklyTurnover(Collection $rows, array $dates): Collection
    {
        if ($rows->isEmpty() || $dates === []) {
            return $rows->each(function (TwStockAnnualFinancialComparison $row): void {
                $row->setAttribute('weekly_turnover_rates', []);
                $row->setAttribute('weekly_turnover_total_percent', null);
                $row->setAttribute('weekly_turnover_trading_days', 0);
            });
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
        $stockKeys = $rows
            ->map(fn (TwStockAnnualFinancialComparison $row): string => $this->stockKey((string) $row->exchange, (string) $row->stock_code))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $turnoverRecords = Cache::remember(
            'tw-stock:annual-comparison:weekly-turnover:v1:' . sha1(serialize([
                $dates,
                $stockKeys,
                $this->dailyTurnoverCacheVersion($dates, $stockCodes, $exchanges),
            ])),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => TwStockDailyTurnoverRate::query()
                ->whereIn('trade_date', $dates)
                ->whereIn('stock_code', $stockCodes)
                ->whereIn('exchange', $exchanges)
                ->get(['exchange', 'stock_code', 'trade_date', 'turnover_rate_percent', 'trading_shares', 'issued_shares'])
                ->map(fn (TwStockDailyTurnoverRate $row): array => $row->getAttributes())
                ->all(),
        );

        $turnoverByStock = TwStockDailyTurnoverRate::hydrate($turnoverRecords)
            ->groupBy(fn (TwStockDailyTurnoverRate $row): string => $this->stockKey((string) $row->exchange, (string) $row->stock_code));

        return $rows->each(function (TwStockAnnualFinancialComparison $row) use ($dates, $turnoverByStock): void {
            $stockTurnoverRows = $turnoverByStock
                ->get($this->stockKey((string) $row->exchange, (string) $row->stock_code), collect())
                ->keyBy(fn (TwStockDailyTurnoverRate $turnover): string => Carbon::parse($turnover->trade_date)->toDateString());

            $history = [];
            $total = 0.0;
            $tradingDays = 0;
            foreach ($dates as $date) {
                $turnover = $stockTurnoverRows->get($date);
                $rate = $this->nullableFloat($turnover?->turnover_rate_percent);
                if ($rate !== null) {
                    $total += $rate;
                    $tradingDays++;
                }

                $history[] = [
                    'date' => $date,
                    'turnover_rate_percent' => $rate,
                    'trading_shares' => $turnover?->trading_shares,
                    'issued_shares' => $turnover?->issued_shares,
                ];
            }

            $row->setAttribute('weekly_turnover_rates', $history);
            $row->setAttribute('weekly_turnover_total_percent', $tradingDays > 0 ? round($total, 4) : null);
            $row->setAttribute('weekly_turnover_trading_days', $tradingDays);
        });
    }

    /**
     * @param list<string> $dates
     * @param list<string> $stockCodes
     * @param list<string> $exchanges
     */
    private function dailyTurnoverCacheVersion(array $dates, array $stockCodes, array $exchanges): string
    {
        $row = TwStockDailyTurnoverRate::query()
            ->whereIn('trade_date', $dates)
            ->whereIn('stock_code', $stockCodes)
            ->whereIn('exchange', $exchanges)
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

    private function annualExpectedPrice(TwStockAnnualFinancialComparison $stock, ?float $valuationGroupPe): ?float
    {
        $currentEps = $this->nullableFloat($stock->current_eps);
        if ($currentEps === null || $currentEps <= 0 || $valuationGroupPe === null || $valuationGroupPe <= 0) {
            return null;
        }

        return $currentEps * 4 * $valuationGroupPe;
    }

    private function annualExpectedPriceChangePercent(TwStockAnnualFinancialComparison $stock, ?float $expectedPrice): ?float
    {
        $latestClosePrice = $this->nullableFloat($stock->latest_close_price);
        if ($expectedPrice === null || $latestClosePrice === null || $latestClosePrice <= 0) {
            return null;
        }

        return (($expectedPrice - $latestClosePrice) / $latestClosePrice) * 100;
    }

    /**
     * @param Collection<int, TwStockAnnualFinancialComparison> $rows
     * @param list<string> $filters
     * @param array<string, true> $recentTwoMonthHighKeys
     * @param array<string, float> $thresholds
     * @return Collection<int, TwStockAnnualFinancialComparison>
     */
    private function filterAnnualComparisonRows(Collection $rows, array $filters, array $recentTwoMonthHighKeys, array $thresholds): Collection
    {
        return $rows
            ->filter(function (TwStockAnnualFinancialComparison $row) use ($filters, $recentTwoMonthHighKeys, $thresholds): bool {
                foreach ($filters as $filter) {
                    $passes = match ($filter) {
                        'revenue_growth' => $this->passesThreshold($row->revenue_yoy_sum, $thresholds['revenue_growth']),
                        'eps_growth' => $this->passesThreshold($row->eps_yoy_sum, $thresholds['eps_growth']),
                        'current_q1_eps_yoy' => $this->passesThreshold($row->current_q1_eps_yoy_percent, $thresholds['current_q1_eps_yoy']),
                        'end_year_revenue_yoy' => $this->passesThreshold($row->end_year_revenue_yoy_percent, $thresholds['end_year_revenue_yoy']),
                        'current_q1_revenue_yoy' => $this->passesThreshold($row->current_q1_revenue_yoy_percent, $thresholds['current_q1_revenue_yoy']),
                        'recent_two_month_high' => isset($recentTwoMonthHighKeys[$this->stockKey((string) $row->exchange, (string) $row->stock_code)]),
                        'net_margin' => $this->annualNetMarginPass($row, $thresholds['net_margin']),
                        'weekly_turnover' => $this->passesThreshold($row->getAttribute('weekly_turnover_total_percent'), $thresholds['weekly_turnover']),
                        default => true,
                    };

                    if (!$passes) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    private function annualNetMarginPass(TwStockAnnualFinancialComparison $row, float $threshold): bool
    {
        return $this->passesThreshold($row->recent_net_margin_average, $threshold)
            || $this->passesThreshold($row->last_two_year_net_margin_average, $threshold);
    }

    /**
     * @param Collection<int, TwStockAnnualFinancialComparison> $rows
     * @param array<string, true> $recentTwoMonthHighKeys
     * @param array<string, float> $thresholds
     * @return array{total: int, revenuePass: int, epsPass: int, currentQ1EpsYoyPass: int, endYearRevenueYoyPass: int, currentQ1RevenueYoyPass: int, recentTwoMonthHighPass: int, netMarginPass: int, weeklyTurnoverPass: int}
     */
    private function annualComparisonSummary(Collection $rows, array $recentTwoMonthHighKeys, array $thresholds): array
    {
        $summary = [
            'total' => $rows->count(),
            'revenuePass' => 0,
            'epsPass' => 0,
            'currentQ1EpsYoyPass' => 0,
            'endYearRevenueYoyPass' => 0,
            'currentQ1RevenueYoyPass' => 0,
            'recentTwoMonthHighPass' => 0,
            'netMarginPass' => 0,
            'weeklyTurnoverPass' => 0,
        ];

        foreach ($rows as $row) {
            if ($this->passesThreshold($row->revenue_yoy_sum, $thresholds['revenue_growth'])) {
                $summary['revenuePass']++;
            }

            if ($this->passesThreshold($row->eps_yoy_sum, $thresholds['eps_growth'])) {
                $summary['epsPass']++;
            }

            if ($this->passesThreshold($row->current_q1_eps_yoy_percent, $thresholds['current_q1_eps_yoy'])) {
                $summary['currentQ1EpsYoyPass']++;
            }

            if ($this->passesThreshold($row->end_year_revenue_yoy_percent, $thresholds['end_year_revenue_yoy'])) {
                $summary['endYearRevenueYoyPass']++;
            }

            if ($this->passesThreshold($row->current_q1_revenue_yoy_percent, $thresholds['current_q1_revenue_yoy'])) {
                $summary['currentQ1RevenueYoyPass']++;
            }

            if (isset($recentTwoMonthHighKeys[$this->stockKey((string) $row->exchange, (string) $row->stock_code)])) {
                $summary['recentTwoMonthHighPass']++;
            }

            if ($this->annualNetMarginPass($row, $thresholds['net_margin'])) {
                $summary['netMarginPass']++;
            }

            if ($this->passesThreshold($row->getAttribute('weekly_turnover_total_percent'), $thresholds['weekly_turnover'])) {
                $summary['weeklyTurnoverPass']++;
            }
        }

        return $summary;
    }

    private function passesThreshold(mixed $value, float $threshold): bool
    {
        $numeric = $this->nullableFloat($value);

        return $numeric !== null && $numeric > $threshold;
    }

    /**
     * @param Collection<int, TwStockAnnualFinancialComparison> $rows
     * @return Collection<int, TwStockAnnualFinancialComparison>
     */
    private function sortAnnualComparisonRows(Collection $rows, string $sort): Collection
    {
        $sortColumn = $sort === 'eps' ? 'eps_yoy_sum' : 'revenue_yoy_sum';

        return $rows
            ->sort(function (TwStockAnnualFinancialComparison $left, TwStockAnnualFinancialComparison $right) use ($sortColumn): int {
                $leftValue = $this->nullableFloat($left->{$sortColumn});
                $rightValue = $this->nullableFloat($right->{$sortColumn});

                if ($leftValue === null && $rightValue === null) {
                    return strcmp((string) $left->stock_code, (string) $right->stock_code);
                }

                if ($leftValue === null) {
                    return 1;
                }

                if ($rightValue === null) {
                    return -1;
                }

                return ($rightValue <=> $leftValue)
                    ?: strcmp((string) $left->stock_code, (string) $right->stock_code);
            })
            ->values();
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
     */
    private function topQ1RowBy(Collection $rows, string $field): ?TwStockQ1FinancialReport
    {
        $topRow = null;
        $topValue = null;

        foreach ($rows as $row) {
            if ($row->{$field} === null) {
                continue;
            }

            $value = (float) $row->{$field};
            if ($topValue === null || $value > $topValue) {
                $topRow = $row;
                $topValue = $value;
            }
        }

        return $topRow;
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

        $stockKeys = $rows
            ->map(fn (object $row): string => $this->stockKey((string) $row->exchange, (string) $row->stock_code))
            ->unique()
            ->sort()
            ->values()
            ->all();

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

        return Cache::remember(
            'tw-stock:recent-two-month-high:v1:' . sha1(serialize([
                $stockKeys,
                $latestDate,
                $startDate,
                self::RECENT_HIGH_LOOKBACK_TRADING_DAYS,
                self::RECENT_HIGH_SIGNAL_TRADING_DAYS,
                $this->dailyPriceCacheVersion($stockCodes, $exchanges, $startDate),
            ])),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => $this->computeRecentTwoMonthHighKeys($stockCodes, $exchanges, $startDate),
        );
    }

    /**
     * @param list<string> $stockCodes
     * @param list<string> $exchanges
     * @return array<string, true>
     */
    private function computeRecentTwoMonthHighKeys(array $stockCodes, array $exchanges, string $startDate): array
    {
        $rankedRows = TwStockDailyPrice::query()
            ->select(['exchange', 'stock_code', 'high_price'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY exchange, stock_code ORDER BY trade_date DESC) as trading_day_rank')
            ->whereIn('stock_code', $stockCodes)
            ->whereIn('exchange', $exchanges)
            ->where('trade_date', '>=', $startDate)
            ->whereNotNull('high_price');

        return DB::query()
            ->fromSub($rankedRows, 'ranked_daily_prices')
            ->select(['exchange', 'stock_code'])
            ->where('trading_day_rank', '<=', self::RECENT_HIGH_LOOKBACK_TRADING_DAYS)
            ->groupBy('exchange', 'stock_code')
            ->havingRaw(
                'MAX(CASE WHEN trading_day_rank <= ? THEN high_price END) >= MAX(high_price)',
                [self::RECENT_HIGH_SIGNAL_TRADING_DAYS],
            )
            ->get()
            ->mapWithKeys(fn ($row): array => [$this->stockKey((string) $row->exchange, (string) $row->stock_code) => true])
            ->all();
    }

    /**
     * @param list<string> $stockCodes
     * @param list<string> $exchanges
     */
    private function dailyPriceCacheVersion(array $stockCodes, array $exchanges, string $startDate): string
    {
        $row = TwStockDailyPrice::query()
            ->whereIn('stock_code', $stockCodes)
            ->whereIn('exchange', $exchanges)
            ->where('trade_date', '>=', $startDate)
            ->selectRaw('COUNT(*) as row_count, MAX(trade_date) as max_trade_date, MAX(updated_at) as max_updated_at, MAX(id) as max_id')
            ->toBase()
            ->first();

        return implode('|', [
            (int) ($row->row_count ?? 0),
            (string) ($row->max_trade_date ?? ''),
            (string) ($row->max_updated_at ?? ''),
            (string) ($row->max_id ?? ''),
        ]);
    }

    private function stockKey(string $exchange, string $stockCode): string
    {
        return $exchange . '|' . $stockCode;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
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
        return Cache::remember(
            'tw-stock:q1:valuation-groups:v1:' . $year . ':' . $this->q1CacheVersion($year),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => TwStockQ1FinancialReport::query()
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
                ->all(),
        );
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
