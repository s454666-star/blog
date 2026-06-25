<?php

namespace Tests\Unit;

use App\Services\EsunPortfolioService;
use Carbon\CarbonImmutable;
use ReflectionMethod;
use Tests\TestCase;

class EsunPortfolioServiceTest extends TestCase
{
    public function test_it_uses_esun_market_price_and_direct_cost_for_margin_inventory(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $row = $method->invoke($service, [
            'cost_qty' => '1000',
            'cost_sum' => '-43057',
            'make_a_per' => '8.05',
            'make_a_sum' => '3466',
            'price_avg' => '106.00',
            'price_evn' => '106.44',
            'price_mkt' => '110.00',
            'price_now' => '108.50',
            'price_qty_sum' => '106000',
            'qty_b' => '1000',
            'qty_s' => '0',
            'stk_dats' => [],
            'stk_na' => '界霖',
            'stk_no' => '5285',
            's_type' => 'H',
            'trade' => '3',
            'value_mkt' => '110000',
            'value_now' => '108500',
        ], ['previousClose' => null]);

        $this->assertSame(110.0, $row['currentPrice']);
        $this->assertSame(110000.0, $row['marketValue']);
        $this->assertSame(110.0, $row['esunCurrentPrice']);
        $this->assertSame(110000.0, $row['esunMarketValue']);
        $this->assertSame(43057.0, $row['costBasis']);
        $this->assertSame(-43057.0, $row['signedCostBasis']);
        $this->assertSame(3466.0, $row['unrealizedPnl']);
        $this->assertSame(3466.0, $row['esunUnrealizedPnl']);
        $this->assertSame(110.0, $row['realtimePnlBasePrice']);
        $this->assertEqualsWithDelta(8.05, $row['unrealizedPnlRate'], 0.01);
        $this->assertSame('3', $row['tradeType']);
        $this->assertSame('融資', $row['tradeTypeLabel']);
    }

    public function test_it_sets_esun_today_pnl_from_esun_price_and_previous_close(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $row = $method->invoke($service, [
            'cost_qty' => '1000',
            'cost_sum' => '-100000',
            'make_a_per' => '10.00',
            'make_a_sum' => '10000',
            'price_avg' => '100.00',
            'price_evn' => '100.00',
            'price_mkt' => '110.00',
            'price_qty_sum' => '100000',
            'qty_b' => '1000',
            'qty_s' => '0',
            'stk_dats' => [],
            'stk_na' => '測試股',
            'stk_no' => '9999',
            's_type' => 'H',
            'trade' => '0',
            'value_mkt' => '110000',
        ], ['previousClose' => 100.0]);

        $this->assertSame(10000.0, $row['todayPnl']);
        $this->assertSame(10000.0, $row['esunTodayPnl']);
        $this->assertSame(10.0, $row['dayChange']);
        $this->assertEqualsWithDelta(10.0, $row['dayChangeRate'], 0.0001);
    }

    public function test_it_maps_cash_inventory_from_trade_type_even_when_position_type_is_h(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $row = $method->invoke($service, [
            'cost_qty' => '1000',
            'cost_sum' => '-264142',
            'make_a_per' => '-1.39',
            'make_a_sum' => '-3674',
            'price_avg' => '264.00',
            'price_evn' => '264.69',
            'price_mkt' => '261.00',
            'price_qty_sum' => '264000',
            'qty_b' => '1000',
            'qty_s' => '0',
            'stk_dats' => [],
            'stk_na' => '同欣電',
            'stk_no' => '6271',
            's_type' => 'H',
            'trade' => '0',
            'value_mkt' => '261000',
        ], ['previousClose' => null]);

        $this->assertSame('H', $row['positionType']);
        $this->assertSame('0', $row['tradeType']);
        $this->assertSame('現股', $row['tradeTypeLabel']);
    }

