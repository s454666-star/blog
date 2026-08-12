<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class TwStockHistoricalDividendFetcher
{
    /**
     * @param list<string> $stockCodes
     * @return list<array<string, mixed>>
     */
    public function fetch(array $stockCodes, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $wanted = array_fill_keys(array_values(array_unique(array_filter($stockCodes))), true);
        if ($wanted === []) {
            return [];
        }

        $events = array_merge(
            $this->twseEvents($from, $to),
            $this->tpexEvents($from, $to),
        );

        return collect($events)
            ->filter(fn (array $event): bool => isset($wanted[$event['stock_code']]))
            ->unique(fn (array $event): string => $event['stock_code'] . ':' . $event['ex_dividend_date'])
            ->sortBy('ex_dividend_date')
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function twseEvents(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $payload = Cache::remember(
            'tw-stock:portfolio-dividends:twse:' . $from->format('Ymd') . ':' . $to->format('Ymd'),
            $to->isBefore(CarbonImmutable::today()) ? now()->addYear() : now()->addHours(8),
            fn (): array => Http::acceptJson()->timeout(30)->retry(2, 300)
                ->get('https://www.twse.com.tw/rwd/zh/exRight/TWT49U', [
                    'startDate' => $from->format('Ymd'),
                    'endDate' => $to->format('Ymd'),
                    'response' => 'json',
                ])->throw()->json(),
        );

        $events = [];
        foreach (is_array($payload['data'] ?? null) ? $payload['data'] : [] as $row) {
            if (!is_array($row) || trim((string) ($row[6] ?? '')) !== '息') {
                continue;
            }
            $cashDividend = $this->decimal($row[5] ?? null);
            $date = $this->rocDate($row[0] ?? null);
            $code = trim((string) ($row[1] ?? ''));
            if ($date === null || $code === '' || $cashDividend <= 0.0) {
                continue;
            }
            $events[] = [
                'stock_code' => $code,
                'stock_name' => trim((string) ($row[2] ?? '')),
                'ex_dividend_date' => $date,
                'cash_dividend_per_share' => $cashDividend,
                'source' => 'TWSE TWT49U',
                'source_payload' => $row,
            ];
        }

        return $events;
    }

    /** @return list<array<string, mixed>> */
    private function tpexEvents(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $payload = Cache::remember(
            'tw-stock:portfolio-dividends:tpex:' . $from->format('Ymd') . ':' . $to->format('Ymd'),
            $to->isBefore(CarbonImmutable::today()) ? now()->addYear() : now()->addHours(8),
            fn (): array => Http::asForm()->acceptJson()->timeout(30)->retry(2, 300)
                ->post('https://www.tpex.org.tw/www/zh-tw/bulletin/exDailyQ', [
                    'startDate' => $from->format('Y/m/d'),
                    'endDate' => $to->format('Y/m/d'),
                    'response' => 'json',
                ])->throw()->json(),
        );

        $rows = $payload['tables'][0]['data'] ?? [];
        $events = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || !str_contains((string) ($row[8] ?? ''), '息')) {
                continue;
            }
            $cashDividend = $this->decimal($row[13] ?? null);
            $date = $this->rocDate($row[0] ?? null);
            $code = trim((string) ($row[1] ?? ''));
            if ($date === null || $code === '' || $cashDividend <= 0.0) {
                continue;
            }
            $events[] = [
                'stock_code' => $code,
                'stock_name' => trim((string) ($row[2] ?? '')),
                'ex_dividend_date' => $date,
                'cash_dividend_per_share' => $cashDividend,
                'source' => 'TPEx exDailyQ',
                'source_payload' => $row,
            ];
        }

        return $events;
    }

    private function rocDate(mixed $value): ?string
    {
        if (preg_match('/(\d{3})\D*(\d{2})\D*(\d{2})/', (string) $value, $matches) !== 1) {
            return null;
        }

        return sprintf('%04d-%s-%s', (int) $matches[1] + 1911, $matches[2], $matches[3]);
    }

    private function decimal(mixed $value): float
    {
        $normalized = str_replace(',', '', trim(strip_tags((string) $value)));

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }
}
