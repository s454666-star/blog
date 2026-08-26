<?php

namespace Tests\Unit;

use App\Services\TwStockEpsGrowthScoringService;
use PHPUnit\Framework\TestCase;

class TwStockEpsGrowthScoringServiceTest extends TestCase
{
    public function test_weights_apply_to_raw_growth_before_percentile_ranking(): void
    {
        $rows = (new TwStockEpsGrowthScoringService())->scoreAndRank([
            [
                'stock_code' => '4958',
                'growth_2025_2026' => 102.7656,
                'growth_2026_2027' => 79.2534,
                'growth_2027_2028' => 31.4778,
            ],
            [
                'stock_code' => '6213',
                'growth_2025_2026' => 236.7788,
                'growth_2026_2027' => 87.2234,
                'growth_2027_2028' => 11.9329,
            ],
        ]);

        $this->assertSame('6213', $rows[0]['stock_code']);
        $this->assertSame(1, $rows[0]['rank']);
        $this->assertSame(100.0, $rows[0]['weighted_score']);
        $this->assertSame('4958', $rows[1]['stock_code']);
        $this->assertSame(0.0, $rows[1]['weighted_score']);
    }
}
