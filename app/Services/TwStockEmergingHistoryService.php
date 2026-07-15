<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockEmergingHistoryService
{
    private const TPEX_EMERGING_HISTORY_URL = 'https://www.tpex.org.tw/www/zh-tw/emerging/historical';

    /**
     * @return array{previousClose: float|null, previousCloseDate: string|null, fiveDayReturn: float|null, twentyDayReturn: float|null, sixtyDayReturn: float|null, yearToDateReturn: null}|null
     */
    public function summary(string $stockCode, string $today, string $timezone = 'Asia/Taipei'): ?array
    {
        $stockCode = strtoupper(preg_replace('/[^0-9A-Z]/i', '', $stockCode) ?? '');
        if ($stockCode === '') {
            return null;
        }

        return Cache::remember(
            'tw-stock:emerging-history:' . $today . ':' . $stockCode . ':v1',
            now()->addHours(12),
            fn (): ?array => $this->fetchSummary($stockCode, $today, $timezone),
        );
    }

    /**
     * @return array{previousClose: float|null, previousCloseDate: string|null, fiveDayReturn: float|null, twentyDayReturn: float|null, sixtyDayReturn: float|null, yearToDateReturn: null}|null
     */
    private function fetchSummary(string $stockCode, string $today, string $timezone): ?array
    {
        try {
            $monthStart = CarbonImmutable::parse($today, $timezone)->startOfMonth();
        } catch (Throwable) {
            return null;
        }

        $months = collect(range(0, 4))
            ->map(fn (int $offset): CarbonImmutable => $monthStart->subMonths($offset))
            ->keyBy(fn (CarbonImmutable $month): string => $month->format('Ym'));

        try {
            $responses = Http::pool(fn (Pool $pool): array => $months
                ->mapWithKeys(function (CarbonImmutable $month, string $key) use ($pool, $stockCode): array {
                    return [
                        $key => $pool->as($key)
                            ->withHeaders([
                                'Accept' => 'application/json,text/plain,*/*',
                                'Referer' => 'https://www.tpex.org.tw/zh-tw/esb/trading/info/stock-pricing.html',
                                'User-Agent' => 'Mozilla/5.0',
                            ])
                            ->asForm()
                            ->timeout(6)
                            ->post(self::TPEX_EMERGING_HISTORY_URL, [
                                'type' => 'Monthly',
                                'date' => $month->format('Y/m/01'),
                                'code' => $stockCode,
                                'response' => 'json',
                            ]),
                    ];
                })
                ->all());
        } catch (Throwable) {
            return null;
        }

        $prices = [];
        foreach ($responses as $response) {
            if (!$response instanceof HttpResponse || !$response->successful()) {
                continue;
            }

            try {
                $payload = $response->json();
            } catch (Throwable) {
                continue;
            }

            $rows = is_array($payload['tables'][0]['data'] ?? null) ? $payload['tables'][0]['data'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $tradeDate = $this->rocDate((string) ($row[0] ?? ''), $timezone);
                $averagePrice = $this->numberOrNull($row[5] ?? null);
                if ($tradeDate === null || $tradeDate >= $today || $averagePrice === null || $averagePrice <= 0) {
                    continue;
                }

                $prices[$tradeDate] = $averagePrice;
            }
        }

        if ($prices === []) {
            return null;
        }

        krsort($prices, SORT_STRING);
        $dates = array_keys($prices);
        $values = array_values($prices);
        $previousClose = $values[0] ?? null;

        return [
            'previousClose' => $previousClose,
            'previousCloseDate' => $dates[0] ?? null,
            'fiveDayReturn' => $this->returnRate($previousClose, $values[4] ?? null),
            'twentyDayReturn' => $this->returnRate($previousClose, $values[19] ?? null),
            'sixtyDayReturn' => $this->returnRate($previousClose, $values[59] ?? null),
            'yearToDateReturn' => null,
        ];
    }

    private function rocDate(string $value, string $timezone): ?string
    {
        $parts = preg_split('/\D+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) < 3) {
            return null;
        }

        $year = (int) $parts[0];
        $year = $year < 1911 ? $year + 1911 : $year;

        try {
            return CarbonImmutable::createSafe(
                $year,
                (int) $parts[1],
                (int) $parts[2],
                0,
                0,
                0,
                $timezone,
            )->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-' || $value === '--') {
            return null;
        }

        $numeric = (float) str_replace(',', '', (string) $value);

        return is_finite($numeric) ? $numeric : null;
    }

    private function returnRate(?float $current, ?float $base): ?float
    {
        return $current !== null && $base !== null && $base > 0
            ? ($current - $base) / $base * 100
            : null;
    }
}
