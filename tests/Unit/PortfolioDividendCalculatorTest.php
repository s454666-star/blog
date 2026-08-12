<?php

namespace Tests\Unit;

use App\Services\PortfolioDividendCalculator;
use PHPUnit\Framework\TestCase;

class PortfolioDividendCalculatorTest extends TestCase
{
    public function test_esun_reverses_post_ex_date_trades_from_current_inventory(): void
    {
        $result = (new PortfolioDividendCalculator)->esunEligibleQuantity('2330', '2026-06-10', [
            ['stk_no' => '2330', 'cost_qty' => 1000, 'trade' => '0'],
        ], [
            ['stk_no' => '2330', 't_date' => '2026/06/10', 'buy_sell' => 'B', 'qty' => 500, 'trade' => '0'],
            ['stk_no' => '2330', 't_date' => '2026/07/01', 'buy_sell' => 'S', 'qty' => 300, 'trade' => '0'],
        ]);

        $this->assertSame(800.0, $result['quantity']);
    }

    public function test_yuanta_combines_pre_ex_date_open_and_realized_lots(): void
    {
        $result = (new PortfolioDividendCalculator)->yuantaEligibleQuantity('2330', '2026-06-10', [
            ['StkCode' => '2330', 'TradeDate' => '2026/01/02', 'StockQty' => 700, 'TradeKind' => '0'],
            ['StkCode' => '2330', 'TradeDate' => '2026/06/10', 'StockQty' => 500, 'TradeKind' => '0'],
        ], [
            [
                'StkCode' => '2330',
                'TradeDate' => '2026/07/01',
                'TradeKind' => '0',
                'ReversalReports' => [
                    ['ReversalDate' => '2026/02/01', 'ReversalQty' => 300],
                    ['ReversalDate' => '2026/06/10', 'ReversalQty' => 100],
                ],
            ],
        ]);

        $this->assertSame(1000.0, $result['quantity']);
    }
}
