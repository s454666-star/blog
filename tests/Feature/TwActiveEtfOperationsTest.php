<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwActiveEtfOperationsTest extends TestCase
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
        $this->createTables();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('tw_stock_daily_prices');
        Schema::dropIfExists('tw_active_etf_operation_items');
        Schema::dropIfExists('tw_active_etf_operation_reports');
        Schema::dropIfExists('tw_active_etfs');

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_fetch_command_stores_active_etf_operation_reports(): void
    {
        Carbon::setTestNow('2026-07-01 12:00:00');

        Http::fake([
            'https://www.twse.com.tw/rwd/zh/ETF/activeList*' => Http::response([
                'status' => 'ok',
                'fields' => ['ETF代號', 'ETF名稱', '管理方式', 'ETF類型'],
                'data' => [
                    ['00403A', '主動統一升級50', '主動式', '股票型'],
                    ['00981A', '主動統一台股增', '主動式', '股票型'],
                ],
            ]),
            'https://www.tpex.org.tw/www/zh-tw/ETF/list' => function ($request) {
                $type = (string) (($request->data())['type'] ?? '');

                return Http::response([
                    'stat' => 'ok',
                    'tables' => [[
                        'fields' => ['證券代號', 'ETF簡稱', '上櫃日期'],
                        'data' => match ($type) {
                            'foreign' => [['00998A', '主動復華金融股息', '115/04/15']],
                            'bond' => [['00981D', '主動中信非投等債', '114/09/16']],
                            default => [],
                        },
                    ]],
                ]);
            },
            'https://www.twse.com.tw/exchangeReport/STOCK_DAY_ALL*' => Http::response([
                'date' => '20260701',
                'data' => [
                    ['00403A', '主動統一升級50', '381,613,000', '4,270,000,000', '11.24', '11.29', '11.06', '11.15', '0.13', '46,057'],
                    ['00981A', '主動統一台股增', '307,148,000', '9,840,000,000', '32.07', '32.23', '31.55', '32.00', '0.72', '58,038'],
                ],
            ]),
            'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes' => Http::response([
                [
                    'SecuritiesCompanyCode' => '00998A',
                    'CompanyName' => '主動復華金融股息',
                    'Date' => '1150701',
                    'Close' => '16.58',
                    'Change' => '0.00',
                    'TradingShares' => '2537000',
                    'TransactionAmount' => '42000000',
                    'TransactionNumber' => '797',
                ],
                [
                    'SecuritiesCompanyCode' => '00981D',
                    'CompanyName' => '主動中信非投等債',
                    'Date' => '1150701',
                    'Close' => '10.40',
                    'Change' => '-0.02',
                    'TradingShares' => '2547000',
                    'TransactionAmount' => '26000000',
                    'TransactionNumber' => '230',
                ],
            ]),
            'https://mis.twse.com.tw/stock/api/getStockInfo.jsp*' => Http::response([
                'rtcode' => '0000',
                'msgArray' => [
                    [
                        'c' => '00403A',
                        'n' => '主動統一升級50',
                        'ex' => 'tse',
                        'd' => '20260701',
                        'z' => '11.15',
                        'y' => '11.02',
                        'v' => '381613',
                    ],
                    [
                        'c' => '00981A',
                        'n' => '主動統一台股增長',
                        'ex' => 'tse',
                        'd' => '20260701',
                        'z' => '32.00',
                        'y' => '31.28',
                        'v' => '307148',
                    ],
                    [
                        'c' => '00998A',
                        'n' => '主動復華金融股息',
                        'ex' => 'otc',
                        'd' => '20260701',
                        'z' => '16.58',
                        'y' => '16.58',
                        'v' => '2537',
                    ],
                    [
                        'c' => '00981D',
                        'n' => '主動中信非投等債',
                        'ex' => 'otc',
                        'd' => '20260701',
                        'z' => '10.40',
                        'y' => '10.42',
                        'v' => '2547',
                    ],
                ],
            ]),
            'https://www.cmoney.tw/forum/stock/00403A*' => Http::response('<html><script>tokens:{at:"guest-token"}</script></html>'),
            'https://customreport.cmoney.tw/app/v2/dtno/JsonCsv' => Http::response([
                'columns' => ['日期', '變動狀態', '變動標的代號', '變動標的名稱', '持股變動數', '持股變動數(張)', '標籤'],
                'rows' => [
                    ['20260630', '變更', '2368', '金像電', -150000, -150, '減碼'],
                    ['20260630', '新增', '6239', '力成', 500000, 500, '新增'],
                    ['20260630', '變更', '2330', '台積電', 0, 0, ''],
                    ['20260629', '刪除', '3105', '穩懋', -1000, -1, '刪除'],
                ],
            ]),
        ]);

        $this->artisan('tw-stock:fetch-active-etf-operations', [
            '--codes' => ['00403A'],
            '--from' => '2026-06-01',
            '--to' => '2026-06-30',
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('tw_active_etfs', [
            'stock_code' => '00403A',
            'stock_name' => '主動統一升級50',
            'is_active' => 1,
        ]);
        $this->assertDatabaseHas('tw_active_etfs', [
            'stock_code' => '00998A',
            'exchange' => 'TPEx',
            'stock_name' => '主動復華金融股息',
            'is_active' => 1,
        ]);
        $quote = DB::table('tw_active_etfs')->where('stock_code', '00403A')->first();
        $this->assertNotNull($quote);
        $this->assertSame('TWSE', $quote->exchange);
        $this->assertEquals(11.15, (float) $quote->close_price);
        $this->assertEquals(0.13, (float) $quote->price_change_amount);
        $this->assertEquals(381613, (int) $quote->volume_lots);
        $this->assertDatabaseCount('tw_active_etf_operation_reports', 2);
        $this->assertDatabaseCount('tw_active_etf_operation_items', 3);
        $this->assertDatabaseHas('tw_active_etf_operation_reports', [
            'etf_code' => '00403A',
            'operation_date' => '2026-06-30 00:00:00',
            'source_row_count' => 3,
            'changed_row_count' => 2,
        ]);
        $this->assertDatabaseHas('tw_active_etf_operation_items', [
            'etf_code' => '00403A',
            'operation_date' => '2026-06-30 00:00:00',
            'stock_code' => '6239',
            'stock_name' => '力成',
            'action' => 'new',
            'action_label' => '新增',
        ]);
        $this->assertDatabaseHas('tw_active_etf_operation_items', [
            'stock_code' => '2368',
            'action' => 'reduce',
            'change_shares' => -150000,
        ]);
    }

    public function test_page_defaults_to_yesterday_and_supports_action_filter(): void
    {
        Carbon::setTestNow('2026-07-01 12:00:00');
        $now = now();

        DB::table('tw_active_etfs')->insert([
            [
                'stock_code' => '00403A',
                'stock_name' => '主動統一升級50',
                'exchange' => 'TWSE',
                'management_type' => '主動式',
                'etf_category' => '股票型',
                'is_active' => true,
                'fetched_at' => $now,
                'quote_date' => '2026-07-01',
                'close_price' => 11.15,
                'previous_close_price' => 11.02,
                'price_change_amount' => 0.13,
                'price_change_percent' => 1.1797,
                'volume_lots' => 381613,
                'volume_shares' => 381613000,
                'trade_value' => 4270000000,
                'quote_source' => 'TWSE MIS stockInfo',
                'quote_fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'stock_code' => '00981A',
                'stock_name' => '主動統一台股增',
                'exchange' => 'TWSE',
                'management_type' => '主動式',
                'etf_category' => '股票型',
                'is_active' => true,
                'fetched_at' => $now,
                'quote_date' => '2026-07-01',
                'close_price' => 32.00,
                'previous_close_price' => 31.28,
                'price_change_amount' => 0.72,
                'price_change_percent' => 2.3018,
                'volume_lots' => 307148,
                'volume_shares' => 307148000,
                'trade_value' => 9840000000,
                'quote_source' => 'TWSE MIS stockInfo',
                'quote_fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $reportId = DB::table('tw_active_etf_operation_reports')->insertGetId([
            'etf_code' => '00403A',
            'etf_name' => '主動統一升級50',
            'operation_date' => '2026-06-30',
            'source_kind' => 'cmoney_dtno',
            'source_url' => 'https://www.cmoney.tw/forum/stock/00403A',
            'source_row_count' => 2,
            'changed_row_count' => 2,
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $secondReportId = DB::table('tw_active_etf_operation_reports')->insertGetId([
            'etf_code' => '00981A',
            'etf_name' => '主動統一台股增',
            'operation_date' => '2026-06-30',
            'source_kind' => 'cmoney_dtno',
            'source_url' => 'https://www.cmoney.tw/forum/stock/00981A',
            'source_row_count' => 1,
            'changed_row_count' => 1,
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('tw_active_etf_operation_items')->insert([
            $this->itemRow($reportId, '00403A', '主動統一升級50', '2026-06-30', '6239', '力成', 'new', '新增', 500),
            $this->itemRow($reportId, '00403A', '主動統一升級50', '2026-06-30', '6147', '頎邦', 'reduce', '減碼', -2500),
            $this->itemRow($secondReportId, '00981A', '主動統一台股增', '2026-06-30', '5274', '信驊', 'add', '加碼', 12),
            $this->itemRow($secondReportId, '00981A', '主動統一台股增', '2026-06-30', '2330', '台積電', 'reduce', '減碼', -10),
        ]);
        DB::table('tw_stock_daily_prices')->insert([
            $this->dailyPriceRow('6147', '頎邦', '2026-06-30', 50.00),
            $this->dailyPriceRow('6239', '力成', '2026-06-30', 120.00),
            $this->dailyPriceRow('5274', '信驊', '2026-06-30', 5000.00),
            $this->dailyPriceRow('2330', '台積電', '2026-06-30', 1000.00),
        ]);

        $this->get(route('tw-stock.active-etf-operations.index'))
            ->assertOk()
            ->assertSee('主動式 ETF 操作日報')
            ->assertSee('value="2026-06-30"', false)
            ->assertSee('00403A')
            ->assertSee('11.15')
            ->assertSee('+0.13')
            ->assertSee('+1.18%')
            ->assertSee('42.7億')
            ->assertSee('market_sort=price', false)
            ->assertSee('detail_sort=amount', false)
            ->assertSee('總金額')
            ->assertSee('1.3億')
            ->assertSee('-2,500 張')
            ->assertSeeInOrder(['頎邦', '力成', '信驊', '台積電'])
            ->assertSee('力成')
            ->assertSee('頎邦')
            ->assertSee('信驊')
            ->assertSee('desktop-ledger', false)
            ->assertSee('mobile-operations', false)
            ->assertDontSee('來源')
            ->assertDontSee('CMoney');

        $this->get(route('tw-stock.active-etf-operations.index', [
            'from' => '2026-06-30',
            'to' => '2026-06-30',
            'action' => 'reduce',
        ]))
            ->assertOk()
            ->assertSeeInOrder(['頎邦', '台積電'])
            ->assertSee('頎邦')
            ->assertDontSee('力成</strong>', false);

        $this->get(route('tw-stock.active-etf-operations.index', [
            'from' => '2026-06-30',
            'to' => '2026-06-30',
            'etf' => '00403A',
        ]))
            ->assertOk()
            ->assertSee('aria-current="true"', false)
            ->assertSee('頎邦')
            ->assertDontSee('信驊</strong>', false);
    }

    private function createTables(): void
    {
        Schema::create('tw_active_etfs', function (Blueprint $table): void {
            $table->id();
            $table->string('stock_code', 12)->unique();
            $table->string('stock_name');
            $table->string('exchange', 16)->nullable();
            $table->string('management_type')->nullable();
            $table->string('etf_category', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->date('quote_date')->nullable();
            $table->decimal('close_price', 12, 4)->nullable();
            $table->decimal('previous_close_price', 12, 4)->nullable();
            $table->decimal('price_change_amount', 12, 4)->nullable();
            $table->decimal('price_change_percent', 8, 4)->nullable();
            $table->unsignedBigInteger('volume_lots')->nullable();
            $table->unsignedBigInteger('volume_shares')->nullable();
            $table->unsignedBigInteger('trade_value')->nullable();
            $table->unsignedInteger('transaction_count')->nullable();
            $table->string('quote_source')->nullable();
            $table->json('quote_payload')->nullable();
            $table->timestamp('quote_fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tw_active_etf_operation_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('etf_code', 12);
            $table->string('etf_name');
            $table->date('operation_date');
            $table->string('source_kind', 48)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->unsignedInteger('source_row_count')->default(0);
            $table->unsignedInteger('changed_row_count')->default(0);
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['etf_code', 'operation_date']);
        });

        Schema::create('tw_active_etf_operation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')
                ->constrained('tw_active_etf_operation_reports')
                ->cascadeOnDelete();
            $table->string('etf_code', 12);
            $table->string('etf_name');
            $table->date('operation_date');
            $table->string('stock_code', 16);
            $table->string('stock_name');
            $table->string('action', 16);
            $table->string('action_label', 16);
            $table->bigInteger('change_shares')->nullable();
            $table->decimal('change_lots', 14, 3)->nullable();
            $table->string('source_status', 32)->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['report_id', 'stock_code', 'action']);
        });

        Schema::create('tw_stock_daily_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->date('trade_date');
            $table->decimal('open_price', 12, 4)->nullable();
            $table->decimal('high_price', 12, 4)->nullable();
            $table->decimal('low_price', 12, 4)->nullable();
            $table->decimal('close_price', 12, 4);
            $table->decimal('previous_close_price', 12, 4)->nullable();
            $table->decimal('price_change_amount', 12, 4)->nullable();
            $table->decimal('price_change_percent', 10, 4)->nullable();
            $table->unsignedBigInteger('volume_lots')->default(0);
            $table->unsignedBigInteger('volume_shares')->default(0);
            $table->unsignedBigInteger('trade_value')->nullable();
            $table->unsignedInteger('transaction_count')->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(
        int $reportId,
        string $etfCode,
        string $etfName,
        string $date,
        string $stockCode,
        string $stockName,
        string $action,
        string $label,
        int $lots,
    ): array {
        return [
            'report_id' => $reportId,
            'etf_code' => $etfCode,
            'etf_name' => $etfName,
            'operation_date' => $date,
            'stock_code' => $stockCode,
            'stock_name' => $stockName,
            'action' => $action,
            'action_label' => $label,
            'change_shares' => $lots * 1000,
            'change_lots' => $lots,
            'source_status' => $label,
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyPriceRow(string $stockCode, string $stockName, string $date, float $closePrice): array
    {
        return [
            'exchange' => 'TWSE',
            'stock_code' => $stockCode,
            'stock_name' => $stockName,
            'trade_date' => $date,
            'open_price' => $closePrice,
            'high_price' => $closePrice,
            'low_price' => $closePrice,
            'close_price' => $closePrice,
            'previous_close_price' => $closePrice,
            'price_change_amount' => 0,
            'price_change_percent' => 0,
            'volume_lots' => 1000,
            'volume_shares' => 1000000,
            'trade_value' => (int) round($closePrice * 1000000),
            'source' => 'test',
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
