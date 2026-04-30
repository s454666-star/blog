<?php

namespace App\Http\Controllers;

use App\Models\TwStockUpcomingDividend;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

class TwStockUpcomingDividendController extends Controller
{
    public function index(): View
    {
        $today = CarbonImmutable::today();
        $endDate = $today->addDays(30);

        $rows = TwStockUpcomingDividend::query()
            ->whereBetween('ex_dividend_date', [$today->toDateString(), $endDate->toDateString()])
            ->orderBy('ex_dividend_date')
            ->orderByDesc('dividend_yield_percent')
            ->get();

        return view('tw-stock.upcoming-dividends', [
            'rows' => $rows,
            'today' => $today,
            'endDate' => $endDate,
            'totalRows' => $rows->count(),
            'nextExDate' => $rows->first()?->ex_dividend_date,
            'maxYieldRow' => $rows->sortByDesc('dividend_yield_percent')->first(),
            'lastFetchedAt' => $rows->pluck('fetched_at')->filter()->max(),
        ]);
    }
}
