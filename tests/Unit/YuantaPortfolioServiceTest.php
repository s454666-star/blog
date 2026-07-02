<?php

namespace Tests\Unit;

use App\Services\YuantaPortfolioService;
use Carbon\CarbonImmutable;
use ReflectionMethod;
use Tests\TestCase;

class YuantaPortfolioServiceTest extends TestCase
{
    public function test_it_summarizes_margin_usage_from_yuanta_loan_fields(): void
    {
        config()->set('yuanta.margin_limit_amount', 1000000);

        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'marginSummary');

        $summary = $method->invoke($service, [
            [
                'tradeType' => '3',
                'marketValue' => 101000,
                'raw' => ['loan' => 60000],
            ],
            [
                'tradeType' => '3',
                'marketValue' => 38430,
                'raw' => ['loan' => 23000],
            ],
            [
                'tradeType' => '3',
                'marketValue' => 305250,
                'raw' => ['loan' => 179000],
            ],
            [
                'tradeType' => '3',
                'marketValue' => 563400,
                'raw' => ['loan' => 334000],
            ],
            [
                'tradeType' => '0',
                'marketValue' => 153750,
                'raw' => ['loan' => 0],
            ],
        ], [
            'balance' => [
                ['AvailableBalance' => 544980],
            ],
        ]);

        $this->assertSame(1000000.0, $summary['limitAmount']);
        $this->assertSame(596000.0, $summary['usedAmount']);
        $this->assertSame(404000.0, $summary['availableAmount']);
        $this->assertEqualsWithDelta(169.14, $summary['maintenanceRate'], 0.01);
    }

    public function test_it_does_not_treat_bank_balance_as_margin_available_amount(): void
    {
        config()->set('yuanta.margin_limit_amount', null);

        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'marginSummary');

        $summary = $method->invoke($service, [
            [
                'tradeType' => '3',
                'marketValue' => 305250,
                'raw' => ['loan' => 179000],
            ],
        ], [
            'balance' => [
                ['AvailableBalance' => 544980],
            ],
        ]);

        $this->assertNull($summary['limitAmount']);
        $this->assertSame(179000.0, $summary['usedAmount']);
        $this->assertNull($summary['availableAmount']);
    }

    public function test_it_subtracts_future_settlements_from_yuanta_available_balance(): void
    {
        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'balanceSummary');

        $summary = $method->invoke($service, [
            'balance' => [
                ['AvailableBalance' => 48971],
            ],
            'settlements' => [
                ['SettlementDay' => '2026/07/02', 'SettlementAmt' => -466131],
                ['SettlementDay' => '2026/07/03', 'SettlementAmt' => -41624],
                ['SettlementDay' => '2026/07/06', 'SettlementAmt' => -6485],
            ],
        ], 1590986.0, CarbonImmutable::parse('2026-07-02 09:45:00', 'Asia/Taipei'));

        $this->assertSame(48971.0, $summary['availableBalance']);
        $this->assertSame(48109.0, $summary['pendingSettlementAmount']);
        $this->assertSame(862.0, $summary['bankBalance']);
        $this->assertEqualsWithDelta(1590986 / (1590986 + 862) * 100, $summary['investmentLevelRate'], 0.000001);
    }
}
