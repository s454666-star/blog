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
            'dailyChartRows' => $indicatorRows['dailyChartRows'],
            'gapMarkers' => $indicatorRows['gapMarkers'],
            'dailyGapMarkers' => $indicatorRows['dailyGapMarkers'],
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
     *     dailyChartRows: list<array<string, mixed>>,
     *     gapMarkers: list<array<string, mixed>>,
     *     dailyGapMarkers: list<array<string, mixed>>,
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

        $sessionCloseTimes = [];
        foreach ($rows as $row) {
            $tradeDate = $row->trade_date?->toDateString();
            $sessionType = (string) $row->session_type;
            if ($tradeDate === null || ! in_array($sessionType, ['day', 'night'], true)) {
                continue;
            }

            $sessionCloseTimes[$tradeDate][$sessionType] = (int) $row->started_at_unix;
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
            $sessionType = (string) $row->session_type;
            $isSessionOpen = in_array($startedAt->format('H:i'), ['08:45', '15:00'], true);
            $isSessionClose = $tradeDate !== null
                && in_array($sessionType, ['day', 'night'], true)
                && ($sessionCloseTimes[$tradeDate][$sessionType] ?? null) === $time
                && $this->isSessionCloseConfirmed($startedAt, $sessionType);

            if ($gap !== null) {
                $latestGap = $gap;
                $latestDailyMa5 = $dailyMa5;
                $latestMa95 = $ma95;
                $gaps[] = $gap;

                $sessionEvents = [];
                if ($isSessionOpen) {
                    $sessionEvents[] = [
                        'label' => $sessionType === 'day' ? '日開' : '夜開',
                        'shape' => $gap >= 0 ? 'arrowDown' : 'arrowUp',
                    ];
                }
                if ($isSessionClose) {
                    $sessionEvents[] = [
                        'label' => $sessionType === 'day' ? '日收' : '夜收',
                        'shape' => 'circle',
                    ];
                }

                foreach ($sessionEvents as $event) {
                    $sessionGapRows[] = [
                        'time' => $time,
                        'localTime' => $localTime,
                        'label' => $event['label'],
                        'gap' => round($gap, 2),
                        'gapText' => ($gap >= 0 ? '+' : '') . number_format($gap, 0) . '點',
                        'dailyMa5' => round($dailyMa5, 2),
                        'ma95' => round($ma95, 2),
                    ];
                    $gapMarkers[] = [
                        'time' => $time,
                        'position' => $gap >= 0 ? 'aboveBar' : 'belowBar',
                        'color' => $gap >= 0 ? '#f59e0b' : '#38bdf8',
                        'shape' => $event['shape'],
                        'text' => $event['label'] . ' ' . ($gap >= 0 ? '+' : '') . number_format($gap, 0) . '點',
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

        $dailyChartRows = $this->dailyChartRows($chartRows);

        return [
            'chartRows' => $chartRows,
            'dailyChartRows' => $dailyChartRows,
            'gapMarkers' => $gapMarkers,
            'dailyGapMarkers' => $this->dailyGapMarkers($dailyChartRows),
            'sessionGapRows' => array_slice(array_reverse($sessionGapRows), 0, 18),
            'latestGap' => $latestGap === null ? null : round($latestGap, 2),
            'latestDailyMa5' => $latestDailyMa5 === null ? null : round($latestDailyMa5, 2),
            'latestMa95' => $latestMa95 === null ? null : round($latestMa95, 2),
            'maxGap' => $gaps === [] ? null : round(max($gaps), 2),
            'minGap' => $gaps === [] ? null : round(min($gaps), 2),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function dailyChartRows(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $tradeDate = $row['tradeDate'] ?? null;
            if ($tradeDate === null) {
                continue;
            }

            if (! isset($groups[$tradeDate])) {
                $groups[$tradeDate] = [
                    'open' => (float) $row['open'],
                    'high' => (float) $row['high'],
                    'low' => (float) $row['low'],
                    'close' => (float) $row['close'],
                    'volume' => 0,
                    'ma95' => null,
                ];
            }

            $groups[$tradeDate]['high'] = max((float) $groups[$tradeDate]['high'], (float) $row['high']);
            $groups[$tradeDate]['low'] = min((float) $groups[$tradeDate]['low'], (float) $row['low']);
            $groups[$tradeDate]['close'] = (float) $row['close'];
            $groups[$tradeDate]['volume'] += (int) $row['volume'];

            if ($row['ma95'] !== null) {
                $groups[$tradeDate]['ma95'] = (float) $row['ma95'];
            }
        }

        ksort($groups);

        $dailyRows = [];
        $closeWindow = [];
        $closeSum = 0.0;
        foreach ($groups as $tradeDate => $group) {
            $close = (float) $group['close'];
            $closeWindow[] = $close;
            $closeSum += $close;
            if (count($closeWindow) > 5) {
                $closeSum -= array_shift($closeWindow);
            }

            $dailyMa5 = count($closeWindow) === 5 ? $closeSum / 5 : null;
            $ma95 = $group['ma95'] === null ? null : (float) $group['ma95'];
            $gap = $dailyMa5 !== null && $ma95 !== null ? $dailyMa5 - $ma95 : null;
            $time = CarbonImmutable::parse($tradeDate . ' 12:00:00', 'Asia/Taipei')->timestamp;

            $dailyRows[] = [
                'time' => $time,
                'localTime' => $tradeDate,
                'tradeDate' => $tradeDate,
                'sessionType' => 'daily',
                'open' => round((float) $group['open'], 4),
                'high' => round((float) $group['high'], 4),
                'low' => round((float) $group['low'], 4),
                'close' => $close,
                'volume' => (int) $group['volume'],
                'ma95' => $ma95 === null ? null : round($ma95, 4),
                'dailyMa5' => $dailyMa5 === null ? null : round($dailyMa5, 4),
                'gap' => $gap === null ? null : round($gap, 4),
                'isSessionOpen' => false,
            ];
        }

        return $dailyRows;
    }

    /**
     * @param list<array<string, mixed>> $dailyRows
     * @return list<array<string, mixed>>
     */
    private function dailyGapMarkers(array $dailyRows): array
    {
        $markers = [];
        foreach ($dailyRows as $row) {
            $gap = $row['gap'] ?? null;
            if ($gap === null) {
                continue;
            }

            $gap = (float) $gap;
            $markers[] = [
                'time' => (int) $row['time'],
                'position' => $gap >= 0 ? 'aboveBar' : 'belowBar',
                'color' => $gap >= 0 ? '#f59e0b' : '#38bdf8',
                'shape' => 'circle',
                'text' => '日線 ' . ($gap >= 0 ? '+' : '') . number_format($gap, 0) . '點',
            ];
        }

        return $markers;
    }

    private function isSessionCloseConfirmed(CarbonImmutable $startedAt, string $sessionType): bool
    {
        if ($sessionType === 'day') {
            $confirmedAt = $startedAt->setTime(13, 45);
        } else {
            $confirmedDate = (int) $startedAt->format('H') >= 15
                ? $startedAt->addDay()
                : $startedAt;
            $confirmedAt = $confirmedDate->setTime(5, 0);
        }

        return CarbonImmutable::now('Asia/Taipei')->greaterThanOrEqualTo($confirmedAt);
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
