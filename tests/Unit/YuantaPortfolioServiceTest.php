<?php

namespace Tests\Unit;

use App\Services\YuantaPortfolioService;
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
}
