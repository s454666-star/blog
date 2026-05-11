<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockTwseDailyQuoteService
{
    private const PRIMARY_DAILY_PRICE_URL = 'https://www.twse.com.tw/exchangeReport/STOCK_DAY_ALL?response=json';

    private const FALLBACK_DAILY_PRICE_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL';

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRows(): array
    {
        foreach ([self::PRIMARY_DAILY_PRICE_URL, self::FALLBACK_DAILY_PRICE_URL] as $url) {
            try {
                $response = $this->http()->get($url);
            } catch (Throwable) {
                continue;
            }

            if (!$response->successful()) {
                continue;
            }

            $rows = $this->normalizeRows($response->json());
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        $date = $this->rocDateFromTwseResponseDate($payload['date'] ?? null);
        $data = $payload['data'] ?? null;
        if ($date === null || !is_array($data)) {
            return [];
        }

        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rows[] = [
                'Date' => $date,
                'Code' => $row[0] ?? null,
                'Name' => $row[1] ?? null,
                'TradeVolume' => $row[2] ?? null,
                'TradeValue' => $row[3] ?? null,
                'OpeningPrice' => $row[4] ?? null,
                'HighestPrice' => $row[5] ?? null,
                'LowestPrice' => $row[6] ?? null,
                'ClosingPrice' => $row[7] ?? null,
                'Change' => $row[8] ?? null,
                'Transaction' => $row[9] ?? null,
            ];
        }

        return $rows;
    }

    private function rocDateFromTwseResponseDate(mixed $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim((string) $value)) ?? '';
        if (strlen($normalized) !== 8) {
            return null;
        }

        $year = (int) substr($normalized, 0, 4);
        $month = (int) substr($normalized, 4, 2);
        $day = (int) substr($normalized, 6, 2);
        if (!checkdate($month, $day, $year) || $year <= 1911) {
            return null;
        }

        return sprintf('%03d%02d%02d', $year - 1911, $month, $day);
    }

    private function http(): PendingRequest
    {
        return Http::timeout(20)
            ->retry(2, 300)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'Mozilla/5.0']);
    }
}
