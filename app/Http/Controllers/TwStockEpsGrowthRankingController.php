<?php

namespace App\Http\Controllers;

use App\Models\TwStockCompanyProfile;
use App\Models\TwStockDailyPrice;
use App\Models\TwStockEpsGrowthRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TwStockEpsGrowthRankingController extends Controller
{
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

        $neutralEstimateCodes = config('tw_stock.eps_growth_ranking.neutral_estimate_stock_codes', []);
        $rows = $run?->rankings()
            ->where(function ($query) use ($neutralEstimateCodes): void {
                $query->where('rank', '<=', 50)
                    ->orWhereIn('stock_code', $neutralEstimateCodes);
            })
            ->orderBy('rank')
            ->get() ?? collect();
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

            $row->setAttribute('stock_group', $group !== '' ? $group : null);
            $row->setAttribute('valuation_group_pe', $groupPe);
            $row->setAttribute('expected_price_2027', $expectedPrice2027);
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
