<?php

namespace Tests\Unit;

use App\Services\YuantaPortfolioService;
use ReflectionMethod;
use Tests\TestCase;

class YuantaPortfolioServiceTest extends TestCase
{
    public function test_it_summarizes_margin_usage_from_yuanta_loan_fields(): void
    {
        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'marginSummary');

        $summary = $method->invoke($service, [
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

        $this->assertSame(1057980.0, $summary['limitAmount']);
        $this->assertSame(513000.0, $summary['usedAmount']);
        $this->assertSame(544980.0, $summary['availableAmount']);
        $this->assertEqualsWithDelta(169.327, $summary['maintenanceRate'], 0.001);
    }
}
