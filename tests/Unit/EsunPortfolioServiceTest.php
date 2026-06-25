<?php

namespace Tests\Unit;

use App\Services\EsunPortfolioService;
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
        $this->assertSame(43057.0, $row['costBasis']);
        $this->assertSame(-43057.0, $row['signedCostBasis']);
        $this->assertSame(3466.0, $row['unrealizedPnl']);
        $this->assertEqualsWithDelta(8.05, $row['unrealizedPnlRate'], 0.01);
        $this->assertSame('3', $row['tradeType']);
        $this->assertSame('融資', $row['tradeTypeLabel']);
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
}
