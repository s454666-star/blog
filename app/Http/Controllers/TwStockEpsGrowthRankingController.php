<?php

namespace App\Http\Controllers;

use App\Models\TwStockCompanyProfile;
use App\Models\TwStockDailyPrice;
use App\Models\TwStockEpsGrowthRun;
use App\Models\TwStockQ1FinancialReport;
use App\Services\TwStockEpsGrowthScoringService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TwStockEpsGrowthRankingController extends Controller
{
    public function __construct(
        private readonly TwStockEpsGrowthScoringService $scoring,
    ) {}

    public function index(Request $request): View
    {
        $availableRuns = TwStockEpsGrowthRun::query()
            ->whereNotNull('completed_at')
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (TwStockEpsGrowthRun $run): string => $run->snapshot_date->toDateString())
            ->values();

        $requestedRunId = max(0, (int) $request->query('run', 0));
        $run = $requestedRunId > 0
            ? $availableRuns->firstWhere('id', $requestedRunId)
            : $availableRuns->first();

        $epsBasis = $request->query('eps_basis') === 'actual' ? 'actual' : 'forecast';
        $neutralEstimateCodes = config('tw_stock.eps_growth_ranking.neutral_estimate_stock_codes', []);
        $rows = $run?->rankings()
            ->orderBy('rank')
            ->get() ?? collect();
        $this->attachReportedHalfYearEps($rows, $run?->forecast_year_1);
        if ($epsBasis === 'actual') {
            $rows = $this->applyAnnualizedHalfYearEps($rows);
        }
        $rows = $rows
            ->filter(fn ($row): bool => $row->rank <= 50 || in_array($row->stock_code, $neutralEstimateCodes, true))
            ->values();
        $this->attachStockGroups($rows);
        $this->attachMovingAveragePositions($rows, $run?->price_date?->toDateString());
        $previousRun = $run === null ? null : TwStockEpsGrowthRun::query()
            ->whereDate('snapshot_date', '<', $run->snapshot_date->toDateString())
            ->whereNotNull('completed_at')
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->first();

        return view('tw-stock.eps-growth-rankings', [
            'run' => $run,
            'previousRun' => $previousRun,
            'availableRuns' => $availableRuns,
            'rows' => $rows,
            'epsBasis' => $epsBasis,
            'summary' => [
                'up' => $rows->where('rank_change', '>', 0)->count(),
                'down' => $rows->where('rank_change', '<', 0)->count(),
                'flat' => $rows->filter(fn ($row): bool => $row->rank_change === 0)->count(),
                'neutral_estimates' => $rows->where('is_neutral_estimate', true)->count(),
                'positive_all_three' => $rows->filter(fn ($row): bool =>
                    $row->growth_2025_2026 > 0
                    && $row->growth_2026_2027 > 0
                    && $row->growth_2027_2028 > 0
                )->count(),
            ],
        ]);
    }

    private function applyAnnualizedHalfYearEps(Collection $rows): Collection
    {
        $eligibleRows = $rows->filter(fn ($row): bool =>
            $row->reported_half_year_eps !== null
            && $row->reported_half_year_eps > 0
            && $row->eps_2025 > 0
            && $row->eps_2027 > 0
        );

        $scoredRows = $this->scoring->scoreAndRank($eligibleRows
            ->map(function ($row): array {
                $annualizedEps2026 = (float) $row->reported_half_year_eps * 2;
                $growth2025To2026 = (($annualizedEps2026 / $row->eps_2025) - 1) * 100;
                $growth2026To2027 = (($row->eps_2027 / $annualizedEps2026) - 1) * 100;

                return [
                    'stock_code' => $row->stock_code,
                    'growth_2025_2026' => round($growth2025To2026, 4),
                    'growth_2026_2027' => round($growth2026To2027, 4),
                    'growth_2027_2028' => (float) $row->growth_2027_2028,
                    'annualized_eps_2026' => $annualizedEps2026,
                    'growth_sum' => round($growth2025To2026 + $growth2026To2027 + $row->growth_2027_2028, 4),
                ];
            })
            ->values()
            ->all());
        $rowsByCode = $eligibleRows->keyBy('stock_code');

        return collect($scoredRows)->map(function (array $scoredRow) use ($rowsByCode) {
            $row = $rowsByCode->get($scoredRow['stock_code']);
            $forecastRank = (int) $row->rank;
            $row->setAttribute('eps_2026', $scoredRow['annualized_eps_2026']);
            $row->setAttribute('growth_2025_2026', $scoredRow['growth_2025_2026']);
            $row->setAttribute('growth_2026_2027', $scoredRow['growth_2026_2027']);
            $row->setAttribute('growth_sum', $scoredRow['growth_sum']);
            $row->setAttribute('weighted_score', $scoredRow['weighted_score']);
            $row->setAttribute('rank', $scoredRow['rank']);
            $row->setAttribute('previous_rank', $forecastRank);
            $row->setAttribute('rank_change', $forecastRank - $scoredRow['rank']);

            return $row;
        });
    }

    private function attachReportedHalfYearEps(Collection $rows, ?int $fiscalYear): void
    {
        if ($rows->isEmpty() || $fiscalYear === null) {
            return;
        }

        $quarterlyEpsByStock = TwStockQ1FinancialReport::query()
            ->where('fiscal_year', $fiscalYear)
            ->whereIn('quarter', [1, 2])
            ->whereIn('stock_code', $rows->pluck('stock_code')->filter()->unique()->values())
            ->whereNotNull('q1_eps')
            ->get(['stock_code', 'quarter', 'q1_eps'])
            ->groupBy('stock_code');

        foreach ($rows as $row) {
            $quarterlyEps = $quarterlyEpsByStock->get($row->stock_code, collect())->keyBy('quarter');
            $q1 = $quarterlyEps->get(1)?->q1_eps;
            $q2 = $quarterlyEps->get(2)?->q1_eps;
            $row->setAttribute(
                'reported_half_year_eps',
                $q1 !== null && $q2 !== null ? (float) $q1 + (float) $q2 : null,
            );
        }
    }

    private function attachStockGroups(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $profiles = TwStockCompanyProfile::query()
            ->whereIn('stock_code', $rows->pluck('stock_code')->filter()->unique()->values())
            ->orderByDesc('source_date')
            ->orderByDesc('id')
            ->get(['stock_code', 'valuation_group', 'valuation_group_pe', 'industry'])
            ->unique('stock_code')
            ->keyBy('stock_code');

        foreach ($rows as $row) {
            $profile = $profiles->get($row->stock_code);
            $group = trim((string) ($profile?->valuation_group ?: $profile?->industry));
            $groupPe = $profile?->valuation_group_pe;
            $expectedPrice2027 = $groupPe !== null && $groupPe > 0 && $row->eps_2027 > 0
                ? (float) $row->eps_2027 * $groupPe
                : null;
            $expectedPrice2027ReturnPercentage = $expectedPrice2027 !== null
                && $row->close_price !== null
                && $row->close_price > 0
                    ? (($expectedPrice2027 / $row->close_price) - 1) * 100
                    : null;

            $row->setAttribute('stock_group', $group !== '' ? $group : null);
            $row->setAttribute('valuation_group_pe', $groupPe);
            $row->setAttribute('expected_price_2027', $expectedPrice2027);
            $row->setAttribute('expected_price_2027_return_percentage', $expectedPrice2027ReturnPercentage);
        }
    }

    private function attachMovingAveragePositions(Collection $rows, ?string $priceDate): void
    {
        if ($rows->isEmpty() || $priceDate === null) {
            return;
        }

        $stockCodes = $rows->pluck('stock_code')->filter()->unique()->values();
        $rankedPrices = TwStockDailyPrice::query()
            ->select(['stock_code', 'trade_date', 'close_price'])
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY stock_code ORDER BY trade_date DESC) AS price_row_number')
            ->whereIn('stock_code', $stockCodes)
            ->whereDate('trade_date', '<=', $priceDate);
        $priceHistories = DB::query()
            ->fromSub($rankedPrices, 'ranked_prices')
            ->where('price_row_number', '<=', 60)
            ->orderBy('stock_code')
            ->orderByDesc('trade_date')
            ->get()
            ->groupBy('stock_code');

        foreach ($rows as $row) {
            $prices = $priceHistories->get($row->stock_code, collect())
                ->pluck('close_price')
                ->filter(fn (mixed $price): bool => is_numeric($price))
                ->map(fn (mixed $price): float => (float) $price)
                ->values();

            $row->setAttribute('monthly_moving_average', $prices->count() >= 20 ? $prices->take(20)->avg() : null);
            $row->setAttribute('quarterly_moving_average', $prices->count() >= 60 ? $prices->take(60)->avg() : null);
        }
    }
}