    public function test_it_includes_exchange_badge_metadata(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $row = $method->invoke($service, [
            'cost_qty' => '1000',
            'cost_sum' => '-200000',
            'make_a_per' => '0',
            'make_a_sum' => '0',
            'price_avg' => '200.00',
            'price_evn' => '200.00',
            'price_mkt' => '200.00',
            'price_qty_sum' => '200000',
            'qty_b' => '1000',
            'qty_s' => '0',
            'stk_dats' => [],
            'stk_na' => '測試櫃',
            'stk_no' => '9999',
            's_type' => 'H',
            'trade' => '3',
            'value_mkt' => '200000',
        ], ['previousClose' => null], [
            'exchange' => 'TPEx',
            'label' => '上櫃',
            'shortLabel' => '櫃',
            'class' => 'tpex',
        ]);

        $this->assertSame('TPEx', $row['exchange']);
        $this->assertSame('上櫃', $row['exchangeLabel']);
        $this->assertSame('櫃', $row['exchangeShortLabel']);
        $this->assertSame('tpex', $row['exchangeClass']);
    }

    public function test_it_calculates_investment_level_from_bank_balance(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'balanceSummary');

        $summary = $method->invoke($service, [
            'balance' => [
                'available_balance' => '500000',
                'exchange_balance' => '10000',
            ],
            'settlements' => [
                ['c_date' => '20260624', 'price' => '-90000'],
                ['c_date' => '20260625', 'price' => '70000'],
                ['c_date' => '20260626', 'price' => '-50000'],
                ['c_date' => '20260629', 'price' => '30000'],
            ],
        ], 500000.0, CarbonImmutable::parse('2026-06-25 10:00:00', 'Asia/Taipei'));

        $this->assertSame(500000.0, $summary['availableBalance']);
        $this->assertSame(10000.0, $summary['dayTradeOffsetAmount']);
        $this->assertSame(80000.0, $summary['pendingSettlementAmount']);
        $this->assertSame(410000.0, $summary['bankBalance']);
        $this->assertEqualsWithDelta(54.945054945, $summary['investmentLevelRate'], 0.000001);
    }

    public function test_it_calculates_year_profit_without_day_trade_offset_profit(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'yearProfitSummary');

        $summary = $method->invoke($service, [
            'transactions' => [
                ['make' => '100000', 'trade' => '3'],
                ['make' => '-15000', 'trade' => 'A'],
                ['make' => '5000', 'trade' => '9'],
                ['make' => '-12000', 'trade' => '0'],
                ['make' => '0', 'trade' => '3'],
            ],
        ]);

        $this->assertSame(78000.0, $summary['realizedYearPnl']);
        $this->assertSame(-10000.0, $summary['dayTradeYearPnl']);
        $this->assertSame(88000.0, $summary['adjustedRealizedYearPnl']);
        $this->assertSame(88000.0, $summary['yearTotalPnl']);
    }

    public function test_it_calculates_year_return_rate_from_current_capital_minus_profit(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'buildSnapshot');

        $snapshot = $method->invoke($service, [
            'queriedAt' => '2026-06-25T10:00:00+08:00',
            'inventories' => [],
            'balance' => ['available_balance' => '1000000'],
            'settlements' => [],
            'transactions' => [
                ['make' => '200000', 'trade' => '0'],
            ],
        ], [
            'isOpen' => false,
            'label' => '非交易時段',
        ], CarbonImmutable::parse('2026-06-25 10:00:00', 'Asia/Taipei'), 60);

        $this->assertSame(200000.0, $snapshot['summary']['yearTotalPnl']);
        $this->assertSame(800000.0, $snapshot['summary']['yearReturnBase']);
        $this->assertSame(25.0, $snapshot['summary']['yearTotalPnlRate']);
        $this->assertSame(176, $snapshot['summary']['yearElapsedDays']);
        $this->assertEqualsWithDelta(25.0 * 365 / 176, $snapshot['summary']['annualizedReturnRate'], 0.000001);
    }
}
