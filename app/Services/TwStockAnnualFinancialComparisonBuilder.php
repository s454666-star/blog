<?php

namespace App\Services;

use App\Models\TwStockQ1FinancialReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TwStockAnnualFinancialComparisonBuilder
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function build(
        int $contextYear = 2026,
        int $startYear = 2020,
        int $endYear = 2025,
        string $search = '',
    ): Collection {
        $latestRows = $this->latestRows($contextYear, $startYear, $search);
        if ($latestRows->isEmpty()) {
            return collect();
        }

        $stockCodes = $latestRows
            ->map(fn (TwStockQ1FinancialReport $row): string => (string) $row->stock_code)
            ->unique()
            ->values();
        $annualMetrics = $this->annualMetricRows($stockCodes, $contextYear, $startYear);
        $monthlyRevenueRows = $this->monthlyRevenueRows($stockCodes, $contextYear, $startYear);
        $netMarginRows = $this->netMarginRows($stockCodes, $contextYear, $startYear);

        return $latestRows
            ->map(fn (TwStockQ1FinancialReport $latest, string $key): array => $this->buildStockRow(
                $latest,
                $monthlyRevenueRows[$key] ?? [],
                $annualMetrics[$key] ?? [],
                $netMarginRows[$key] ?? collect(),
                $contextYear,
                $startYear,
                $endYear,
            ))
            ->filter(fn (array $stock): bool => $stock['comparisons'] !== [])
            ->values();
    }

    /**
     * @return Collection<string, TwStockQ1FinancialReport>
     */
    private function latestRows(int $contextYear, int $startYear, string $search): Collection
    {
        return TwStockQ1FinancialReport::query()
            ->select([
                'id',
                'exchange',
                'stock_code',
                'stock_name',
                'fiscal_year',
                'quarter',
                'q1_revenue_yoy_percent',
                'q1_eps_yoy_percent',
                'latest_close_price',
                'volume_lots',
            ])
            ->whereBetween('fiscal_year', [$startYear, $contextYear])
            ->whereBetween('quarter', [1, 4])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
                $query->where(function (Builder $query) use ($like): void {
                    $query->where('stock_code', 'like', $like)
                        ->orWhere('stock_name', 'like', $like);
                });
            })
            ->orderBy('exchange')
            ->orderBy('stock_code')
            ->orderByDesc('fiscal_year')
            ->orderByDesc('quarter')
            ->orderByDesc('id')
            ->get()
            ->groupBy(fn (TwStockQ1FinancialReport $row): string => $this->rowKey((string) $row->exchange, (string) $row->stock_code))
            ->map(fn (Collection $rows): TwStockQ1FinancialReport => $rows->first());
    }

    /**
     * @param Collection<int, string> $stockCodes
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function annualMetricRows(Collection $stockCodes, int $contextYear, int $startYear): array
    {
        return TwStockQ1FinancialReport::query()
            ->select(['exchange', 'stock_code', 'fiscal_year'])
            ->selectRaw('SUM(q1_eps) as eps')
            ->selectRaw('AVG(q1_gross_margin_percent) as gross_margin')
            ->selectRaw('AVG(q1_operating_margin_percent) as operating_margin')
            ->selectRaw('AVG(q1_net_margin_percent) as net_margin')
            ->selectRaw('COUNT(*) as quarters')
            ->whereIn('stock_code', $stockCodes->all())
            ->whereBetween('fiscal_year', [$startYear, $contextYear])
            ->whereBetween('quarter', [1, 4])
            ->groupBy('exchange', 'stock_code', 'fiscal_year')
            ->get()
            ->groupBy(fn ($row): string => $this->rowKey((string) $row->exchange, (string) $row->stock_code))
            ->map(fn (Collection $rows): array => $rows
                ->mapWithKeys(fn ($row): array => [
                    (int) $row->fiscal_year => [
                        'eps' => $this->nullableFloat($row->eps),
                        'gross_margin' => $this->nullableFloat($row->gross_margin),
                        'operating_margin' => $this->nullableFloat($row->operating_margin),
                        'net_margin' => $this->nullableFloat($row->net_margin),
                        'quarters' => (int) $row->quarters,
                    ],
                ])
                ->all())
            ->all();
    }

    /**
     * @param Collection<int, string> $stockCodes
     * @return array<string, list<array<string, mixed>>>
     */
    private function monthlyRevenueRows(Collection $stockCodes, int $contextYear, int $startYear): array
    {
        return TwStockQ1FinancialReport::query()
            ->select(['exchange', 'stock_code', 'recent_monthly_revenues'])
            ->whereIn('stock_code', $stockCodes->all())
            ->whereBetween('fiscal_year', [$startYear, $contextYear])
            ->whereNotNull('recent_monthly_revenues')
            ->get()
            ->groupBy(fn (TwStockQ1FinancialReport $row): string => $this->rowKey((string) $row->exchange, (string) $row->stock_code))
            ->map(fn (Collection $rows): array => $rows
                ->map(fn (TwStockQ1FinancialReport $row): array => $row->recent_monthly_revenues ?? [])
                ->sortByDesc(fn (array $rows): int => count($rows))
                ->first() ?? [])
            ->all();
    }

    /**
     * @param Collection<int, string> $stockCodes
     * @return array<string, Collection<int, TwStockQ1FinancialReport>>
     */
    private function netMarginRows(Collection $stockCodes, int $contextYear, int $startYear): array
    {
        return TwStockQ1FinancialReport::query()
            ->select(['exchange', 'stock_code', 'fiscal_year', 'quarter', 'q1_net_margin_percent'])
            ->whereIn('stock_code', $stockCodes->all())
            ->whereBetween('fiscal_year', [$startYear, $contextYear])
            ->whereBetween('quarter', [1, 4])
            ->whereNotNull('q1_net_margin_percent')
            ->orderBy('exchange')
            ->orderBy('stock_code')
            ->orderByDesc('fiscal_year')
            ->orderByDesc('quarter')
            ->get()
            ->groupBy(fn (TwStockQ1FinancialReport $row): string => $this->rowKey((string) $row->exchange, (string) $row->stock_code))
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $monthlyRows
     * @param array<int, array<string, mixed>> $annualMetrics
     * @param Collection<int, TwStockQ1FinancialReport> $netMarginRows
     * @return array<string, mixed>
     */
    private function buildStockRow(
        TwStockQ1FinancialReport $latest,
        array $monthlyRows,
        array $annualMetrics,
        Collection $netMarginRows,
        int $contextYear,
        int $startYear,
        int $endYear,
    ): array {
        $annualRevenues = $this->annualRevenueFromMonthlyRows($monthlyRows, $startYear, $contextYear);
        $comparisons = [];

        foreach (range($startYear + 1, $endYear) as $year) {
            $previousYear = $year - 1;
            $previousRevenue = $annualRevenues[$previousYear] ?? null;
            $currentRevenue = $annualRevenues[$year] ?? null;
            $previousEps = $annualMetrics[$previousYear]['eps'] ?? null;
            $currentEps = $annualMetrics[$year]['eps'] ?? null;

            $comparisons[] = [
                'previous_year' => $previousYear,
                'year' => $year,
                'previous_revenue_billion' => $previousRevenue,
                'revenue_billion' => $currentRevenue,
                'revenue_yoy_percent' => $this->growthValue($currentRevenue, $previousRevenue),
                'previous_eps' => $previousEps,
                'eps' => $currentEps,
                'eps_yoy_percent' => $this->growthValue($currentEps, $previousEps),
                'gross_margin_percent' => $annualMetrics[$year]['gross_margin'] ?? null,
                'operating_margin_percent' => $annualMetrics[$year]['operating_margin'] ?? null,
                'net_margin_percent' => $annualMetrics[$year]['net_margin'] ?? null,
                'quarters' => $annualMetrics[$year]['quarters'] ?? 0,
            ];
        }

        $revenueYoySum = $this->sumExistingValues($comparisons, 'revenue_yoy_percent');
        $epsYoySum = $this->sumExistingValues($comparisons, 'eps_yoy_percent');
        $epsYoyAllPositive = $this->allGrowthValuesPositive($comparisons, 'eps_yoy_percent');
        $endYearRevenueYoy = $this->growthValueForYear($comparisons, $endYear, 'revenue_yoy_percent');
        $recentNetMarginAverage = $this->recentNetMarginAverage($netMarginRows, 8);
        $lastTwoYearNetMarginAverage = $this->lastTwoYearNetMarginAverage($netMarginRows, $endYear);
        $currentRevenueMonths = $this->currentRevenueMonths($monthlyRows, $contextYear);

        return [
            'context_year' => $contextYear,
            'comparison_start_year' => $startYear,
            'comparison_end_year' => $endYear,
            'exchange' => (string) $latest->exchange,
            'stock_code' => (string) $latest->stock_code,
            'stock_name' => (string) $latest->stock_name,
            'comparisons' => $comparisons,
            'revenue_yoy_sum' => $revenueYoySum,
            'eps_yoy_sum' => $epsYoySum,
            'revenue_filter_pass' => $revenueYoySum !== null && $revenueYoySum > 40,
            'eps_filter_pass' => $epsYoySum !== null && $epsYoySum > 25,
            'eps_yoy_all_positive' => $epsYoyAllPositive,
            'net_margin_filter_pass' => ($recentNetMarginAverage !== null && $recentNetMarginAverage > 15)
                || ($lastTwoYearNetMarginAverage !== null && $lastTwoYearNetMarginAverage > 15),
            'recent_net_margin_average' => $recentNetMarginAverage,
            'last_two_year_net_margin_average' => $lastTwoYearNetMarginAverage,
            'current_revenue_billion' => $currentRevenueMonths['revenue_billion'],
            'current_revenue_months' => $currentRevenueMonths['months'],
            'current_eps' => $annualMetrics[$contextYear]['eps'] ?? null,
            'current_q1_eps_yoy_percent' => $this->nullableFloat($latest->q1_eps_yoy_percent),
            'current_q1_revenue_yoy_percent' => $this->nullableFloat($latest->q1_revenue_yoy_percent),
            'end_year_revenue_yoy_percent' => $endYearRevenueYoy,
            'latest_close_price' => $latest->latest_close_price,
            'volume_lots' => $latest->volume_lots,
            'generated_at' => now(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $monthlyRows
     * @return array<int, float>
     */
    private function annualRevenueFromMonthlyRows(array $monthlyRows, int $startYear, int $contextYear): array
    {
        $annual = [];
        foreach ($monthlyRows as $monthlyRow) {
            if (!is_array($monthlyRow)) {
                continue;
            }

            $yearMonth = (string) ($monthlyRow['year_month'] ?? '');
            if (strlen($yearMonth) !== 6) {
                continue;
            }

            $year = (int) substr($yearMonth, 0, 4);
            if ($year < $startYear || $year > $contextYear) {
                continue;
            }

            $revenue = $this->nullableFloat($monthlyRow['revenue_billion'] ?? null);
            if ($revenue === null) {
                continue;
            }

            $annual[$year] = ($annual[$year] ?? 0.0) + $revenue;
        }

        return $annual;
    }

    private function growthValue(?float $value, ?float $baseline): ?float
    {
        if ($value === null || $baseline === null || abs($baseline) < 0.000001) {
            return null;
        }

        return (($value - $baseline) / abs($baseline)) * 100;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function sumExistingValues(array $rows, string $field): ?float
    {
        $values = array_values(array_filter(array_map(
            fn (array $row): ?float => $this->nullableFloat($row[$field] ?? null),
            $rows
        ), fn (?float $value): bool => $value !== null));

        return $values === [] ? null : array_sum($values);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function allGrowthValuesPositive(array $rows, string $field): bool
    {
        if ($rows === []) {
            return false;
        }

        foreach ($rows as $row) {
            $value = $this->nullableFloat($row[$field] ?? null);
            if ($value === null || $value <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function growthValueForYear(array $rows, int $year, string $field): ?float
    {
        foreach ($rows as $row) {
            if ((int) ($row['year'] ?? 0) === $year) {
                return $this->nullableFloat($row[$field] ?? null);
            }
        }

        return null;
    }

    /**
     * @param Collection<int, TwStockQ1FinancialReport> $netMarginRows
     */
    private function recentNetMarginAverage(Collection $netMarginRows, int $quarters): ?float
    {
        $values = $netMarginRows
            ->sortByDesc(fn (TwStockQ1FinancialReport $row): string => sprintf('%04d%02d', $row->fiscal_year, $row->quarter))
            ->pluck('q1_net_margin_percent')
            ->filter(fn ($value): bool => $value !== null)
            ->take($quarters)
            ->map(fn ($value): float => (float) $value)
            ->values();

        return $values->count() < $quarters ? null : $values->avg();
    }

    /**
     * @param Collection<int, TwStockQ1FinancialReport> $netMarginRows
     */
    private function lastTwoYearNetMarginAverage(Collection $netMarginRows, int $endYear): ?float
    {
        $values = $netMarginRows
            ->whereIn('fiscal_year', [$endYear - 1, $endYear])
            ->pluck('q1_net_margin_percent')
            ->filter(fn ($value): bool => $value !== null)
            ->map(fn ($value): float => (float) $value)
            ->values();

        return $values->count() < 8 ? null : $values->avg();
    }

    /**
     * @param list<array<string, mixed>> $monthlyRows
     * @return array{revenue_billion: ?float, months: int}
     */
    private function currentRevenueMonths(array $monthlyRows, int $contextYear): array
    {
        $rows = collect($monthlyRows)
            ->filter(fn ($row): bool => is_array($row) && str_starts_with((string) ($row['year_month'] ?? ''), (string) $contextYear))
            ->values();

        return [
            'revenue_billion' => $this->sumExistingValues($rows->all(), 'revenue_billion'),
            'months' => $rows->count(),
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }

    private function rowKey(string $exchange, string $stockCode): string
    {
        return $exchange . '|' . $stockCode;
    }
}
