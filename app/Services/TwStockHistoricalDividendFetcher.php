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

        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $policies = collect($rows)->contains(
            fn (mixed $row): bool => is_array($row)
                && trim((string) ($row[6] ?? '')) !== '息'
                && str_contains((string) ($row[6] ?? ''), '息'),
        ) ? $this->twseCashDividendPolicies() : [];
        $usedPolicies = [];

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !str_contains((string) ($row[6] ?? ''), '息')) {
                continue;
            }
            $date = $this->rocDate($row[0] ?? null);
            $code = trim((string) ($row[1] ?? ''));
            $policy = null;
            if (trim((string) ($row[6] ?? '')) === '息') {
                $cashDividend = $this->decimal($row[5] ?? null);
            } else {
                [$cashDividend, $policy] = $this->matchingCashDividendPolicy(
                    $policies[$code] ?? [],
                    $date,
                    $usedPolicies,
                );
            }
            if ($date === null || $code === '' || $cashDividend <= 0.0) {
                continue;
            }
            $events[] = [
                'stock_code' => $code,
                'stock_name' => trim((string) ($row[2] ?? '')),
                'ex_dividend_date' => $date,
                'cash_dividend_per_share' => $cashDividend,
                'source' => $policy === null ? 'TWSE TWT49U' : 'TWSE TWT49U + MOPS t187ap45_L',
                'source_payload' => $policy === null ? $row : [
                    'ex_dividend' => $row,
                    'dividend_policy' => $policy,
                ],
            ];
        }

        return $events;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function twseCashDividendPolicies(): array
    {
        $payload = Cache::remember(
            'tw-stock:portfolio-dividends:twse-dividend-policies',
            now()->addHours(8),
            fn (): array => Http::acceptJson()->timeout(30)->retry(2, 300)
                ->get('https://openapi.twse.com.tw/v1/opendata/t187ap45_L')
                ->throw()->json(),
        );

        $policies = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string) ($row['公司代號'] ?? ''));
            $boardDate = $this->compactRocDate($row['董事會（擬議）股利分派日'] ?? null);
            $cashDividend = $this->decimal($row['股東配發-盈餘分配之現金股利(元/股)'] ?? null)
                + $this->decimal($row['股東配發-法定盈餘公積發放之現金(元/股)'] ?? null)
                + $this->decimal($row['股東配發-資本公積發放之現金(元/股)'] ?? null);
            if ($code === '' || $boardDate === null || $cashDividend <= 0.0) {
                continue;
            }
            $row['_board_date'] = $boardDate;
            $row['_cash_dividend_per_share'] = $cashDividend;
            $policies[$code][] = $row;
        }

        foreach ($policies as &$rows) {
            usort($rows, fn (array $left, array $right): int => $left['_board_date'] <=> $right['_board_date']);
        }

        return $policies;
    }

    /**
     * @param list<array<string, mixed>> $policies
     * @param array<string, true> $usedPolicies
     * @return array{float, array<string, mixed>|null}
     */
    private function matchingCashDividendPolicy(array $policies, ?string $exDividendDate, array &$usedPolicies): array
    {
        if ($exDividendDate === null) {
            return [0.0, null];
        }

        $match = null;
        foreach ($policies as $policy) {
            $key = ($policy['公司代號'] ?? '') . ':' . ($policy['_board_date'] ?? '') . ':' . ($policy['期別'] ?? '');
            if (($policy['_board_date'] ?? '') <= $exDividendDate && !isset($usedPolicies[$key])) {
                $match = [$key, $policy];
            }
        }
        if ($match === null) {
            return [0.0, null];
        }

        [$key, $policy] = $match;
        $usedPolicies[$key] = true;

        return [(float) $policy['_cash_dividend_per_share'], $policy];
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

    private function compactRocDate(mixed $value): ?string
    {
        if (preg_match('/^(\d{3})(\d{2})(\d{2})$/', trim((string) $value), $matches) !== 1) {
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
