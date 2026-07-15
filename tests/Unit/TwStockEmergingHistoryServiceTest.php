<?php

namespace Tests\Unit;

use App\Services\TwStockEmergingHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwStockEmergingHistoryServiceTest extends TestCase
{
    public function test_it_builds_emerging_stock_returns_from_tpex_monthly_history(): void
    {
        Cache::flush();

        $date = CarbonImmutable::parse('2026-07-14', 'Asia/Taipei');
        $rows = [];
        for ($index = 0; $index < 65; $index++) {
            while ($date->isWeekend()) {
                $date = $date->subDay();
            }

            $rows[] = [
                ($date->year - 1911) . $date->format('/m/d'),
                '1,000',
                '1,000,000',
                '1,010.00',
                '990.00',
                number_format(1000 - $index, 2, '.', ','),
                '10',
            ];
            $date = $date->subDay();
        }

        Http::fake([
            'https://www.tpex.org.tw/www/zh-tw/emerging/historical' => Http::response([
                'stat' => 'ok',
                'tables' => [['data' => $rows]],
            ]),
        ]);

        $summary = app(TwStockEmergingHistoryService::class)->summary(
            '7861',
            '2026-07-15',
            'Asia/Taipei',
        );

        $this->assertNotNull($summary);
        $this->assertSame(1000.0, $summary['previousClose']);
        $this->assertSame('2026-07-14', $summary['previousCloseDate']);
        $this->assertEqualsWithDelta((1000 - 996) / 996 * 100, $summary['fiveDayReturn'], 0.000001);
        $this->assertEqualsWithDelta((1000 - 981) / 981 * 100, $summary['twentyDayReturn'], 0.000001);
        $this->assertEqualsWithDelta((1000 - 941) / 941 * 100, $summary['sixtyDayReturn'], 0.000001);
        $this->assertNull($summary['yearToDateReturn']);
        Http::assertSentCount(5);
    }
}
