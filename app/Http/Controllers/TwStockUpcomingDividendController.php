<?php

namespace App\Http\Controllers;

use App\Models\TwStockUpcomingDividend;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class TwStockUpcomingDividendController extends Controller
{
    private const STOCK_CACHE_TTL_SECONDS = 43200;

    public function index(): View
    {
        $today = CarbonImmutable::today();
        $endDate = $today->addDays(30);

        $rows = $this->upcomingRows($today, $endDate);

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

    /**
     * @return Collection<int, TwStockUpcomingDividend>
     */
    private function upcomingRows(CarbonImmutable $today, CarbonImmutable $endDate): Collection
    {
        $start = $today->toDateString();
        $end = $endDate->toDateString();

        $records = Cache::remember(
            'tw-stock:upcoming-dividends:rows:v2:' . sha1(serialize([
                $start,
                $end,
                $this->dividendCacheVersion($start, $end),
            ])),
            now()->addSeconds(self::STOCK_CACHE_TTL_SECONDS),
            fn (): array => TwStockUpcomingDividend::query()
                ->whereBetween('ex_dividend_date', [$start, $end])
                ->orderBy('ex_dividend_date')
                ->orderByDesc('dividend_yield_percent')
                ->get()
                ->map(fn (TwStockUpcomingDividend $row): array => $row->getAttributes())
                ->all(),
        );

        return TwStockUpcomingDividend::hydrate($records);
    }

    private function dividendCacheVersion(string $start, string $end): string
    {
        $row = TwStockUpcomingDividend::query()
            ->whereBetween('ex_dividend_date', [$start, $end])
            ->selectRaw('COUNT(*) as row_count, MAX(ex_dividend_date) as max_ex_dividend_date, MAX(updated_at) as max_updated_at, MAX(fetched_at) as max_fetched_at, MAX(id) as max_id')
            ->toBase()
            ->first();

        return implode('|', [
            (int) ($row->row_count ?? 0),
            (string) ($row->max_ex_dividend_date ?? ''),
            (string) ($row->max_updated_at ?? ''),
            (string) ($row->max_fetched_at ?? ''),
            (string) ($row->max_id ?? ''),
        ]);
    }
}
