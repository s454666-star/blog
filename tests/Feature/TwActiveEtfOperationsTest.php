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
            'stock_code' => '00403A',
            'stock_name' => '主動統一升級50',
            'management_type' => '主動式',
            'etf_category' => '股票型',
            'is_active' => true,
            'fetched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
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

        DB::table('tw_active_etf_operation_items')->insert([
            $this->itemRow($reportId, '00403A', '主動統一升級50', '2026-06-30', '6239', '力成', 'new', '新增', 500),
            $this->itemRow($reportId, '00403A', '主動統一升級50', '2026-06-30', '6147', '頎邦', 'reduce', '減碼', -2500),
        ]);

        $this->get(route('tw-stock.active-etf-operations.index'))
            ->assertOk()
            ->assertSee('主動式 ETF 操作日報')
            ->assertSee('value="2026-06-30"', false)
            ->assertSee('00403A')
            ->assertSee('力成')
            ->assertSee('頎邦')
            ->assertSee('desktop-ledger', false)
            ->assertSee('mobile-operations', false);

        $this->get(route('tw-stock.active-etf-operations.index', [
            'from' => '2026-06-30',
            'to' => '2026-06-30',
            'action' => 'reduce',
        ]))
            ->assertOk()
            ->assertSee('頎邦')
            ->assertDontSee('力成</strong>', false);
    }

    private function createTables(): void
    {
        Schema::create('tw_active_etfs', function (Blueprint $table): void {
            $table->id();
            $table->string('stock_code', 12)->unique();
            $table->string('stock_name');
            $table->string('management_type')->nullable();
            $table->string('etf_category', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
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
}
