<?php

namespace App\Http\Controllers;

use App\Models\TwFuturesHourlyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Cache;

class TwFuturesHourlyPriceController extends Controller
{
    private const CACHE_TTL_SECONDS = 300;

    private const SYMBOL = 'TXF1!';

    public function index(): View
    {
        $rows = $this->hourlyRows(self::SYMBOL);
        $indicatorRows = $this->indicatorRows($rows);
        $latest = $rows->last();

        return view('tw-stock.taiex-futures-kline', [
            'latest' => $latest,
            'chartRows' => $indicatorRows['chartRows'],
            'gapMarkers' => $indicatorRows['gapMarkers'],
            'sessionGapRows' => $indicatorRows['sessionGapRows'],
            'stats' => [
                'firstDateTime' => $rows->first()?->started_at?->timezone('Asia/Taipei')->format('Y-m-d H:i'),
                'lastDateTime' => $latest?->started_at?->timezone('Asia/Taipei')->format('Y-m-d H:i'),
                'rowCount' => $rows->count(),
                'latestGap' => $indicatorRows['latestGap'],
                'latestDailyMa5' => $indicatorRows['latestDailyMa5'],
                'latestMa95' => $indicatorRows['latestMa95'],
                'maxGap' => $indicatorRows['maxGap'],
                'minGap' => $indicatorRows['minGap'],
                'sessionGapCount' => count($indicatorRows['sessionGapRows']),
            ],
        ]);
    }

    /**
     * @return EloquentCollection<int, TwFuturesHourlyPrice>
     */
    private function hourlyRows(string $symbol): EloquentCollection
    {
        $records = Cache::remember(
            'tw-futures:hourly-prices:rows:v1:' . sha1(serialize([
                $symbol,
                $this->cacheVersion($symbol),
            ])),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => TwFuturesHourlyPrice::query()
                ->where('symbol', $symbol)
                ->where('interval', '60')
                ->orderBy('started_at')
                ->get()
                ->map(fn (TwFuturesHourlyPrice $row): array => $row->getAttributes())
                ->all(),
        );

        return TwFuturesHourlyPrice::hydrate($records);
    }

