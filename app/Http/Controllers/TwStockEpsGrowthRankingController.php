<?php

namespace App\Http\Controllers;

use App\Models\TwStockEpsGrowthRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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

        $rows = $run?->rankings()
            ->where('rank', '<=', 50)
            ->orderBy('rank')
            ->get() ?? collect();
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
                'positive_all_three' => $rows->filter(fn ($row): bool =>
                    $row->growth_2025_2026 > 0
                    && $row->growth_2026_2027 > 0
                    && $row->growth_2027_2028 > 0
                )->count(),
            ],
        ]);
    }
}
