<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillTwFuturesContinuousPricesCommand;
use App\Console\Commands\NotifyTaiexFuturesLineAlertsCommand;
use App\Http\Controllers\TwFuturesHourlyPriceController;
use App\Services\TwFuturesDailyPriceFetcher;
use App\Services\TwFuturesHourlyPriceFetcher;
use App\Services\TwFuturesYahooMinutePriceFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use ReflectionMethod;
use Tests\TestCase;

class TwFuturesHourlyPricesTest extends TestCase
{
    private string $originalDatabaseDefault;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this feature test.');
        }

        $this->originalDatabaseDefault = (string) config('database.default');

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Carbon::setTestNow('2026-05-25 22:00:00');
        CarbonImmutable::setTestNow('2026-05-25 22:00:00');

        Schema::dropAllTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tw_futures_daily_prices');
        Schema::dropIfExists('tw_futures_hourly_prices');

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_taiex_futures_kline_page_renders_gap_chart(): void
    {
        $this->seedHourlyRows();

        $response = $this->get(route('tw-stock.taiex-futures.kline'));

        $response
            ->assertOk()
            ->assertSee('台指期 15K 差值 K 線')
            ->assertSee('TAIFEX · TXF1! · 15K / 60K')
            ->assertSee('<span data-legend-ma-label>15K</span>', false)
            ->assertDontSee('15K MA380')
            ->assertSee('60K MA95')
            ->assertSee('日 MA5')
            ->assertSee('差值')
            ->assertSee('乖離')
            ->assertDontSee('乖離-差值')
            ->assertSee('乖離率')
            ->assertSee('const dailyChartRows =', false)
            ->assertSee('const hourlyChartRows =', false)
            ->assertSee('const dataUrl =', false)
            ->assertSee('let futuresDataRevision =', false)
            ->assertSee('data-summary-field="latestClose"', false)
            ->assertSee('data-session-gap-list', false)
            ->assertSee('data-timeframe="hourly"', false)
            ->assertSee('data-timeframe="fifteen-minute"', false)
            ->assertSee('data-timeframe="daily"', false)
            ->assertSee('15分K')
            ->assertSee('60分K')
            ->assertSee('日線')
            ->assertSee('data-series-control="candles"', false)
            ->assertSee('type="checkbox" checked disabled data-toggle-series="candles"', false)
            ->assertSee('type="checkbox" checked data-toggle-series="movingAverage"', false)
            ->assertSee('type="checkbox" checked data-toggle-series="dailyMa5"', false)
            ->assertSee('type="checkbox" checked data-toggle-series="gap"', false)
            ->assertSee('type="checkbox" data-toggle-series="bias"', false)
            ->assertDontSee('type="checkbox" data-toggle-series="biasGapDiff"', false)
            ->assertSee('type="checkbox" checked data-toggle-series="biasRate"', false)
            ->assertSee('const seriesVisibility =', false)
            ->assertSee('candles: true', false)
            ->assertSee('movingAverage: true', false)
            ->assertSee('dailyMa5: true', false)
            ->assertSee('gap: true', false)
            ->assertSee('bias: false', false)
            ->assertDontSee('biasGapDiff: false', false)
            ->assertSee('biasRate: true', false)
            ->assertSee('const gapMarkers =', false)
            ->assertSee('const dailyGapMarkers =', false)
            ->assertSee('const hourlyGapMarkers =', false)
            ->assertSee('const movingAverageData =', false)
            ->assertSee('fixRightEdge: false', false)
            ->assertSee('MAX_FUTURE_EMPTY_TRADING_DAYS = 2', false)
            ->assertSee('futureEmptyLogicalBars', false)
            ->assertSee('maxRightLogicalIndex', false)
            ->assertSee('data-toggle-series="gap"', false)
            ->assertSee('data-toggle-series="bias"', false)
            ->assertDontSee('data-toggle-series="biasGapDiff"', false)
            ->assertSee('data-toggle-series="movingAverage"', false)
            ->assertSee("upColor: '#ef5350'", false)
            ->assertSee("downColor: '#26a69a'", false)
            ->assertSee("timeZone: 'Asia/Taipei'", false)
            ->assertSee('tickMarkFormatter: formatTaipeiAxisTime', false)
            ->assertSee('gapSeries.createPriceLine', false)
            ->assertSee('gapSeries.coordinateToPrice', false)
            ->assertSee('formatSignedGapValue', false)
            ->assertSee('title: `差值 ${formatSignedGapValue(roundedGap)}`', false)
            ->assertSee('temporary-line-label', false)
            ->assertSee('axisLabelVisible: false', false)
            ->assertDontSee('candleSeries.createPriceLine', false)
            ->assertSee('toggleTemporaryPriceLine', false)
            ->assertSee('cancelMarkerClick', false)
            ->assertSee('TEMPORARY_LINE_CLICK_MOVE_LIMIT = 6', false)
            ->assertSee('data-marker-count', false)
            ->assertSee('data-legend-bias', false)
            ->assertDontSee('data-legend-bias-gap-diff', false)
            ->assertSee('data-legend-bias-rate', false)
            ->assertSee('const biasRateSeries = chart.addLineSeries', false)
            ->assertSee('marker-label-layer', false)
            ->assertSee('threshold-dot', false)
            ->assertSee('BIAS_RATE_HIGHLIGHT_THRESHOLD = 0.05', false)
            ->assertSee('GAP_HIGHLIGHT_THRESHOLD = 1000', false)
            ->assertSee('chartMarkerData', false)
            ->assertSee('renderMarkerLabels', false)
            ->assertSee('renderThresholdDots', false)
            ->assertSee('visibleCurrentRows', false)
            ->assertSee('Math.abs(biasRate) > BIAS_RATE_HIGHLIGHT_THRESHOLD', false)
            ->assertSee('Math.abs(gap) > GAP_HIGHLIGHT_THRESHOLD', false)
            ->assertSee('appendThresholdDot(', false)
            ->assertSee('series.priceToCoordinate(value)', false)
            ->assertSee('biasRateSeries,', false)
            ->assertSee('gapSeries,', false)
            ->assertSee('data-gap-axis', false)
            ->assertSee('renderGapAxis', false)
            ->assertSee('startGapAxisDrag', false)
            ->assertSee('gapAutoscaleInfoProvider', false)
            ->assertSee('GAP_AXIS_DRAG_SENSITIVITY', false)
            ->assertSee('GAP_AXIS_TICK_MIN_GAP', false)
            ->assertSee('GAP_AXIS_TICK_MAX_GAP = 24', false)
            ->assertSee('GAP_AXIS_DEFAULT_TICK_STEP = 200', false)
            ->assertSee('GAP_AXIS_MIN_VISIBLE_MAX = 2000', false)
            ->assertSee('GAP_AXIS_MIN_NEGATIVE_VISIBLE = 1200', false)
            ->assertSee('GAP_AXIS_ZERO_RATIO = 0.68', false)
            ->assertSee('gapAxisTargetTickCount', false)
            ->assertSee('Math.min(dynamicStep, GAP_AXIS_DEFAULT_TICK_STEP)', false)
            ->assertSee('roundedMaxValue', false)
            ->assertSee('scheduleMarkerLabelRender', false)
            ->assertSee('startMarkerLabelRenderLoop', false)
            ->assertSee('const gapSeries = chart.addBaselineSeries', false)
            ->assertSee('const biasSeries = chart.addLineSeries', false)
            ->assertDontSee('const biasGapDiffSeries = chart.addLineSeries', false)
            ->assertSee("color: '#a78bfa'", false)
            ->assertSee("biasSeries.setData(lineData(currentRows, 'bias'))", false)
            ->assertDontSee("biasGapDiffSeries.setData(lineData(currentRows, 'biasGapDiff'))", false)
            ->assertSee("biasRateSeries.setData(lineData(currentRows, 'biasRate'))", false)
            ->assertSee('candles: [candleSeries, volumeSeries]', false)
            ->assertSee('bias: [biasSeries]', false)
            ->assertDontSee('biasGapDiff: [biasGapDiffSeries]', false)
            ->assertSee('biasRate: [biasRateSeries]', false)
            ->assertSee('visibleGapKeys.map(key => Number(row[key]))', false)
            ->assertSee("priceScaleId: 'gap'", false)
            ->assertSee("priceScaleId: 'biasRate'", false)
            ->assertSee("chart.priceScale('gap').applyOptions", false)
            ->assertSee("chart.priceScale('biasRate').applyOptions", false)
            ->assertSee('scaleMargins: { top: 0.14, bottom: 0.06 }', false)
            ->assertSee("topLineColor: '#f59e0b'", false)
            ->assertSee("bottomLineColor: '#38bdf8'", false)
            ->assertSee("document.querySelectorAll('input[data-toggle-series]')", false)
            ->assertSee('FUTURES_REFRESH_INTERVAL_MS = 60000', false)
            ->assertSee('FUTURES_REFRESH_VISIBLE_GRACE_MS = 10000', false)
            ->assertSee('refreshFuturesData', false)
            ->assertSee('applyFuturesPayload', false)
            ->assertSee('setArrayContents(chartRows, payload.chartRows)', false)
            ->assertSee("url.searchParams.set('revision', futuresDataRevision)", false)
            ->assertSee('payload.unchanged', false)
            ->assertSee("cache: 'no-store'", false)
            ->assertSee("document.addEventListener('visibilitychange'", false)
            ->assertSee("window.addEventListener('focus'", false)
            ->assertSee("window.addEventListener('pageshow'", false)
            ->assertSee('點一下可標記差值，再點一下或右鍵可取消，重整後標記清空。')
            ->assertDontSee('點一下可標記，再點一下或右鍵可取消，重整後標記清空。')
            ->assertDontSee('長按左鍵 1.5 秒可標記或取消既有標記')
            ->assertSee('開盤差值')
            ->assertSee('收盤差值')
            ->assertSee('"shape":"circle"', false)
            ->assertSee('台指期K線');

        $content = (string) $response->getContent();
        $summaryGapPosition = strpos($content, '<div class="label">差值</div>');
        $latestClosePosition = strpos($content, '<div class="label">最新收盤</div>');
        $movingAveragePosition = strpos($content, '<div class="label">15K</div>');
        $biasPosition = strpos($content, '<div class="label">乖離</div>');
        $biasRatePosition = strpos($content, '<div class="label">乖離率</div>');
        $this->assertNotFalse($summaryGapPosition);
        $this->assertNotFalse($latestClosePosition);
        $this->assertNotFalse($movingAveragePosition);
        $this->assertNotFalse($biasPosition);
        $this->assertNotFalse($biasRatePosition);
        $this->assertLessThan($biasPosition, $summaryGapPosition);
        $this->assertGreaterThan($biasPosition, $biasRatePosition);
        $this->assertGreaterThan($biasRatePosition, $latestClosePosition);
        $this->assertGreaterThan($latestClosePosition, $movingAveragePosition);

        preg_match('/const chartRows = (.*);/', $content, $chartMatches);
        $this->assertNotEmpty($chartMatches[1] ?? null);

        $chartRows = json_decode((string) $chartMatches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2026-01-01 09:00', $chartRows[0]['localTime']);
        $this->assertSame(
            CarbonImmutable::parse('2026-01-01 09:00:00', 'Asia/Taipei')->timestamp,
            $chartRows[0]['time'],
        );
        $this->assertArrayNotHasKey('alertLocalTime', $chartRows[0]);
        $this->assertArrayNotHasKey('alertTime', $chartRows[0]);

        $biasRows = array_values(array_filter(
            $chartRows,
            fn (array $row): bool => $row['movingAverage'] !== null && $row['bias'] !== null && $row['biasRate'] !== null,
        ));
        $this->assertNotEmpty($biasRows);
        $biasRow = $biasRows[array_key_last($biasRows)];
        $expectedBias = (float) $biasRow['close'] - (float) $biasRow['movingAverage'];
        $this->assertEqualsWithDelta($expectedBias, (float) $biasRow['bias'], 0.0001);
        $this->assertEqualsWithDelta(
            $expectedBias / (float) $biasRow['close'],
            (float) $biasRow['biasRate'],
            0.000001,
        );
        $this->assertArrayNotHasKey('biasGapDiff', $biasRow);

        preg_match('/const dailyChartRows = (.*);/', $content, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $dailyRows = json_decode((string) $matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty(array_filter(
            $dailyRows,
            fn (array $row): bool => $row['movingAverage'] !== null && $row['gap'] !== null,
        ));
        $this->assertNotEmpty(array_filter(
            $dailyRows,
            fn (array $row): bool => $row['bias'] !== null && $row['biasRate'] !== null,
        ));
        $this->assertArrayNotHasKey('biasGapDiff', $dailyRows[array_key_first($dailyRows)]);

        preg_match('/const hourlyChartRows = (.*);/', $content, $hourlyMatches);
        $this->assertNotEmpty($hourlyMatches[1] ?? null);

        $hourlyRows = json_decode((string) $hourlyMatches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty(array_filter(
            $hourlyRows,
            fn (array $row): bool => $row['movingAverage'] !== null && $row['gap'] !== null,
        ));
        $this->assertNotEmpty(array_filter(
            $hourlyRows,
            fn (array $row): bool => $row['bias'] !== null && $row['biasRate'] !== null,
        ));
        $this->assertArrayNotHasKey('biasGapDiff', $hourlyRows[array_key_first($hourlyRows)]);

        preg_match('/const dailyGapMarkers = (.*);/', $content, $markerMatches);
        $this->assertNotEmpty($markerMatches[1] ?? null);

        $dailyGapMarkers = json_decode((string) $markerMatches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($dailyGapMarkers);
        $this->assertNotEmpty(array_filter(
            $dailyGapMarkers,
            fn (array $marker): bool => (bool) preg_match('/^[+-][0-9,]+$/', (string) $marker['text']),
        ));

        preg_match('/const gapMarkers = (.*);/', $content, $hourlyMarkerMatches);
        $this->assertNotEmpty($hourlyMarkerMatches[1] ?? null);

        $hourlyGapMarkers = json_decode((string) $hourlyMarkerMatches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($hourlyGapMarkers);
        foreach ([...$dailyGapMarkers, ...$hourlyGapMarkers] as $marker) {
            $this->assertMatchesRegularExpression('/^[+-][0-9,]+$/', (string) $marker['text']);
        }
    }

    public function test_taiex_futures_kline_data_endpoint_returns_chart_payload(): void
    {
        $this->seedHourlyRows();

        $response = $this->getJson(route('tw-stock.taiex-futures.kline.data'));

        $response
            ->assertOk()
            ->assertJsonStructure([
                'dataRevision',
                'chartRows' => [
                    [
                        'time',
                        'localTime',
                        'tradeDate',
                        'sessionType',
                        'open',
                        'high',
                        'low',
                        'close',
                        'volume',
                        'movingAverage',
                        'dailyMa5',
                        'gap',
                        'bias',
                        'biasRate',
                    ],
                ],
                'dailyChartRows',
                'gapMarkers',
                'dailyGapMarkers',
                'hourlyChartRows',
                'hourlyGapMarkers',
                'fourHourMa5Rows' => [
                    [
                        'time',
                        'localTime',
                        'close',
                        'fourHourMa5',
                        'fourHourMa5Diff',
                    ],
                ],
                'sessionGapRows',
                'stats' => [
                    'firstDateTime',
                    'lastDateTime',
                    'rowCount',
                    'latestClose',
                    'latestGap',
                    'latestDailyMa5',
                    'latestMovingAverage',
                    'latestBias',
                    'latestBiasRate',
                    'maxGap',
                    'minGap',
                    'sessionGapCount',
                ],
            ]);

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(560, $response->json('stats.rowCount'));
        $this->assertIsNumeric($response->json('stats.latestClose'));
        $this->assertNotEmpty($response->json('dailyChartRows'));
        $this->assertNotEmpty($response->json('hourlyChartRows'));
        $this->assertNotEmpty($response->json('fourHourMa5Rows'));
        $this->assertArrayNotHasKey('biasGapDiff', $response->json('chartRows.0'));
        $this->assertArrayNotHasKey('alertTime', $response->json('chartRows.0'));
        $this->assertArrayNotHasKey('alertLocalTime', $response->json('chartRows.0'));

        $fourHourNotifyTimes = collect($response->json('fourHourMa5Rows'))
            ->map(fn (array $row): string => substr((string) $row['localTime'], -5))
            ->unique()
            ->values()
            ->all();
        foreach (['08:45', '12:45', '13:45', '15:00', '19:00', '23:00'] as $clock) {
            $this->assertContains($clock, $fourHourNotifyTimes);
        }

        $januarySevenRows = collect($response->json('fourHourMa5Rows'))
            ->filter(fn (array $row): bool => str_starts_with((string) $row['localTime'], '2026-01-07 '))
            ->keyBy(fn (array $row): string => substr((string) $row['localTime'], -5));
        foreach ([
            '08:45' => ['startedAt' => '2026-01-07 08:45:00', 'priceColumn' => 'open_price'],
            '12:45' => ['startedAt' => '2026-01-07 12:30:00', 'priceColumn' => 'close_price'],
            '13:45' => ['startedAt' => '2026-01-07 13:30:00', 'priceColumn' => 'close_price'],
            '15:00' => ['startedAt' => '2026-01-07 15:00:00', 'priceColumn' => 'open_price'],
            '19:00' => ['startedAt' => '2026-01-07 18:45:00', 'priceColumn' => 'close_price'],
            '23:00' => ['startedAt' => '2026-01-07 22:45:00', 'priceColumn' => 'close_price'],
        ] as $clock => $source) {
            $this->assertTrue($januarySevenRows->has($clock));
            $this->assertSame(
                (float) DB::table('tw_futures_hourly_prices')
                    ->where('interval', '15')
                    ->where('started_at', $source['startedAt'])
                    ->value($source['priceColumn']),
                (float) $januarySevenRows->get($clock)['close'],
            );
        }

        $revision = (string) $response->json('dataRevision');
        $unchangedResponse = $this->getJson(route('tw-stock.taiex-futures.kline.data', [
            'revision' => $revision,
        ]));

        $unchangedResponse
            ->assertOk()
            ->assertJson([
                'unchanged' => true,
                'dataRevision' => $revision,
            ]);
        $this->assertArrayNotHasKey('chartRows', $unchangedResponse->json());
        $this->assertStringContainsString('no-store', (string) $unchangedResponse->headers->get('Cache-Control'));
    }

    public function test_taiex_futures_price_alert_uses_15k_end_time_and_5_percent_bias_threshold(): void
    {
        config()->set('app.timezone', 'Asia/Taipei');
        config()->set('app.url', 'https://stock.mystar.monster');

        $command = new NotifyTaiexFuturesLineAlertsCommand();
        $method = new ReflectionMethod(NotifyTaiexFuturesLineAlertsCommand::class, 'priceAlert');
        $method->setAccessible(true);

        $baseRow = [
            'time' => CarbonImmutable::parse('2026-01-01 09:00:00', 'Asia/Taipei')->timestamp,
            'localTime' => '2026-01-01 09:00',
            'gap' => null,
            'isSessionOpen' => false,
            'biasRate' => 0.0499,
            'close' => 25000,
            'dailyMa5' => 25200,
            'movingAverage' => 24000,
        ];
        $minTimestamp = CarbonImmutable::parse('2026-01-01 08:30:00', 'Asia/Taipei')->timestamp;

        $this->assertNull($method->invoke($command, $baseRow, $minTimestamp));

        $alert = $method->invoke($command, [
            ...$baseRow,
            'biasRate' => 0.05,
        ], $minTimestamp);

        $this->assertIsArray($alert);
        $this->assertSame(
            CarbonImmutable::parse('2026-01-01 09:00:00', 'Asia/Taipei')->timestamp,
            $alert['time'],
        );
        $this->assertStringContainsString('台指期通知 2026-01-01 09:00', $alert['message']);
        $this->assertStringContainsString('乖離率 +5.00% 高於 +5.00%', $alert['message']);

        $gapAlert = $method->invoke($command, [
            ...$baseRow,
            'gap' => -1000,
        ], $minTimestamp);

        $this->assertIsArray($gapAlert);
        $this->assertStringContainsString('差值 -1,000點 低於 -1,000點', $gapAlert['message']);

        $this->assertNull($method->invoke(
            $command,
            [...$baseRow, 'biasRate' => 0.05],
            $minTimestamp,
            '2026-01-01 08:45',
        ));
        $this->assertIsArray($method->invoke(
            $command,
            [...$baseRow, 'biasRate' => 0.05],
            $minTimestamp,
            '2026-01-01 09:00',
        ));
    }

    public function test_taiex_futures_line_alert_sends_gap_and_bias_at_each_15k_boundary(): void
    {
        Cache::flush();
        Carbon::setTestNow('2026-07-17 16:30:00');
        CarbonImmutable::setTestNow('2026-07-17 16:30:00');

        $this->mock(TwFuturesHourlyPriceFetcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetchRows')
                ->once()
                ->andReturn([]);
            $mock->shouldReceive('upsertRows')
                ->once()
                ->with([])
                ->andReturn(0);
            $mock->shouldReceive('fetchCurrentTaifexQuoteSnapshot')
                ->twice()
                ->andReturn(null);
        });
        $this->mock(TwFuturesHourlyPriceController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('lineAlertPayload')
                ->twice()
                ->andReturn([
                    'chartRows' => [
                        [
                            'time' => CarbonImmutable::parse('2026-07-17 16:30:00', 'Asia/Taipei')->timestamp,
                            'localTime' => '2026-07-17 16:30',
                            'gap' => -1033.3632,
                            'isSessionOpen' => false,
                            'biasRate' => -0.07110657,
                            'close' => 42350,
                            'dailyMa5' => 44328,
                            'movingAverage' => 45361.3632,
                        ],
                    ],
                    'fourHourMa5Rows' => [],
                    'stats' => ['latestGap' => -1033.3632],
                ]);
        });

        config()->set('app.url', 'https://stock.mystar.monster');
        config()->set('line.channel_access_token', 'stock-line-token');
        config()->set('line.dashboard_notify_target_id', 'Cstocktarget');
        config()->set('line.yuanta_channel_access_token', 'yuanta-line-token');
        config()->set('line.yuanta_dashboard_notify_target_id', 'Cyuantatarget');

        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200),
        ]);

        $this->artisan('tw-stock:notify-taiex-futures-line')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $message = (string) data_get($request->data(), 'messages.0.text', '');

            return str_contains($message, '台指期通知 2026-07-17 16:30')
                && str_contains($message, '差值 -1,033點 低於 -1,000點')
                && str_contains($message, '乖離率 -7.11% 低於 -5.00%')
                && str_contains($message, '現價 42,350');
        });

        Carbon::setTestNow('2026-07-17 16:35:00');
        CarbonImmutable::setTestNow('2026-07-17 16:35:00');

        $this->artisan('tw-stock:notify-taiex-futures-line')->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_taiex_futures_line_alert_retries_the_latest_15k_row_five_minutes_later(): void
    {
        Cache::flush();
        Carbon::setTestNow('2026-07-17 16:35:00');
        CarbonImmutable::setTestNow('2026-07-17 16:35:00');

        $this->mock(TwFuturesHourlyPriceFetcher::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('fetchRows');
            $mock->shouldNotReceive('upsertRows');
            $mock->shouldReceive('fetchCurrentTaifexQuoteSnapshot')
                ->once()
                ->andReturn(null);
        });
        $this->mock(TwFuturesHourlyPriceController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('lineAlertPayload')
                ->once()
                ->andReturn([
                    'chartRows' => [
                        [
                            'time' => CarbonImmutable::parse('2026-07-17 16:30:00', 'Asia/Taipei')->timestamp,
                            'localTime' => '2026-07-17 16:30',
                            'gap' => -1033.3632,
                            'isSessionOpen' => false,
                            'biasRate' => -0.07110657,
                            'close' => 42350,
                            'dailyMa5' => 44328,
                            'movingAverage' => 45361.3632,
                        ],
                    ],
                    'fourHourMa5Rows' => [],
                    'stats' => ['latestGap' => -1033.3632],
                ]);
        });

        config()->set('app.url', 'https://stock.mystar.monster');
        config()->set('line.channel_access_token', 'stock-line-token');
        config()->set('line.dashboard_notify_target_id', 'Cstocktarget');
        config()->set('line.yuanta_channel_access_token', 'yuanta-line-token');
        config()->set('line.yuanta_dashboard_notify_target_id', 'Cyuantatarget');

        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200),
        ]);

        $this->artisan('tw-stock:notify-taiex-futures-line')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $message = (string) data_get($request->data(), 'messages.0.text', '');

            return str_contains($message, '台指期通知 2026-07-17 16:30')
                && str_contains($message, '差值 -1,033點 低於 -1,000點');
        });
    }

    public function test_taiex_futures_line_alert_uses_live_quote_during_the_current_15k_bar(): void
    {
        Cache::flush();
        Carbon::setTestNow('2026-07-20 09:40:03');
        CarbonImmutable::setTestNow('2026-07-20 09:40:03');

        $this->mock(TwFuturesHourlyPriceFetcher::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('fetchRows');
            $mock->shouldNotReceive('upsertRows');
            $mock->shouldReceive('fetchCurrentTaifexQuoteSnapshot')
                ->once()
                ->andReturn([
                    'price' => 42524.0,
                    'quotedAt' => CarbonImmutable::parse('2026-07-20 09:40:01', 'Asia/Taipei'),
                    'symbolId' => 'TXFH6-F',
                ]);
        });
        $this->partialMock(TwFuturesHourlyPriceController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('lineAlertPayload')
                ->once()
                ->andReturn([
                    'chartRows' => [
                        [
                            'time' => CarbonImmutable::parse('2026-07-20 09:30:00', 'Asia/Taipei')->timestamp,
                            'localTime' => '2026-07-20 09:30',
                            'gap' => -494.1211,
                            'isSessionOpen' => false,
                            'biasRate' => -0.04525555,
                            'close' => 42994,
                            'dailyMa5' => 44455,
                            'movingAverage' => 44940.4132,
                        ],
                    ],
                    'fourHourMa5Rows' => [],
                    'stats' => [
                        'latestClose' => 42994,
                        'latestDailyMa5' => 44455,
                        'latestFiveMinuteClose' => 42994,
                        'latestMovingAverage' => 44940.4132,
                        'latestGap' => -485.4132,
                    ],
                ]);
        });

        config()->set('app.url', 'https://stock.mystar.monster');
        config()->set('line.channel_access_token', 'stock-line-token');
        config()->set('line.dashboard_notify_target_id', 'Cstocktarget');
        config()->set('line.yuanta_channel_access_token', 'yuanta-line-token');
        config()->set('line.yuanta_dashboard_notify_target_id', 'Cyuantatarget');

        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200),
        ]);

        $this->artisan('tw-stock:notify-taiex-futures-line')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $message = (string) data_get($request->data(), 'messages.0.text', '');

            return str_contains($message, '台指期通知 2026-07-20 09:30')
                && str_contains($message, '乖離率 -5.68% 低於 -5.00%')
                && str_contains($message, '現價 42,524')
                && str_contains($message, '即時報價時間 2026-07-20 09:40:01');
        });
    }

    public function test_hourly_fetcher_returns_a_fresh_taifex_quote_snapshot(): void
    {
        $now = CarbonImmutable::parse('2026-07-20 09:40:03', 'Asia/Taipei');
        Http::fake([
            'https://mis.taifex.com.tw/futures/api/getQuoteList' => Http::response([
                'RtCode' => '0',
                'RtData' => [
                    'QuoteList' => [
                        [
                            'SymbolID' => 'TXFH6-F',
                            'CLastPrice' => '42524.00',
                            'CDate' => '20260720',
                            'CTime' => '094001',
                        ],
                    ],
                ],
            ]),
        ]);

        $snapshot = app(TwFuturesHourlyPriceFetcher::class)->fetchCurrentTaifexQuoteSnapshot($now);

        $this->assertNotNull($snapshot);
        $this->assertSame(42524.0, $snapshot['price']);
        $this->assertSame('2026-07-20 09:40:01', $snapshot['quotedAt']->format('Y-m-d H:i:s'));
        $this->assertSame('TXFH6-F', $snapshot['symbolId']);
    }

    public function test_taiex_futures_line_alert_command_sends_four_hour_ma5_notification(): void
    {
        $this->seedHourlyRows();
        $currentGap = app(TwFuturesHourlyPriceController::class)->lineAlertPayload()['stats']['latestGap'];
        $currentGapText = ($currentGap >= 0 ? '+' : '') . number_format((float) $currentGap, 0) . '點';
        Cache::flush();
        Carbon::setTestNow('2026-01-07 23:00:00');
        CarbonImmutable::setTestNow('2026-01-07 23:00:00');

        $this->mock(TwFuturesHourlyPriceFetcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetchRows')
                ->twice()
                ->andReturn([]);
            $mock->shouldReceive('upsertRows')
                ->twice()
                ->with([])
                ->andReturn(0);
            $mock->shouldReceive('fetchCurrentTaifexQuoteSnapshot')
                ->once()
                ->andReturn(null);
        });

        config()->set('app.url', 'https://stock.mystar.monster');
        config()->set('line.channel_access_token', 'stock-line-token');
        config()->set('line.dashboard_notify_target_id', 'Cstocktarget');
        config()->set('line.yuanta_channel_access_token', 'yuanta-line-token');
        config()->set('line.yuanta_dashboard_notify_target_id', 'Cyuantatarget');

        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200, [
                'x-line-request-id' => 'test-request-id',
            ]),
        ]);

        $this->artisan('tw-stock:notify-taiex-futures-line', [
            '--lookback-minutes' => 999999,
            '--max-alerts' => 50,
        ])->assertExitCode(0);

        Http::assertSent(function ($request) use ($currentGapText): bool {
            $body = $request->data();
            $message = (string) data_get($body, 'messages.0.text', '');

            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && $request->hasHeader('Authorization', 'Bearer yuanta-line-token')
                && data_get($body, 'to') === 'Cyuantatarget'
                && str_contains($message, '台指期 4H MA5 通知')
                && str_contains($message, '23:00')
                && str_contains($message, '目前價格')
                && str_contains($message, '價差')
                && str_contains($message, '目前差值 ' . $currentGapText)
                && str_contains($message, 'https://stock.mystar.monster/tw-stock/taiex-futures-kline');
        });
    }

    public function test_taiex_futures_line_alert_command_does_not_backfill_four_hour_notification_at_ten(): void
    {
        $this->seedHourlyRows();
        Cache::flush();
        Carbon::setTestNow('2026-01-07 10:00:00');
        CarbonImmutable::setTestNow('2026-01-07 10:00:00');

        $this->mock(TwFuturesHourlyPriceFetcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('fetchRows')
                ->once()
                ->andReturn([]);
            $mock->shouldReceive('upsertRows')
                ->once()
                ->with([])
                ->andReturn(0);
            $mock->shouldReceive('fetchCurrentTaifexQuoteSnapshot')
                ->once()
                ->andReturn(null);
        });

        config()->set('app.url', 'https://stock.mystar.monster');
        config()->set('line.channel_access_token', 'stock-line-token');
        config()->set('line.dashboard_notify_target_id', 'Cstocktarget');
        config()->set('line.yuanta_channel_access_token', 'yuanta-line-token');
        config()->set('line.yuanta_dashboard_notify_target_id', 'Cyuantatarget');

        Http::fake([
            'https://api.line.me/v2/bot/message/push' => Http::response([], 200),
        ]);

        $this->artisan('tw-stock:notify-taiex-futures-line')->assertExitCode(0);

        Http::assertNotSent(function ($request): bool {
            $message = (string) data_get($request->data(), 'messages.0.text', '');

            return str_contains($message, '台指期 4H MA5 通知');
        });
    }

    public function test_taiex_futures_daily_ma5_uses_five_minute_dynamic_closes(): void
    {
        $this->seedHourlyRows();
        $this->seedOfficialDailyRows([
            '2026-01-01' => 30000,
            '2026-01-02' => 30010,
            '2026-01-03' => 30020,
            '2026-01-04' => 30030,
            '2026-01-05' => 30040,
            '2026-01-06' => 30050,
            '2026-01-07' => 30060,
            '2026-01-08' => 30070,
        ]);

        $response = $this->getJson(route('tw-stock.taiex-futures.kline.data'));

        $rows = $response->json('chartRows');
        $janFiveRows = collect($rows)
            ->filter(fn (array $row): bool => $row['tradeDate'] === '2026-01-05')
            ->values();
        $janFiveRow = $janFiveRows->first();
        $janFiveLastRow = $janFiveRows->last();

        $this->assertNotNull($janFiveRow);
        $this->assertNotNull($janFiveLastRow);

        $previousFiveMinuteCloses = collect(['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04'])
            ->map(fn (string $tradeDate): float => (float) DB::table('tw_futures_hourly_prices')
                ->where('interval', '5')
                ->where('trade_date', $tradeDate)
                ->orderByDesc('started_at')
                ->value('close_price'));
        $currentFiveMinuteClose = (float) DB::table('tw_futures_hourly_prices')
            ->where('interval', '5')
            ->where('started_at_unix', (int) $janFiveRow['time'] - 300)
            ->value('close_price');
        $expected = ($previousFiveMinuteCloses->sum() + $currentFiveMinuteClose) / 5;

        $this->assertEqualsWithDelta($expected, (float) $janFiveRow['dailyMa5'], 0.0001);
        $this->assertNotEquals(30020.0, (float) $janFiveRow['dailyMa5']);
        $this->assertNotEquals((float) $janFiveRow['dailyMa5'], (float) $janFiveLastRow['dailyMa5']);
    }

    public function test_taiex_futures_daily_fetcher_stores_rows_verified_by_self_calculation(): void
    {
        DB::table('tw_futures_hourly_prices')->insert([
            $this->priceRow(
                interval: '15',
                startedAt: CarbonImmutable::parse('2026-06-24 08:45:00', 'Asia/Taipei'),
                tradeDate: '2026-06-24',
                sessionType: 'day',
                close: 46000,
                cursor: 1,
                now: now(),
            ),
            $this->priceRow(
                interval: '15',
                startedAt: CarbonImmutable::parse('2026-06-24 13:30:00', 'Asia/Taipei'),
                tradeDate: '2026-06-24',
                sessionType: 'day',
                close: 46387,
                cursor: 2,
                now: now(),
            ),
        ]);

        Http::fake([
            'https://www.taifex.com.tw/cht/3/futDataDown' => Http::response($this->taifexDailyCsv('2026/06/24', '202607', '46387'), 200),
        ]);

        $fetcher = app(TwFuturesDailyPriceFetcher::class);
        $rows = $fetcher->fetchRows(from: '2026-06-24', to: '2026-06-24');
        $result = $fetcher->upsertVerifiedRows($rows);

        $this->assertSame(1, $result['stored']);
        $this->assertSame(0, $result['skipped']);
        $dailyRow = DB::table('tw_futures_daily_prices')->where('trade_date', '2026-06-24')->first();
        $this->assertNotNull($dailyRow);
        $this->assertEqualsWithDelta(46387.0, (float) $dailyRow->close_price, 0.0001);
        $this->assertStringContainsString('15K self-calculated daily close', (string) $dailyRow->verified_sources);
    }

    public function test_taiex_futures_daily_fetcher_uses_history_page_as_third_source_on_self_mismatch(): void
    {
        DB::table('tw_futures_hourly_prices')->insert([
            $this->priceRow(
                interval: '15',
                startedAt: CarbonImmutable::parse('2026-06-26 13:30:00', 'Asia/Taipei'),
                tradeDate: '2026-06-26',
                sessionType: 'day',
                close: 44000,
                cursor: 1,
                now: now(),
            ),
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'futDataDown')) {
                return Http::response($this->taifexDailyCsv('2026/06/26', '202607', '44373'), 200);
            }

            if (str_contains($request->url(), 'futDailyMarketReport')) {
                return Http::response('<table><tr><td>TX</td><td>202607</td><td>45709</td><td>45922</td><td>44264</td><td>44373</td></tr></table>', 200);
            }

            return Http::response('', 404);
        });

        $fetcher = app(TwFuturesDailyPriceFetcher::class);
        $rows = $fetcher->fetchRows(from: '2026-06-26', to: '2026-06-26');
        $result = $fetcher->upsertVerifiedRows($rows);

        $this->assertSame(1, $result['stored']);
        $this->assertNotEmpty($result['mismatches']);
        $dailyRow = DB::table('tw_futures_daily_prices')->where('trade_date', '2026-06-26')->first();
        $this->assertNotNull($dailyRow);
        $this->assertStringContainsString('TAIFEX official futures history page', (string) $dailyRow->verified_sources);
    }

    public function test_futures_night_session_trade_date_skips_weekends(): void
    {
        $fetcher = app(TwFuturesHourlyPriceFetcher::class);
        $method = new ReflectionMethod(TwFuturesHourlyPriceFetcher::class, 'tradeDate');
        $method->setAccessible(true);

        $this->assertSame(
            '2026-05-25',
            $method->invoke($fetcher, CarbonImmutable::parse('2026-05-22 15:00:00', 'Asia/Taipei')),
        );
        $this->assertSame(
            '2026-05-25',
            $method->invoke($fetcher, CarbonImmutable::parse('2026-05-23 04:00:00', 'Asia/Taipei')),
        );
        $this->assertSame(
            '2026-05-26',
            $method->invoke($fetcher, CarbonImmutable::parse('2026-05-25 15:00:00', 'Asia/Taipei')),
        );
        $this->assertSame(
            '2026-05-26',
            $method->invoke($fetcher, CarbonImmutable::parse('2026-05-26 04:00:00', 'Asia/Taipei')),
        );
    }

    public function test_futures_continuous_contract_rolls_on_expiration_date(): void
    {
        $command = new BackfillTwFuturesContinuousPricesCommand();

        $frontContractMonthForDate = new ReflectionMethod(BackfillTwFuturesContinuousPricesCommand::class, 'frontContractMonthForDate');
        $frontContractMonthForDate->setAccessible(true);
        $thirdWednesday = new ReflectionMethod(BackfillTwFuturesContinuousPricesCommand::class, 'thirdWednesday');
        $thirdWednesday->setAccessible(true);
        $contractCode = new ReflectionMethod(BackfillTwFuturesContinuousPricesCommand::class, 'contractCode');
        $contractCode->setAccessible(true);

        $this->assertSame(
            '2026-05-01',
            $frontContractMonthForDate->invoke($command, CarbonImmutable::parse('2026-05-19 23:45:00', 'Asia/Taipei'))->toDateString(),
        );
        $this->assertSame(
            '2026-06-01',
            $frontContractMonthForDate->invoke($command, CarbonImmutable::parse('2026-05-20 00:00:00', 'Asia/Taipei'))->toDateString(),
        );
        $this->assertSame(
            '2026-07-01',
            $frontContractMonthForDate->invoke($command, CarbonImmutable::parse('2026-06-17 08:45:00', 'Asia/Taipei'))->toDateString(),
        );

        $this->assertSame(
            '2026-06-17',
            $thirdWednesday->invoke($command, CarbonImmutable::parse('2026-06-01', 'Asia/Taipei'))->toDateString(),
        );
        $this->assertSame(
            'TXFN2026',
            $contractCode->invoke($command, CarbonImmutable::parse('2026-07-01', 'Asia/Taipei')),
        );
        $this->assertSame(
            'TXFF2025',
            $contractCode->invoke($command, CarbonImmutable::parse('2025-01-01', 'Asia/Taipei')),
        );
    }

    public function test_futures_expected_current_bar_tracks_open_sessions(): void
    {
        $fetcher = app(TwFuturesHourlyPriceFetcher::class);
        $method = new ReflectionMethod(TwFuturesHourlyPriceFetcher::class, 'expectedCurrentBarStartedAtUnix');
        $method->setAccessible(true);

        Carbon::setTestNow('2026-06-23 15:03:00');
        CarbonImmutable::setTestNow('2026-06-23 15:03:00');
        $this->assertSame(
            CarbonImmutable::parse('2026-06-23 15:00:00', 'Asia/Taipei')->timestamp,
            $method->invoke($fetcher, '15'),
        );

        Carbon::setTestNow('2026-06-23 13:50:00');
        CarbonImmutable::setTestNow('2026-06-23 13:50:00');
        $this->assertNull($method->invoke($fetcher, '15'));

        Carbon::setTestNow('2026-06-24 04:05:00');
        CarbonImmutable::setTestNow('2026-06-24 04:05:00');
        $this->assertSame(
            CarbonImmutable::parse('2026-06-24 04:00:00', 'Asia/Taipei')->timestamp,
            $method->invoke($fetcher, '60'),
        );
    }

    public function test_futures_night_opening_bar_can_be_filled_from_taifex_snapshot(): void
    {
        Carbon::setTestNow('2026-06-23 15:03:00');
        CarbonImmutable::setTestNow('2026-06-23 15:03:00');
        Http::fake([
            'https://mis.taifex.com.tw/futures/api/getQuoteList' => Http::response([
                'RtCode' => '0',
                'RtMsg' => '',
                'RtData' => [
                    'QuoteList' => [
                        [
                            'SymbolID' => 'TXF-P',
                            'CLastPrice' => '',
                        ],
                        [
                            'SymbolID' => 'TXFG6-M',
                            'CDate' => '20260623',
                            'CTime' => '150312',
                            'COpenPrice' => '46940.00',
                            'CHighPrice' => '47072.00',
                            'CLowPrice' => '46731.00',
                            'CLastPrice' => '47025.00',
                            'CTotalVolume' => '4359',
                        ],
                    ],
                ],
            ]),
        ]);

        $fetcher = app(TwFuturesHourlyPriceFetcher::class);
        $method = new ReflectionMethod(TwFuturesHourlyPriceFetcher::class, 'appendCurrentSessionOpeningQuoteRow');
        $method->setAccessible(true);
        $rows = [
            $this->priceRow(
                interval: '15',
                startedAt: CarbonImmutable::parse('2026-06-23 13:30:00', 'Asia/Taipei'),
                tradeDate: '2026-06-23',
                sessionType: 'day',
                close: 47431,
                cursor: 1,
                now: now(),
            ),
        ];

        $result = $method->invoke(
            $fetcher,
            $rows,
            CarbonImmutable::parse('2026-06-23', 'Asia/Taipei')->startOfDay(),
            CarbonImmutable::parse('2026-06-23', 'Asia/Taipei')->endOfDay(),
            'TXF1!',
            '15',
        );

        $this->assertCount(2, $result);
        $row = $result[array_key_last($result)];
        $this->assertSame('2026-06-23 15:00:00', $row['started_at']);
        $this->assertSame('2026-06-24', $row['trade_date']);
        $this->assertSame('night', $row['session_type']);
        $this->assertSame('46940.0000', $row['open_price']);
        $this->assertSame('47072.0000', $row['high_price']);
        $this->assertSame('46731.0000', $row['low_price']);
        $this->assertSame('47025.0000', $row['close_price']);
        $this->assertSame(4359, $row['volume_contracts']);
        $this->assertSame('TAIFEX official quote snapshot', $row['source']);
        $this->assertSame('TXFG6-M', $row['source_payload']['symbol_id']);
    }

    public function test_futures_day_opening_bar_can_be_filled_from_taifex_snapshot(): void
    {
        Carbon::setTestNow('2026-06-24 08:55:00');
        CarbonImmutable::setTestNow('2026-06-24 08:55:00');
        Http::fake([
            'https://mis.taifex.com.tw/futures/api/getQuoteList' => Http::response([
                'RtCode' => '0',
                'RtMsg' => '',
                'RtData' => [
                    'QuoteList' => [
                        [
                            'SymbolID' => 'TXF-S',
                            'CLastPrice' => '',
                        ],
                        [
                            'SymbolID' => 'TXFG6-F',
                            'CDate' => '20260624',
                            'CTime' => '085516',
                            'COpenPrice' => '46858.00',
                            'CHighPrice' => '47067.00',
                            'CLowPrice' => '46606.00',
                            'CLastPrice' => '46657.00',
                            'CTotalVolume' => '7114',
                        ],
                    ],
                ],
            ]),
        ]);

        $fetcher = app(TwFuturesHourlyPriceFetcher::class);
        $method = new ReflectionMethod(TwFuturesHourlyPriceFetcher::class, 'appendCurrentSessionOpeningQuoteRow');
        $method->setAccessible(true);
        $rows = [
            $this->priceRow(
                interval: '15',
                startedAt: CarbonImmutable::parse('2026-06-24 04:45:00', 'Asia/Taipei'),
                tradeDate: '2026-06-24',
                sessionType: 'night',
                close: 46207,
                cursor: 1,
                now: now(),
            ),
        ];

        $result = $method->invoke(
            $fetcher,
            $rows,
            CarbonImmutable::parse('2026-06-24', 'Asia/Taipei')->startOfDay(),
            CarbonImmutable::parse('2026-06-24', 'Asia/Taipei')->endOfDay(),
            'TXF1!',
            '15',
        );

        $this->assertCount(2, $result);
        $row = $result[array_key_last($result)];
        $this->assertSame('2026-06-24 08:45:00', $row['started_at']);
        $this->assertSame('2026-06-24', $row['trade_date']);
        $this->assertSame('day', $row['session_type']);
        $this->assertSame('46858.0000', $row['open_price']);
        $this->assertSame('47067.0000', $row['high_price']);
        $this->assertSame('46606.0000', $row['low_price']);
        $this->assertSame('46657.0000', $row['close_price']);
        $this->assertSame(7114, $row['volume_contracts']);
        $this->assertSame('TAIFEX official quote snapshot', $row['source']);
        $this->assertSame('TXFG6-F', $row['source_payload']['symbol_id']);
    }

    public function test_yahoo_minute_fetcher_aligns_closed_session_with_taifex_quote(): void
    {
        $this->fakeYahooLatestFiveMinuteBar();

        $rows = app(TwFuturesYahooMinutePriceFetcher::class)->fetchLatestAggregatedRows('5');

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('2026-06-27 04:55:00', $row['started_at']);
        $this->assertSame('2026-06-29', $row['trade_date']);
        $this->assertSame('night', $row['session_type']);
        $this->assertSame('45025.0000', $row['open_price']);
        $this->assertSame('45036.0000', $row['high_price']);
        $this->assertSame('44995.0000', $row['low_price']);
        $this->assertSame('44995.0000', $row['close_price']);
        $this->assertSame(133, $row['volume_contracts']);
        $this->assertSame(5, $row['source_payload']['minute_count']);
        $this->assertSame(-172800, $row['source_payload']['timestamp_shift_seconds']);
    }

    public function test_hourly_fetcher_uses_yahoo_taifex_row_when_latest_yahoo_and_tradingview_mismatch(): void
    {
        $this->fakeYahooLatestFiveMinuteBar();

        $tradingViewRow = $this->priceRow(
            interval: '5',
            startedAt: CarbonImmutable::parse('2026-06-27 04:55:00', 'Asia/Taipei'),
            tradeDate: '2026-06-29',
            sessionType: 'night',
            close: 44994,
            cursor: 1,
            now: now(),
        );
        $tradingViewRow['open_price'] = '45025.0000';
        $tradingViewRow['high_price'] = '45036.0000';
        $tradingViewRow['low_price'] = '44995.0000';
        $tradingViewRow['volume_contracts'] = 132;
        $tradingViewRow['source'] = 'TradingView chart websocket';
        $tradingViewRow['source_payload'] = ['tradingview_symbol' => 'TAIFEX:TXF1!'];

        $fetcher = app(TwFuturesHourlyPriceFetcher::class);
        $method = new ReflectionMethod(TwFuturesHourlyPriceFetcher::class, 'mergeYahooLatestValidation');
        $method->setAccessible(true);

        $rows = $method->invoke(
            $fetcher,
            [$tradingViewRow],
            CarbonImmutable::parse('2026-06-26', 'Asia/Taipei')->startOfDay(),
            CarbonImmutable::parse('2026-06-29', 'Asia/Taipei')->endOfDay(),
            'TXF1!',
            '5',
        );

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertEqualsWithDelta(44995.0, (float) $row['close_price'], 0.0001);
        $this->assertSame(133, $row['volume_contracts']);
        $this->assertSame('Yahoo Taiwan Finance 1m self-aggregate + TAIFEX official quote snapshot', $row['source']);
        $this->assertArrayNotHasKey('skip_upsert', $row);
        $this->assertSame(
            'yahoo_taifex_session_used_after_tradingview_mismatch',
            $row['source_payload']['validation']['status'],
        );
        $this->assertSame('TradingView chart websocket', $row['source_payload']['validation']['rejected_source']);
        $this->assertNotEmpty($row['source_payload']['validation']['mismatches']);
        $this->assertSame(44994.0, $row['source_payload']['validation']['tradingview']['close_price']);
    }

    public function test_hourly_fetcher_deletes_existing_row_when_validation_skips_upsert(): void
    {
        $existingRow = $this->priceRow(
            interval: '15',
            startedAt: CarbonImmutable::parse('2026-06-27 00:15:00', 'Asia/Taipei'),
            tradeDate: '2026-06-29',
            sessionType: 'night',
            close: 45085,
            cursor: 1,
            now: now(),
        );
        $existingRow['source'] = 'TAIFEX official futures tick CSV aggregate';

        DB::table('tw_futures_hourly_prices')->insert($existingRow);

        $skippedRow = $existingRow;
        $skippedRow['skip_upsert'] = true;
        $skippedRow['source'] = 'TradingView chart websocket';
        $skippedRow['source_payload'] = [
            'validation' => [
                'status' => 'tradingview_yahoo_mismatch_skipped_needs_third_source',
            ],
        ];

        $stored = app(TwFuturesHourlyPriceFetcher::class)->upsertRows([$skippedRow]);

        $this->assertSame(0, $stored);
        $this->assertDatabaseMissing('tw_futures_hourly_prices', [
            'exchange' => 'TAIFEX',
            'symbol' => 'TXF1!',
            'interval' => '15',
            'started_at' => '2026-06-27 00:15:00',
        ]);
    }

    public function test_futures_hourly_fetcher_upserts_large_batches_in_chunks(): void
    {
        $rows = [];
        $now = now();
        $startedAt = CarbonImmutable::parse('2026-06-01 08:45:00', 'Asia/Taipei');

        foreach (range(0, 449) as $index) {
            $row = $this->priceRow(
                interval: '5',
                startedAt: $startedAt->addMinutes($index * 5),
                tradeDate: '2026-06-01',
                sessionType: 'day',
                close: 25000 + $index,
                cursor: $index,
                now: $now,
            );
            $row['source_payload'] = ['index' => $index];
            $rows[] = $row;
        }

        $upsertQueries = 0;
        DB::listen(function ($query) use (&$upsertQueries): void {
            if (str_contains((string) $query->sql, 'tw_futures_hourly_prices')) {
                $upsertQueries++;
            }
        });

        $stored = app(TwFuturesHourlyPriceFetcher::class)->upsertRows($rows);

        $this->assertSame(450, $stored);
        $this->assertSame(3, $upsertQueries);
        $this->assertSame(450, DB::table('tw_futures_hourly_prices')->count());
    }

    private function seedHourlyRows(): void
    {
        $now = now();
        $rows = [];
        $this->appendFiveMinuteRows($rows, $now);
        $this->appendFifteenMinuteRows($rows, $now);
        $this->appendHourlyRows($rows, $now);

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('tw_futures_hourly_prices')->insert($chunk);
        }
    }

    /**
     * @param array<string, int> $closesByDate
     */
    private function seedOfficialDailyRows(array $closesByDate): void
    {
        $rows = [];
        foreach ($closesByDate as $tradeDate => $close) {
            $rows[] = [
                'exchange' => 'TAIFEX',
                'symbol' => 'TXF1!',
                'symbol_name' => '台指期近月連續',
                'contract_code' => 'TX',
                'contract_month' => '202601',
                'trade_date' => $tradeDate,
                'session_type' => 'day',
                'open_price' => $close - 100,
                'high_price' => $close + 100,
                'low_price' => $close - 200,
                'close_price' => $close,
                'settlement_price' => $close,
                'volume_contracts' => 1000,
                'open_interest' => 1000,
                'source' => 'test official daily close',
                'source_payload' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
                'verified_sources' => json_encode(['test'], JSON_THROW_ON_ERROR),
                'validation_status' => 'verified',
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('tw_futures_daily_prices')->insert($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function appendFiveMinuteRows(array &$rows, Carbon $now): void
    {
        $cursor = 0;

        foreach (range(0, 6) as $dayOffset) {
            $date = CarbonImmutable::parse('2026-01-01', 'Asia/Taipei')->addDays($dayOffset);

            for ($minutes = 0; $minutes < 330; $minutes += 5) {
                $startedAt = $date->setTime(8, 45)->addMinutes($minutes);
                $close = 24000 + $cursor;
                $rows[] = $this->priceRow(
                    interval: '5',
                    startedAt: $startedAt,
                    tradeDate: $date->toDateString(),
                    sessionType: 'day',
                    close: $close,
                    cursor: $cursor,
                    now: $now,
                );
                $cursor++;
            }

            for ($minutes = 0; $minutes < 870; $minutes += 5) {
                $startedAt = $date->setTime(15, 0)->addMinutes($minutes);
                $close = 24000 + $cursor;
                $rows[] = $this->priceRow(
                    interval: '5',
                    startedAt: $startedAt,
                    tradeDate: $date->addDay()->toDateString(),
                    sessionType: 'night',
                    close: $close,
                    cursor: $cursor,
                    now: $now,
                );
                $cursor++;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function appendFifteenMinuteRows(array &$rows, Carbon $now): void
    {
        $cursor = 0;

        foreach (range(0, 6) as $dayOffset) {
            $date = CarbonImmutable::parse('2026-01-01', 'Asia/Taipei')->addDays($dayOffset);

            for ($minutes = 0; $minutes < 330; $minutes += 15) {
                $startedAt = $date->setTime(8, 45)->addMinutes($minutes);
                $close = 24000 + $cursor * 2;
                $rows[] = $this->priceRow(
                    interval: '15',
                    startedAt: $startedAt,
                    tradeDate: $date->toDateString(),
                    sessionType: 'day',
                    close: $close,
                    cursor: $cursor,
                    now: $now,
                );
                $cursor++;
            }

            for ($minutes = 0; $minutes < 870; $minutes += 15) {
                $startedAt = $date->setTime(15, 0)->addMinutes($minutes);
                $close = 24000 + $cursor * 2;
                $rows[] = $this->priceRow(
                    interval: '15',
                    startedAt: $startedAt,
                    tradeDate: $date->addDay()->toDateString(),
                    sessionType: 'night',
                    close: $close,
                    cursor: $cursor,
                    now: $now,
                );
                $cursor++;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function appendHourlyRows(array &$rows, Carbon $now): void
    {
        $dayTimes = ['08:45', '09:45', '10:45', '11:45', '12:45', '13:45'];
        $nightTimes = ['15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00', '23:00'];
        $cursor = 0;

        foreach (range(0, 8) as $dayOffset) {
            $date = CarbonImmutable::parse('2026-01-01', 'Asia/Taipei')->addDays($dayOffset);
            foreach ([...$dayTimes, ...$nightTimes] as $time) {
                $startedAt = CarbonImmutable::parse($date->toDateString() . ' ' . $time, 'Asia/Taipei');
                $close = 24000 + $cursor * 8;
                $rows[] = $this->priceRow(
                    interval: '60',
                    startedAt: $startedAt,
                    tradeDate: $time >= '15:00' ? $date->addDay()->toDateString() : $date->toDateString(),
                    sessionType: $time >= '15:00' ? 'night' : 'day',
                    close: $close,
                    cursor: $cursor,
                    now: $now,
                );
                $cursor++;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function priceRow(
        string $interval,
        CarbonImmutable $startedAt,
        string $tradeDate,
        string $sessionType,
        int $close,
        int $cursor,
        Carbon $now,
    ): array {
        return [
            'exchange' => 'TAIFEX',
            'symbol' => 'TXF1!',
            'symbol_name' => '台指期近月連續',
            'interval' => $interval,
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'started_at_unix' => $startedAt->timestamp,
            'trade_date' => $tradeDate,
            'session_type' => $sessionType,
            'open_price' => $close - 10,
            'high_price' => $close + 18,
            'low_price' => $close - 24,
            'close_price' => $close,
            'volume_contracts' => 1000 + $cursor,
            'source' => 'test',
            'source_payload' => json_encode(['interval' => $interval, 'index' => $cursor], JSON_THROW_ON_ERROR),
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function createTables(): void
    {
        Schema::create('tw_futures_hourly_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 16)->default('TAIFEX');
            $table->string('symbol', 32);
            $table->string('symbol_name')->default('台指期近月連續');
            $table->string('interval', 8)->default('60');
            $table->dateTime('started_at');
            $table->unsignedBigInteger('started_at_unix');
            $table->date('trade_date')->nullable();
            $table->string('session_type', 16)->nullable();
            $table->decimal('open_price', 12, 4);
            $table->decimal('high_price', 12, 4);
            $table->decimal('low_price', 12, 4);
            $table->decimal('close_price', 12, 4);
            $table->unsignedBigInteger('volume_contracts')->default(0);
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['exchange', 'symbol', 'interval', 'started_at']);
        });

        Schema::create('tw_futures_daily_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 16)->default('TAIFEX');
            $table->string('symbol', 32);
            $table->string('symbol_name')->default('台指期近月連續');
            $table->string('contract_code', 16)->default('TX');
            $table->string('contract_month', 16);
            $table->date('trade_date');
            $table->string('session_type', 16)->default('day');
            $table->decimal('open_price', 12, 4);
            $table->decimal('high_price', 12, 4);
            $table->decimal('low_price', 12, 4);
            $table->decimal('close_price', 12, 4);
            $table->decimal('settlement_price', 12, 4)->nullable();
            $table->unsignedBigInteger('volume_contracts')->default(0);
            $table->unsignedBigInteger('open_interest')->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->json('verified_sources')->nullable();
            $table->string('validation_status', 24)->default('verified');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['exchange', 'symbol', 'session_type', 'trade_date']);
        });
    }

    private function taifexDailyCsv(string $tradeDate, string $contractMonth, string $close): string
    {
        return implode("\n", [
            '交易日期,契約,到期月份(週別),開盤價,最高價,最低價,收盤價,漲跌價,漲跌%,成交量,結算價,未沖銷契約數,最後最佳買價,最後最佳賣價,歷史最高價,歷史最低價,是否因訊息面暫停交易,交易時段,價差對單式委託成交量',
            "{$tradeDate},TX,{$contractMonth}  ,45709,45922,44264,{$close},-2154,-4.63%,102163,44452,101073,44372,44401,49240,36972,,一般,,",
            "{$tradeDate},TX,{$contractMonth}  ,46430,46884,45311,45805,-722,-1.55%,93096,-,-,45804,45806,49240,36972,,盤後,,",
        ]);
    }

    private function fakeYahooLatestFiveMinuteBar(): void
    {
        $timestamps = [];
        foreach (['2026-06-29 04:56:00', '2026-06-29 04:57:00', '2026-06-29 04:58:00', '2026-06-29 04:59:00', '2026-06-29 05:00:00'] as $time) {
            $timestamps[] = CarbonImmutable::parse($time, 'Asia/Taipei')->timestamp;
        }

        Http::fake([
            'https://tw.stock.yahoo.com/*' => Http::response([
                'data' => [
                    [
                        'chart' => [
                            'timestamp' => $timestamps,
                            'indicators' => [
                                'quote' => [
                                    [
                                        'open' => [45025, 45018, 45015, 45015, 45009],
                                        'high' => [45036, 45025, 45025, 45017, 45020],
                                        'low' => [45018, 45014, 45014, 45000, 44995],
                                        'close' => [45025, 45014, 45015, 45017, 44995],
                                        'volume' => [28, 5, 6, 50, 44],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
            'https://mis.taifex.com.tw/futures/api/getQuoteList' => Http::response([
                'RtCode' => '0',
                'RtMsg' => '',
                'RtData' => [
                    'QuoteList' => [
                        [
                            'SymbolID' => 'TXFG6-M',
                            'CDate' => '20260626',
                            'CTime' => '045956',
                            'COpenPrice' => '45025.00',
                            'CHighPrice' => '45036.00',
                            'CLowPrice' => '44995.00',
                            'CLastPrice' => '44995.00',
                            'CTotalVolume' => '133',
                        ],
                    ],
                ],
            ]),
        ]);
    }

}