    /**
     * @param EloquentCollection<int, TwFuturesHourlyPrice> $rows
     * @return array{
     *     chartRows: list<array<string, mixed>>,
     *     gapMarkers: list<array<string, mixed>>,
     *     sessionGapRows: list<array<string, mixed>>,
     *     latestGap: float|null,
     *     latestDailyMa5: float|null,
     *     latestMa95: float|null,
     *     maxGap: float|null,
     *     minGap: float|null
     * }
     */
    private function indicatorRows(EloquentCollection $rows): array
    {
        $dailyCloseByDate = [];
        foreach ($rows as $row) {
            $tradeDate = $row->trade_date?->toDateString();
            if ($tradeDate === null) {
                continue;
            }

            $dailyCloseByDate[$tradeDate] = (float) $row->close_price;
        }

        $tradeDates = array_keys($dailyCloseByDate);
        sort($tradeDates);
        $previousDailyCloses = [];
        foreach ($tradeDates as $index => $tradeDate) {
            $previous = [];
            for ($cursor = max(0, $index - 4); $cursor < $index; $cursor++) {
                $previous[] = (float) $dailyCloseByDate[$tradeDates[$cursor]];
            }
            $previousDailyCloses[$tradeDate] = $previous;
        }

        $ma95Window = [];
        $ma95Sum = 0.0;
        $chartRows = [];
        $gapMarkers = [];
        $sessionGapRows = [];
        $gaps = [];
        $latestGap = null;
        $latestDailyMa5 = null;
        $latestMa95 = null;

        foreach ($rows as $row) {
            $close = (float) $row->close_price;
            $ma95Window[] = $close;
            $ma95Sum += $close;
            if (count($ma95Window) > 95) {
                $ma95Sum -= array_shift($ma95Window);
            }

            $ma95 = count($ma95Window) === 95 ? $ma95Sum / 95 : null;
            $tradeDate = $row->trade_date?->toDateString();
            $previous = $tradeDate !== null ? ($previousDailyCloses[$tradeDate] ?? []) : [];
            $dailyMa5 = count($previous) === 4
                ? (array_sum($previous) + $close) / 5
                : null;
            $gap = $dailyMa5 !== null && $ma95 !== null ? $dailyMa5 - $ma95 : null;
            $startedAt = CarbonImmutable::parse($row->started_at, 'Asia/Taipei');
            $time = (int) $row->started_at_unix;
            $localTime = $startedAt->format('Y-m-d H:i');
            $isSessionOpen = in_array($startedAt->format('H:i'), ['08:45', '15:00'], true);

            if ($gap !== null) {
                $latestGap = $gap;
                $latestDailyMa5 = $dailyMa5;
                $latestMa95 = $ma95;
                $gaps[] = $gap;

                if ($isSessionOpen) {
                    $label = $row->session_type === 'day' ? '日開' : '夜開';
                    $sessionGapRows[] = [
                        'time' => $time,
                        'localTime' => $localTime,
                        'label' => $label,
                        'gap' => round($gap, 2),
                        'gapText' => ($gap >= 0 ? '+' : '') . number_format($gap, 0) . '點',
                        'dailyMa5' => round($dailyMa5, 2),
                        'ma95' => round($ma95, 2),
                    ];
                    $gapMarkers[] = [
                        'time' => $time,
                        'position' => $gap >= 0 ? 'aboveBar' : 'belowBar',
                        'color' => $gap >= 0 ? '#f59e0b' : '#38bdf8',
                        'shape' => $gap >= 0 ? 'arrowDown' : 'arrowUp',
                        'text' => $label . ' ' . ($gap >= 0 ? '+' : '') . number_format($gap, 0) . '點',
                    ];
                }
            }

            $chartRows[] = [
                'time' => $time,
                'localTime' => $localTime,
                'tradeDate' => $tradeDate,
                'sessionType' => $row->session_type,
                'open' => (float) $row->open_price,
                'high' => (float) $row->high_price,
                'low' => (float) $row->low_price,
                'close' => $close,
                'volume' => (int) $row->volume_contracts,
                'ma95' => $ma95 === null ? null : round($ma95, 4),
                'dailyMa5' => $dailyMa5 === null ? null : round($dailyMa5, 4),
                'gap' => $gap === null ? null : round($gap, 4),
                'isSessionOpen' => $isSessionOpen,
            ];
        }

        return [
            'chartRows' => $chartRows,
            'gapMarkers' => $gapMarkers,
            'sessionGapRows' => array_slice(array_reverse($sessionGapRows), 0, 18),
            'latestGap' => $latestGap === null ? null : round($latestGap, 2),
            'latestDailyMa5' => $latestDailyMa5 === null ? null : round($latestDailyMa5, 2),
            'latestMa95' => $latestMa95 === null ? null : round($latestMa95, 2),
            'maxGap' => $gaps === [] ? null : round(max($gaps), 2),
            'minGap' => $gaps === [] ? null : round(min($gaps), 2),
        ];
    }

    private function cacheVersion(string $symbol): string
    {
        $row = TwFuturesHourlyPrice::query()
            ->where('symbol', $symbol)
            ->where('interval', '60')
            ->selectRaw('COUNT(*) as row_count, MAX(started_at) as max_started_at, MAX(updated_at) as max_updated_at, MAX(fetched_at) as max_fetched_at, MAX(id) as max_id')
            ->toBase()
            ->first();

        return implode('|', [
            (int) ($row->row_count ?? 0),
            (string) ($row->max_started_at ?? ''),
            (string) ($row->max_updated_at ?? ''),
            (string) ($row->max_fetched_at ?? ''),
            (string) ($row->max_id ?? ''),
        ]);
    }
}
