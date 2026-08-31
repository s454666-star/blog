<?php

namespace Tests\Unit;

use App\Models\TwStockDailyPrice;
use App\Services\EsunPortfolioService;
use App\Services\TwStockPriceAdjustmentService;
use App\Services\YuantaPortfolioService;
use ReflectionMethod;
use Tests\TestCase;

class TwStockPriceAdjustmentServiceTest extends TestCase
{
    public function test_conversion_only_applies_to_old_prices_after_the_effective_date(): void
    {
        $service = new TwStockPriceAdjustmentService();

        $this->assertEqualsWithDelta(93.093, $service->historicalPrice('6696', '2026-08-19', '2026-08-31', 930.93), 0.000001);
        $this->assertSame(930.93, $service->historicalPrice('6696', '2026-08-19', '2026-08-28', 930.93));
        $this->assertSame(100.5, $service->historicalPrice('6696', '2026-08-31', '2026-09-01', 100.5));
        $this->assertSame(930.93, $service->historicalPrice('3081', '2026-08-19', '2026-08-31', 930.93));
    }

    public function test_both_brokers_adjust_raw_history_without_adjusting_yahoo_history_twice(): void
    {
        $raw = collect([new TwStockDailyPrice([
            'stock_code' => '6696', 'trade_date' => '2026-08-19', 'close_price' => 930.93,
        ])]);
        $adjustedYahoo = collect([['tradeDate' => '2026-08-19', 'closePrice' => 93.093]]);

        foreach ([new YuantaPortfolioService(), new EsunPortfolioService()] as $service) {
            $method = new ReflectionMethod($service, 'historicalPriceSummary');
            foreach ([$raw, $adjustedYahoo] as $prices) {
                $summary = $method->invoke($service, $prices, '2026-08-31', '2026-01-01');
                $this->assertEqualsWithDelta(93.093, $summary['previousClose'], 0.000001);
            }
            $oldSummary = $method->invoke($service, $raw, '2026-08-28', '2026-01-01');
            $this->assertEqualsWithDelta(930.93, $oldSummary['previousClose'], 0.000001);
        }
        $this->assertEqualsWithDelta(930.93, $raw[0]->close_price, 0.000001);
    }

    public function test_converted_yuanta_holding_has_correct_today_pnl_and_unchanged_broker_valuation(): void
    {
        $previousClose = (new TwStockPriceAdjustmentService())->historicalPrice('6696', '2026-08-19', '2026-08-31', 930.93);
        $service = new YuantaPortfolioService();
        $row = (new ReflectionMethod($service, 'formatInventoryRow'))->invoke($service, [
            'StkCode' => '6696', 'StkName' => '仁新*', 'TradeKind' => 0,
            'StockQty' => 600, 'MarketPrice' => 100.5, 'MarketAmt' => 60300,
            'ReturnAmt' => 15496, 'Cost' => 44539, 'Price' => 74.2317,
        ], ['previousClose' => $previousClose]);

        $this->assertEqualsWithDelta(4444.2, $row['todayPnl'], 0.000001);
        $this->assertEqualsWithDelta(7.95656, $row['dayChangeRate'], 0.00001);
        $this->assertSame($row['todayPnl'], $row['esunTodayPnl']);
        $this->assertSame(600.0, $row['quantity']);
        $this->assertSame(60300.0, $row['marketValue']);
        $this->assertSame(44539.0, $row['costBasis']);
        $this->assertSame(15496.0, $row['unrealizedPnl']);
        $this->assertFalse($row['inventoryPriceFallback']);
    }
}
