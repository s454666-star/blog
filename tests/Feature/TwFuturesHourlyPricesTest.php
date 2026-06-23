<?php

namespace Tests\Feature;

use App\Console\Commands\BackfillTwFuturesContinuousPricesCommand;
use App\Services\TwFuturesHourlyPriceFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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
            ->assertSee('15K MA380')
            ->assertSee('60K MA95')
            ->assertSee('日 MA5')
            ->assertSee('差值')
            ->assertSee('乖離')
            ->assertSee('乖離率')
            ->assertSee('const dailyChartRows =', false)
            ->assertSee('const hourlyChartRows =', false)
            ->assertSee('data-timeframe="hourly"', false)
            ->assertSee('data-timeframe="fifteen-minute"', false)
            ->assertSee('data-timeframe="daily"', false)
            ->assertSee('15分K')
            ->assertSee('60分K')
            ->assertSee('日線')
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
            ->assertSee('data-legend-bias-rate', false)
            ->assertSee('marker-label-layer', false)
            ->assertSee('chartMarkerData', false)
            ->assertSee('renderMarkerLabels', false)
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
            ->assertSee("color: '#a78bfa'", false)
            ->assertSee("biasSeries.setData(lineData(currentRows, 'bias'))", false)
            ->assertSee('bias: [biasSeries]', false)
            ->assertSee('.flatMap(row => [Number(row.gap), Number(row.bias)])', false)
            ->assertSee("priceScaleId: 'gap'", false)
            ->assertSee("chart.priceScale('gap').applyOptions", false)
            ->assertSee('scaleMargins: { top: 0.14, bottom: 0.06 }', false)
            ->assertSee("topLineColor: '#f59e0b'", false)
            ->assertSee("bottomLineColor: '#38bdf8'", false)
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
        $movingAveragePosition = strpos($content, '<div class="label">15K MA380</div>');
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

        $biasRows = array_values(array_filter(
            $chartRows,
            fn (array $row): bool => $row['movingAverage'] !== null && $row['bias'] !== null && $row['biasRate'] !== null,
        ));
        $this->assertNotEmpty($biasRows);
        $biasRow = $biasRows[array_key_last($biasRows)];
        $expectedBias = (float) $biasRow['close'] - (float) $biasRow['movingAverage'];
        $this->assertEqualsWithDelta($expectedBias, (float) $biasRow['bias'], 0.0001);
        $this->assertEqualsWithDelta($expectedBias / (float) $biasRow['close'], (float) $biasRow['biasRate'], 0.000001);

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

    public function test_futures_opening_bar_can_be_filled_from_taifex_snapshot(): void
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

    private function seedHourlyRows(): void
    {
        $now = now();
        $rows = [];
        $this->appendFifteenMinuteRows($rows, $now);
        $this->appendHourlyRows($rows, $now);

        DB::table('tw_futures_hourly_prices')->insert($rows);
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
    }
}
