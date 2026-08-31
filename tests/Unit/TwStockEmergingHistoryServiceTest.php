<?php

namespace Tests\Unit;

use App\Services\TwStockEmergingHistoryService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwStockEmergingHistoryServiceTest extends TestCase
{
    public function test_share_conversion_adjusts_prior_average_and_cross_date_returns(): void
    {
        Cache::flush();
        $rows = [
            ['115/08/31', '0', '0', '0', '0', '100.50'],
            ['115/08/19', '0', '0', '0', '0', '930.93'],
            ['115/08/18', '0', '0', '0', '0', '907.17'],
            ['115/08/17', '0', '0', '0', '0', '945.37'],
            ['115/08/14', '0', '0', '0', '0', '876.77'],
            ['115/08/13', '0', '0', '0', '0', '841.00'],
        ];
        Http::fake([
            'https://www.tpex.org.tw/www/zh-tw/emerging/historical' => Http::response([
                'stat' => 'ok', 'tables' => [['subtitle' => '115年08月 6696 仁新*', 'data' => $rows]],
            ]),
        ]);
        // The old cache must not keep serving the unadjusted comparison price.
        Cache::put('tw-stock:emerging-history:2026-08-31:6696:v2', ['previousClose' => 930.93], 600);
        $service = app(TwStockEmergingHistoryService::class);
        $before = $service->summary('6696', '2026-08-28');
        $reopening = $service->summary('6696', '2026-08-31');
        $nextDay = $service->summary('6696', '2026-09-01');

        $this->assertSame(930.93, $before['previousClose']);
        $this->assertEqualsWithDelta(93.093, $reopening['previousClose'], 0.000001);
        $this->assertSame('2026-08-19', $reopening['previousCloseDate']);
        $this->assertEqualsWithDelta($before['fiveDayReturn'], $reopening['fiveDayReturn'], 0.000001);
        $this->assertSame(100.5, $nextDay['previousClose']);
        $this->assertSame('2026-08-31', $nextDay['previousCloseDate']);
        $this->assertEqualsWithDelta((100.5 / 87.677 - 1) * 100, $nextDay['fiveDayReturn'], 0.000001);
    }

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
                'tables' => [[
                    'subtitle' => '115年07月 7861 逸達生技',
                    'data' => $rows,
                ]],
            ]),
        ]);

        $summary = app(TwStockEmergingHistoryService::class)->summary(
            '7861',
            '2026-07-15',
            'Asia/Taipei',
        );

        $this->assertNotNull($summary);
        $this->assertSame('逸達生技', $summary['stockName']);
        $this->assertSame(1000.0, $summary['previousClose']);
        $this->assertSame('2026-07-14', $summary['previousCloseDate']);
        $this->assertEqualsWithDelta((1000 - 996) / 996 * 100, $summary['fiveDayReturn'], 0.000001);
        $this->assertEqualsWithDelta((1000 - 981) / 981 * 100, $summary['twentyDayReturn'], 0.000001);
        $this->assertEqualsWithDelta((1000 - 941) / 941 * 100, $summary['sixtyDayReturn'], 0.000001);
        $this->assertNull($summary['yearToDateReturn']);
        Http::assertSentCount(5);
    }
}
