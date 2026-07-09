<?php

namespace App\Http\Controllers;

use App\Models\TwFuturesHourlyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TwFuturesHourlyPriceController extends Controller
{
    private const SYMBOL = 'TXF1!';

    private const DAILY_MA_SOURCE_INTERVAL = '5';

    private const PRIMARY_INTERVAL = '15';

    private const FIFTEEN_MINUTE_MA_WINDOW = 380;

    private const HOURLY_MA_WINDOW = 95;

    private const FOUR_HOUR_MA5_BOUNDARY_TIMES = ['03:00', '05:00', '12:45', '13:45', '19:00', '23:00'];

    private const FOUR_HOUR_MA5_NOTIFY_TIMES = ['08:45', '12:45', '15:00', '19:00', '23:00'];

    private const FOUR_HOUR_MA5_WINDOW = 5;

    public function index(): View
    {
        $dataRevision = $this->currentDataRevision();

        return view('tw-stock.taiex-futures-kline', [
            ...$this->chartPayload($dataRevision),
            'dataUrl' => route('tw-stock.taiex-futures.kline.data'),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $dataRevision = $this->currentDataRevision();
        if ($request->query('revision') === $dataRevision) {
            return $this->noStoreJson([
                'unchanged' => true,
                'dataRevision' => $dataRevision,
            ]);
        }

        return $this->noStoreJson($this->chartPayload($dataRevision));
    }

    /**
     * @return array<string, mixed>
     */
    public function lineAlertPayload(): array
    {
        $payload = $this->chartPayload();

        return [
            'chartRows' => $payload['chartRows'],
            'fourHourMa5Rows' => $payload['fourHourMa5Rows'],
            'stats' => $payload['stats'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chartPayload(?string $dataRevision = null): array
    {
        $rows = $this->priceRows(self::SYMBOL, self::PRIMARY_INTERVAL);
        $fiveMinuteRows = $this->priceRows(self::SYMBOL, self::DAILY_MA_SOURCE_INTERVAL);
        $hourlyRows = $this->priceRows(self::SYMBOL, '60');
        $dailyMa5ByTimestamp = $this->fiveMinuteDailyMa5ByTimestamp($fiveMinuteRows);
        $indicatorRows = $this->indicatorRows($rows, self::FIFTEEN_MINUTE_MA_WINDOW, $dailyMa5ByTimestamp);
        $hourlyIndicatorRows = $this->indicatorRows($hourlyRows, self::HOURLY_MA_WINDOW, $dailyMa5ByTimestamp);
        $fourHourMa5Rows = $this->fourHourMa5Rows($hourlyIndicatorRows['chartRows'], $indicatorRows['chartRows']);
        $latest = $rows->last();

        return [
            'dataRevision' => $dataRevision ?? $this->currentDataRevision(),
            'latest' => $latest,
            'chartRows' => $indicatorRows['chartRows'],
            'dailyChartRows' => $indicatorRows['dailyChartRows'],
            'gapMarkers' => $indicatorRows['gapMarkers'],
            'dailyGapMarkers' => $indicatorRows['dailyGapMarkers'],
            'hourlyChartRows' => $hourlyIndicatorRows['chartRows'],
            'hourlyGapMarkers' => $hourlyIndicatorRows['gapMarkers'],
            'fourHourMa5Rows' => $fourHourMa5Rows,
            'sessionGapRows' => $indicatorRows['sessionGapRows'],
            'stats' => [
                'firstDateTime' => $this->displayDateTime($rows->first()),
                'lastDateTime' => $this->displayDateTime($latest),
                'rowCount' => $rows->count(),
                'latestClose' => $latest === null ? null : round((float) $latest->close_price, 2),
                'latestGap' => $indicatorRows['latestGap'],
                'latestDailyMa5' => $indicatorRows['latestDailyMa5'],
                'latestMovingAverage' => $indicatorRows['latestMovingAverage'],
                'latestBias' => $indicatorRows['latestBias'],
                'latestBiasRate' => $indicatorRows['latestBiasRate'],
                'maxGap' => $indicatorRows['maxGap'],
                'minGap' => $indicatorRows['minGap'],
                'sessionGapCount' => count($indicatorRows['sessionGapRows']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function noStoreJson(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function currentDataRevision(): string
    {
        $rows = TwFuturesHourlyPrice::query()
            ->where('symbol', self::SYMBOL)
            ->whereIn('interval', [self::DAILY_MA_SOURCE_INTERVAL, self::PRIMARY_INTERVAL, '60'])
            ->select('interval')
            ->selectRaw('COUNT(*) as row_count, MAX(started_at_unix) as latest_started_at_unix, MAX(updated_at) as last_updated_at')
            ->groupBy('interval')
            ->orderBy('interval')
            ->toBase()
            ->get();

        $fingerprint = $rows
            ->map(fn (object $row): string => implode('|', [
                (string) $row->interval,
                (string) $row->row_count,
                (string) $row->latest_started_at_unix,
                (string) $row->last_updated_at,
            ]))
            ->implode(';');

        return sha1($fingerprint);
    }

    /**
     * @return Collection<int, object>
     */
    private function priceRows(string $symbol, string $interval): Collection
    {
        return TwFuturesHourlyPrice::query()
            ->where('symbol', $symbol)
            ->where('interval', $interval)
            ->select([
                'interval',
                'started_at',
                'started_at_unix',
                'trade_date',
                'session_type',
                'open_price',
                'high_price',
                'low_price',
                'close_price',
                'volume_contracts',
            ])
            ->orderBy('started_at')
            ->toBase()
            ->get();
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, float>
     */
    private function fiveMinuteDailyMa5ByTimestamp(Collection $rows): array
    {
        $previousDailyCloses = $this->previousComputedDailyCloses($rows);
        $dailyMa5ByTimestamp = [];

        foreach ($rows as $row) {
            $tradeDate = $this->tradeDateString($row);
            $previous = $tradeDate !== null ? ($previousDailyCloses[$tradeDate] ?? []) : [];
            if (count($previous) !== 4) {
                continue;
            }

            $dailyMa5ByTimestamp[$this->displayTimestamp($row)] = round(
                (array_sum($previous) + (float) $row->close_price) / 5,
                4,
            );
        }

        return $dailyMa5ByTimestamp;
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<string, list<float>>
     */
    private function previousComputedDailyCloses(Collection $rows): array
    {
        $computedDailyCloseByDate = [];
        foreach ($rows as $row) {
            $tradeDate = $this->tradeDateString($row);
            if ($tradeDate === null) {
                continue;
            }

            $computedDailyCloseByDate[$tradeDate] = (float) $row->close_price;
        }

        $tradeDates = array_keys($computedDailyCloseByDate);
        sort($tradeDates);

        $previousDailyCloses = [];
        foreach ($tradeDates as $index => $tradeDate) {
            $previous = [];
            for ($cursor = max(0, $index - 4); $cursor < $index; $cursor++) {
                $previous[] = (float) $computedDailyCloseByDate[$tradeDates[$cursor]];
            }
            $previousDailyCloses[$tradeDate] = $previous;
        }

        return $previousDailyCloses;
    }

    /**
     * @param Collection<int, object> $rows
     * @param array<int, float> $dailyMa5ByTimestamp
     * @return array{
     *     chartRows: list<array<string, mixed>>,
     *     dailyChartRows: list<array<string, mixed>>,
     *     gapMarkers: list<array<string, mixed>>,
     *     dailyGapMarkers: list<array<string, mixed>>,
     *     sessionGapRows: list<array<string, mixed>>,
     *     latestGap: float|null,
     *     latestDailyMa5: float|null,
     *     latestMovingAverage: float|null,
     *     latestBias: float|null,
     *     latestBiasRate: float|null,
     *     maxGap: float|null,
     *     minGap: float|null
     * }
     */
    private function indicatorRows(Collection $rows, int $movingAverageWindowSize, array $dailyMa5ByTimestamp = []): array
    {
        $previousDailyCloses = $this->previousComputedDailyCloses($rows);

        $sessionCloseTimes = [];
        foreach ($rows as $row) {
            $tradeDate = $this->tradeDateString($row);
            $sessionType = (string) $row->session_type;
            if ($tradeDate === null || ! in_array($sessionType, ['day', 'night'], true)) {
                continue;
            }

            $sessionCloseTimes[$tradeDate][$sessionType] = (int) $row->started_at_unix;
        }

        $movingAverageWindow = [];
        $movingAverageSum = 0.0;
        $chartRows = [];
        $gapMarkers = [];
        $sessionGapRows = [];
        $gaps = [];
        $latestGap = null;
        $latestDailyMa5 = null;
        $latestMovingAverage = null;
        $latestBias = null;
        $latestBiasRate = null;

        foreach ($rows as $row) {
            $close = (float) $row->close_price;
            $movingAverageWindow[] = $close;
            $movingAverageSum += $close;
            if (count($movingAverageWindow) > $movingAverageWindowSize) {
                $movingAverageSum -= array_shift($movingAverageWindow);
            }

            $movingAverage = count($movingAverageWindow) === $movingAverageWindowSize
                ? $movingAverageSum / $movingAverageWindowSize
                : null;
            $tradeDate = $this->tradeDateString($row);
            $startedAt = CarbonImmutable::parse($row->started_at, 'Asia/Taipei');
            $startedAtUnix = (int) $row->started_at_unix;
            $time = $this->displayTimestamp($row);
            $previous = $tradeDate !== null ? ($previousDailyCloses[$tradeDate] ?? []) : [];
            $dailyMa5 = $dailyMa5ByTimestamp[$time]
                ?? (count($previous) === 4 ? (array_sum($previous) + $close) / 5 : null);
            $gap = $dailyMa5 !== null && $movingAverage !== null ? $dailyMa5 - $movingAverage : null;
            $bias = $movingAverage !== null ? $close - $movingAverage : null;
            $biasRate = $bias !== null && $close !== 0.0 ? $bias / $close : null;
            $localTime = $this->displayDateTime($row) ?? $startedAt->format('Y-m-d H:i');
            $sessionType = (string) $row->session_type;
            $isSessionOpen = in_array($startedAt->format('H:i'), ['08:45', '15:00'], true);
            $isSessionClose = $tradeDate !== null
                && in_array($sessionType, ['day', 'night'], true)
                && ($sessionCloseTimes[$tradeDate][$sessionType] ?? null) === $startedAtUnix
                && $this->isSessionCloseConfirmed($startedAt, $sessionType);

            if ($bias !== null) {
                $latestMovingAverage = $movingAverage;
                $latestBias = $bias;
                $latestBiasRate = $biasRate;
            }

            if ($gap !== null) {
                $latestGap = $gap;
                $latestDailyMa5 = $dailyMa5;
                $latestMovingAverage = $movingAverage;
                $gaps[] = $gap;

                $sessionEvents = [];
                if ($isSessionOpen) {
                    $sessionEvents[] = [
                        'label' => $sessionType === 'day' ? '日盤' : '夜盤',
                        'eventLabel' => '開盤差值',
                        'shape' => $gap >= 0 ? 'arrowDown' : 'arrowUp',
                    ];
                }
                if ($isSessionClose) {
                    $sessionEvents[] = [
                        'label' => $sessionType === 'day' ? '日盤' : '夜盤',
                        'eventLabel' => '收盤差值',
                        'shape' => 'circle',
                    ];
                }

                $gapColor = $this->gapColor($gap);
                foreach ($sessionEvents as $event) {
                    $sessionGapRows[] = [
                        'time' => $time,
                        'localTime' => $localTime,
                        'label' => $event['label'],
                        'eventLabel' => $event['eventLabel'],
                        'gap' => round($gap, 2),
                        'gapText' => ($gap >= 0 ? '+' : '') . number_format($gap, 0) . '點',
                        'dailyMa5' => round($dailyMa5, 2),
                        'movingAverage' => round($movingAverage, 2),
                    ];
                    $gapMarkers[] = [
                        'time' => $time,
                        'position' => $gap >= 0 ? 'aboveBar' : 'belowBar',
                        'color' => $gapColor,
                        'shape' => $event['shape'],
                        'text' => $this->signedGapText($gap),
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
                'movingAverage' => $movingAverage === null ? null : round($movingAverage, 4),
                'dailyMa5' => $dailyMa5 === null ? null : round($dailyMa5, 4),
                'gap' => $gap === null ? null : round($gap, 4),
                'bias' => $bias === null ? null : round($bias, 4),
                'biasRate' => $biasRate === null ? null : round($biasRate, 8),
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
            'latestMovingAverage' => $latestMovingAverage === null ? null : round($latestMovingAverage, 2),
            'latestBias' => $latestBias === null ? null : round($latestBias, 2),
            'latestBiasRate' => $latestBiasRate === null ? null : round($latestBiasRate, 6),
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
                    'movingAverage' => null,
                    'dailyMa5' => null,
                    'gap' => null,
                    'bias' => null,
                    'biasRate' => null,
                ];
            }

            $groups[$tradeDate]['high'] = max((float) $groups[$tradeDate]['high'], (float) $row['high']);
            $groups[$tradeDate]['low'] = min((float) $groups[$tradeDate]['low'], (float) $row['low']);
            $groups[$tradeDate]['close'] = (float) $row['close'];
            $groups[$tradeDate]['volume'] += (int) $row['volume'];

            if ($row['movingAverage'] !== null) {
                $groups[$tradeDate]['movingAverage'] = (float) $row['movingAverage'];
            }
            if ($row['dailyMa5'] !== null) {
                $groups[$tradeDate]['dailyMa5'] = (float) $row['dailyMa5'];
            }
            if ($row['gap'] !== null) {
                $groups[$tradeDate]['gap'] = (float) $row['gap'];
            }
            if ($row['bias'] !== null) {
                $groups[$tradeDate]['bias'] = (float) $row['bias'];
            }
            if ($row['biasRate'] !== null) {
                $groups[$tradeDate]['biasRate'] = (float) $row['biasRate'];
            }
        }

        ksort($groups);

        $dailyRows = [];
        foreach ($groups as $tradeDate => $group) {
            $close = (float) $group['close'];
            $dailyMa5 = $group['dailyMa5'] === null ? null : (float) $group['dailyMa5'];
            $movingAverage = $group['movingAverage'] === null ? null : (float) $group['movingAverage'];
            $gap = $group['gap'] === null ? null : (float) $group['gap'];
            $bias = $group['bias'] === null ? null : (float) $group['bias'];
            $biasRate = $group['biasRate'] === null ? null : (float) $group['biasRate'];
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
                'movingAverage' => $movingAverage === null ? null : round($movingAverage, 4),
                'dailyMa5' => $dailyMa5 === null ? null : round($dailyMa5, 4),
                'gap' => $gap === null ? null : round($gap, 4),
                'bias' => $bias === null ? null : round($bias, 4),
                'biasRate' => $biasRate === null ? null : round($biasRate, 8),
                'isSessionOpen' => false,
            ];
        }

        return $dailyRows;
    }

    /**
     * @param list<array<string, mixed>> $hourlyRows
     * @param list<array<string, mixed>> $primaryRows
     * @return list<array<string, mixed>>
     */
    private function fourHourMa5Rows(array $hourlyRows, array $primaryRows): array
    {
        $boundaryCloses = [];
        $snapshots = [];

        foreach ($hourlyRows as $row) {
            $localTime = (string) ($row['localTime'] ?? '');
            $clock = substr($localTime, -5);

            if (! in_array($clock, self::FOUR_HOUR_MA5_BOUNDARY_TIMES, true)) {
                continue;
            }

            $close = (float) $row['close'];
            $boundaryCloses[] = $close;

            if (count($boundaryCloses) < self::FOUR_HOUR_MA5_WINDOW) {
                continue;
            }

            $window = array_slice($boundaryCloses, -self::FOUR_HOUR_MA5_WINDOW);
            $ma5 = array_sum($window) / self::FOUR_HOUR_MA5_WINDOW;

            $snapshots[] = [
                'time' => (int) $row['time'],
                'fourHourMa5' => round($ma5, 4),
            ];
        }

        $rows = [];
        $snapshotIndex = -1;
        $snapshotCount = count($snapshots);

        foreach ($primaryRows as $row) {
            $alertTime = $this->primaryAlertTime($row);
            $alertLocalTime = $this->primaryAlertLocalTime($row);
            $clock = substr($alertLocalTime, -5);

            if ($alertTime <= 0 || ! in_array($clock, self::FOUR_HOUR_MA5_NOTIFY_TIMES, true)) {
                continue;
            }

            while ($snapshotIndex + 1 < $snapshotCount && $snapshots[$snapshotIndex + 1]['time'] <= $alertTime) {
                $snapshotIndex++;
            }

            if ($snapshotIndex < 0) {
                continue;
            }

            $close = (float) $row['close'];
            $ma5 = (float) $snapshots[$snapshotIndex]['fourHourMa5'];
            $diff = $close - $ma5;

            $rows[] = [
                'time' => $alertTime,
                'localTime' => $alertLocalTime,
                'close' => round($close, 4),
                'fourHourMa5' => round($ma5, 4),
                'fourHourMa5Diff' => round($diff, 4),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function primaryAlertTime(array $row): int
    {
        $time = (int) ($row['time'] ?? 0);
        if ($time <= 0) {
            return 0;
        }

        return $time - ($this->intervalMinutes(self::PRIMARY_INTERVAL) * 60);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function primaryAlertLocalTime(array $row): string
    {
        $alertTime = $this->primaryAlertTime($row);
        if ($alertTime <= 0) {
            return (string) ($row['localTime'] ?? '');
        }

        return CarbonImmutable::createFromTimestamp($alertTime, 'Asia/Taipei')->format('Y-m-d H:i');
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
                'color' => $this->gapColor($gap),
                'shape' => 'circle',
                'text' => $this->signedGapText($gap),
            ];
        }

        return $markers;
    }

    private function gapColor(float $gap): string
    {
        return $gap >= 0 ? '#f59e0b' : '#38bdf8';
    }

    private function signedGapText(float $gap): string
    {
        return ($gap >= 0 ? '+' : '') . number_format($gap, 0);
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

    private function displayDateTime(?object $row): ?string
    {
        if ($row === null || $row->started_at === null) {
            return null;
        }

        return $this->displayedAt($row)->format('Y-m-d H:i');
    }

    private function displayTimestamp(object $row): int
    {
        return (int) $row->started_at_unix + ($this->intervalMinutes((string) $row->interval) * 60);
    }

    private function displayedAt(object $row): CarbonImmutable
    {
        return CarbonImmutable::parse($row->started_at, 'Asia/Taipei')
            ->addMinutes($this->intervalMinutes((string) $row->interval));
    }

    private function tradeDateString(object $row): ?string
    {
        $tradeDate = $row->trade_date ?? null;
        if ($tradeDate === null || $tradeDate === '') {
            return null;
        }

        if ($tradeDate instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($tradeDate)->toDateString();
        }

        return CarbonImmutable::parse((string) $tradeDate, 'Asia/Taipei')->toDateString();
    }

    private function intervalMinutes(string $interval): int
    {
        return max(0, (int) $interval);
    }

}
