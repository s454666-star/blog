<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TwStockHistoricalDividendFetcher
{
    private const URL = 'https://api.finmindtrade.com/api/v4/data';

    /**
     * @param list<string> $stockCodes
     * @return list<array<string, mixed>>
     */
    public function fetch(array $stockCodes, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $events = [];

        foreach (array_values(array_unique(array_filter($stockCodes))) as $stockCode) {
            $query = [
                'dataset' => 'TaiwanStockDividendResult',
                'data_id' => $stockCode,
                'start_date' => $from->toDateString(),
                'end_date' => $to->toDateString(),
            ];
            $rows = Cache::remember(
                'tw-stock:portfolio-dividends:finmind:' . md5(json_encode($query, JSON_THROW_ON_ERROR)),
                $to->isBefore(CarbonImmutable::today()) ? now()->addYear() : now()->addHours(8),
                function () use ($query): array {
                    $payload = Http::acceptJson()->timeout(20)->retry(2, 300)
                        ->get(self::URL, $query)->throw()->json();

                    return is_array($payload) && (int) ($payload['status'] ?? 0) === 200
                        && is_array($payload['data'] ?? null)
                        ? array_values(array_filter($payload['data'], 'is_array'))
                        : [];
                },
            );

            foreach ($rows as $row) {
                $date = (string) ($row['date'] ?? '');
                $cashDividend = is_numeric($row['stock_and_cache_dividend'] ?? null)
                    ? (float) $row['stock_and_cache_dividend']
                    : 0.0;
                if ($date === '' || $cashDividend <= 0.0
                    || !str_contains((string) ($row['stock_or_cache_dividend'] ?? ''), '息')) {
                    continue;
                }

                $events[] = [
                    'stock_code' => $stockCode,
                    'ex_dividend_date' => $date,
                    'cash_dividend_per_share' => $cashDividend,
                    'source_payload' => $row,
                ];
            }
        }

        return collect($events)
            ->unique(fn (array $event): string => $event['stock_code'] . ':' . $event['ex_dividend_date'])
            ->sortBy('ex_dividend_date')
            ->values()
            ->all();
    }
}
