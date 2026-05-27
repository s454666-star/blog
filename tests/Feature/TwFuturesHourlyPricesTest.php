<?php

namespace Tests\Feature;

use App\Services\TwFuturesHourlyPriceFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('台指期 60K 差值 K 線')
            ->assertSee('TAIFEX · TXF1! · 60K')
            ->assertSee('60K MA95')
            ->assertSee('日 MA5')
            ->assertSee('差值')
            ->assertSee('const dailyChartRows =', false)
            ->assertSee('data-timeframe="hourly"', false)
            ->assertSee('data-timeframe="daily"', false)
            ->assertSee('60分K')
            ->assertSee('日線')
            ->assertSee('const gapMarkers =', false)
            ->assertSee('const dailyGapMarkers =', false)
            ->assertSee('const ma95Data =', false)
            ->assertSee('fixRightEdge: true', false)
            ->assertSee('data-toggle-series="gap"', false)
            ->assertSee("upColor: '#ef5350'", false)
            ->assertSee("downColor: '#26a69a'", false)
            ->assertSee("timeZone: 'Asia/Taipei'", false)
            ->assertSee('tickMarkFormatter: formatTaipeiAxisTime', false)
            ->assertSee('candleSeries.createPriceLine', false)
            ->assertSee('toggleTemporaryPriceLine', false)
            ->assertSee('data-marker-count', false)
            ->assertSee('marker-label-layer', false)
            ->assertSee('chartMarkerData', false)
            ->assertSee('renderMarkerLabels', false)
            ->assertSee('data-gap-axis', false)
            ->assertSee('renderGapAxis', false)
            ->assertSee('startGapAxisDrag', false)
            ->assertSee('gapAutoscaleInfoProvider', false)
            ->assertSee('GAP_AXIS_DRAG_SENSITIVITY', false)
            ->assertSee('GAP_AXIS_TICK_MIN_GAP', false)
            ->assertSee('scheduleMarkerLabelRender', false)
            ->assertSee('startMarkerLabelRenderLoop', false)
            ->assertSee('const gapSeries = chart.addBaselineSeries', false)
            ->assertSee("priceScaleId: 'gap'", false)
            ->assertSee("chart.priceScale('gap').applyOptions", false)
            ->assertSee("topLineColor: '#f59e0b'", false)
            ->assertSee("bottomLineColor: '#38bdf8'", false)
            ->assertSee('長按左鍵 1.5 秒可標記或取消既有標記')
            ->assertSee('開盤差值')
            ->assertSee('收盤差值')
            ->assertSee('"shape":"circle"', false)
            ->assertSee('台指期K線');

        preg_match('/const dailyChartRows = (.*);/', (string) $response->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $dailyRows = json_decode((string) $matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty(array_filter(
            $dailyRows,
            fn (array $row): bool => $row['ma95'] !== null && $row['gap'] !== null,
        ));

        preg_match('/const dailyGapMarkers = (.*);/', (string) $response->getContent(), $markerMatches);
        $this->assertNotEmpty($markerMatches[1] ?? null);

        $dailyGapMarkers = json_decode((string) $markerMatches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($dailyGapMarkers);
        $this->assertNotEmpty(array_filter(
            $dailyGapMarkers,
            fn (array $marker): bool => (bool) preg_match('/^[+-][0-9,]+$/', (string) $marker['text']),
        ));

        preg_match('/const gapMarkers = (.*);/', (string) $response->getContent(), $hourlyMarkerMatches);
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

    private function seedHourlyRows(): void
    {
        $now = now();
        $rows = [];
        $dayTimes = ['08:45', '09:45', '10:45', '11:45', '12:45', '13:45'];
        $nightTimes = ['15:00', '16:00', '17:00', '18:00', '19:00', '20:00', '21:00', '22:00', '23:00'];
        $cursor = 0;

        foreach (range(0, 8) as $dayOffset) {
            $date = CarbonImmutable::parse('2026-01-01', 'Asia/Taipei')->addDays($dayOffset);
            foreach ([...$dayTimes, ...$nightTimes] as $time) {
                $startedAt = CarbonImmutable::parse($date->toDateString() . ' ' . $time, 'Asia/Taipei');
                $close = 24000 + $cursor * 8;
                $rows[] = [
                    'exchange' => 'TAIFEX',
                    'symbol' => 'TXF1!',
                    'symbol_name' => '台指期近月連續',
                    'interval' => '60',
                    'started_at' => $startedAt->format('Y-m-d H:i:s'),
                    'started_at_unix' => $startedAt->timestamp,
                    'trade_date' => $time >= '15:00' ? $date->addDay()->toDateString() : $date->toDateString(),
                    'session_type' => $time >= '15:00' ? 'night' : 'day',
                    'open_price' => $close - 10,
                    'high_price' => $close + 18,
                    'low_price' => $close - 24,
                    'close_price' => $close,
                    'volume_contracts' => 1000 + $cursor,
                    'source' => 'test',
                    'source_payload' => json_encode(['index' => $cursor], JSON_THROW_ON_ERROR),
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $cursor++;
            }
        }

        DB::table('tw_futures_hourly_prices')->insert($rows);
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
