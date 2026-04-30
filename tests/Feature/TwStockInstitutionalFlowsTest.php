<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwStockInstitutionalFlowsTest extends TestCase
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

        Schema::dropAllTables();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tw_stock_institutional_flows');

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_fetch_command_stores_twse_and_taifex_institutional_flow_data(): void
    {
        Http::fake([
            'https://www.twse.com.tw/fund/BFI82U*' => Http::response([
                'stat' => 'OK',
                'date' => '20260430',
                'title' => '115年04月30日 三大法人買賣金額統計表',
                'fields' => ['單位名稱', '買進金額', '賣出金額', '買賣差額'],
                'data' => [
                    ['投信', '14,846,886,261', '13,663,267,625', '1,183,618,636'],
                    ['外資及陸資(不含外資自營商)', '392,966,839,553', '446,528,564,661', '-53,561,725,108'],
                    ['外資自營商', '0', '0', '0'],
                ],
            ]),
            'https://www.twse.com.tw/rwd/zh/TAIEX/MI_5MINS_HIST*' => Http::sequence()
                ->push([
                    'stat' => 'OK',
                    'title' => '115年03月 發行量加權股價指數歷史資料',
                    'fields' => ['日期', '開盤指數', '最高指數', '最低指數', '收盤指數'],
                    'data' => [
                        ['115/03/31', '31,000.00', '31,200.00', '30,900.00', '31,100.00'],
                    ],
                ])
                ->push([
                    'stat' => 'OK',
                    'title' => '115年04月 發行量加權股價指數歷史資料',
                    'fields' => ['日期', '開盤指數', '最高指數', '最低指數', '收盤指數'],
                    'data' => [
                        ['115/04/30', '38,601.66', '39,222.19', '38,436.78', '38,926.63'],
                    ],
                ]),
            'https://www.taifex.com.tw/*' => Http::response($this->taifexHtml()),
        ]);

        $this->artisan('tw-stock:fetch-institutional-flows', [
            '--date' => '2026-04-30',
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $record = DB::table('tw_stock_institutional_flows')->first();

        $this->assertSame('2026-04-30 00:00:00', $record->trade_date);
        $this->assertSame(-53561725108, (int) $record->foreign_stock_net_amount);
        $this->assertSame(1183618636, (int) $record->investment_trust_stock_net_amount);
        $this->assertSame(3006, (int) $record->foreign_txf_trade_net_contracts);
        $this->assertSame(-44044, (int) $record->foreign_txf_open_interest_net_contracts);
        $this->assertSame(122, (int) $record->investment_trust_txf_trade_net_contracts);
        $this->assertSame(42317, (int) $record->investment_trust_txf_open_interest_net_contracts);
        $this->assertEqualsWithDelta(38926.63, (float) $record->taiex_close_index, 0.001);
    }

    public function test_dashboard_defaults_to_latest_sixty_trade_days_and_supports_day_filter(): void
    {
        $start = Carbon::parse('2026-01-01');

        for ($i = 0; $i < 65; $i++) {
            DB::table('tw_stock_institutional_flows')->insert([
                'trade_date' => $start->copy()->addDays($i)->toDateString(),
                'foreign_stock_net_amount' => ($i + 1) * 100_000_000,
                'investment_trust_stock_net_amount' => -($i + 1) * 10_000_000,
                'taiex_close_index' => 20000 + $i,
                'foreign_txf_open_interest_net_contracts' => -40000 - $i,
                'investment_trust_txf_open_interest_net_contracts' => 10000 + $i,
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->get(route('tw-stock.institutional-flows.index'))
            ->assertOk()
            ->assertSee('近 60 個交易日明細')
            ->assertSee('"windowSize":60', false)
            ->assertSee('"initialStartIndex":5', false)
            ->assertSee('"taiexClose"', false)
            ->assertSee('加權指數')
            ->assertSee('2026-03-06')
            ->assertSee('2026-01-06')
            ->assertDontSee('2026-01-05</td>', false);

        $this->get(route('tw-stock.institutional-flows.index', ['days' => 20]))
            ->assertOk()
            ->assertSee('近 20 個交易日明細');
    }

    private function createTable(): void
    {
        Schema::create('tw_stock_institutional_flows', function (Blueprint $table): void {
            $table->id();
            $table->date('trade_date')->unique();
            $table->unsignedBigInteger('foreign_stock_buy_amount')->nullable();
            $table->unsignedBigInteger('foreign_stock_sell_amount')->nullable();
            $table->bigInteger('foreign_stock_net_amount')->nullable();
            $table->unsignedBigInteger('investment_trust_stock_buy_amount')->nullable();
            $table->unsignedBigInteger('investment_trust_stock_sell_amount')->nullable();
            $table->bigInteger('investment_trust_stock_net_amount')->nullable();
            $table->integer('foreign_txf_trade_net_contracts')->nullable();
            $table->integer('investment_trust_txf_trade_net_contracts')->nullable();
            $table->unsignedInteger('foreign_txf_open_interest_long_contracts')->nullable();
            $table->unsignedInteger('foreign_txf_open_interest_short_contracts')->nullable();
            $table->integer('foreign_txf_open_interest_net_contracts')->nullable();
            $table->unsignedInteger('investment_trust_txf_open_interest_long_contracts')->nullable();
            $table->unsignedInteger('investment_trust_txf_open_interest_short_contracts')->nullable();
            $table->integer('investment_trust_txf_open_interest_net_contracts')->nullable();
            $table->decimal('taiex_open_index', 10, 2)->nullable();
            $table->decimal('taiex_high_index', 10, 2)->nullable();
            $table->decimal('taiex_low_index', 10, 2)->nullable();
            $table->decimal('taiex_close_index', 10, 2)->nullable();
            $table->string('taiex_source_title')->nullable();
            $table->json('taiex_payload')->nullable();
            $table->string('twse_source_title')->nullable();
            $table->json('twse_payload')->nullable();
            $table->json('taifex_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    private function taifexHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="zh-Hant">
<body>
<table>
    <tbody>
        <tr>
            <td rowspan="3">1</td>
            <td rowspan="3">臺股期貨</td>
            <td>自營商</td>
            <td>5,551</td><td>43,883,684</td>
            <td>5,850</td><td>46,269,952</td>
            <td>-299</td><td>-2,386,268</td>
            <td>4,708</td><td>37,094,282</td>
            <td>6,247</td><td>49,192,453</td>
            <td>-1,539</td><td>-12,098,171</td>
        </tr>
        <tr>
            <td>投信</td>
            <td>378</td><td>2,975,040</td>
            <td>256</td><td>2,014,710</td>
            <td>122</td><td>960,330</td>
            <td>42,823</td><td>337,175,112</td>
            <td>506</td><td>3,981,055</td>
            <td>42,317</td><td>332,967,083</td>
        </tr>
        <tr>
            <td>外資</td>
            <td>70,159</td><td>554,377,821</td>
            <td>67,153</td><td>530,626,691</td>
            <td>3,006</td><td>23,751,130</td>
            <td>19,569</td><td>154,006,546</td>
            <td>63,613</td><td>500,605,400</td>
            <td>-44,044</td><td>-346,598,854</td>
        </tr>
    </tbody>
</table>
</body>
</html>
HTML;
    }
}
