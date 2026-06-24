<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockRealtimeQuoteService
{
    private const TWSE_MIS_URL = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp';
    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s';

    public function quotes(array $codes): array
    {
        $codes = $this->normalizeCodes($codes);
        $ttl = max(1, (int) config('esun.quote_cache_seconds', 1));

        if ($codes === []) {
            return $this->emptyPayload($ttl);
        }

        $cacheKey = 'tw-stock:realtime-quotes:v1:' . sha1(implode(',', $codes));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds($ttl),
            fn (): array => $this->fetchQuotes($codes, $ttl),
        );
    }

    /**
     * @param list<string> $codes
     */
    private function fetchQuotes(array $codes, int $ttl): array
    {
        $quotes = [];
        $providers = [];
        $errors = [];

        foreach ($this->providerOrder() as $provider) {
            $missing = array_values(array_diff($codes, array_keys($quotes)));
            if ($missing === []) {
                break;
            }

            try {
                $providerQuotes = match ($provider) {
                    'twse' => $this->fetchTwseQuotes($missing),
                    'yahoo' => $this->fetchYahooQuotes($missing),
                    default => [],
                };

                foreach ($providerQuotes as $code => $quote) {
                    if (!isset($quotes[$code]) && $this->numberOrNull($quote['price'] ?? null) !== null) {
                        $quotes[$code] = $quote;
                    }
                }

                if ($providerQuotes !== []) {
                    $providers[] = $provider;
                }
            } catch (Throwable $exception) {
                $errors[$provider] = $exception->getMessage();
            }
        }

        $missing = array_values(array_diff($codes, array_keys($quotes)));

        return [
            'servedAt' => $this->now()->toIso8601String(),
            'cacheSeconds' => $ttl,
            'source' => [
                'status' => $quotes === [] ? 'unavailable' : ($missing === [] ? 'live' : 'partial'),
                'providers' => $providers,
                'label' => $providers === [] ? '無可用報價源' : implode(' + ', array_map($this->providerLabel(...), $providers)),
                'message' => $missing === []
                    ? '庫存報價更新成功。'
                    : '部分庫存報價暫時缺漏，已保留上一筆價格。',
                'errors' => $errors,
            ],
            'quotes' => $quotes,
            'missing' => $missing,
        ];
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchTwseQuotes(array $codes): array
    {
        $channels = collect($codes)
            ->flatMap(fn (string $code): array => ['tse_' . $code . '.tw', 'otc_' . $code . '.tw'])
            ->implode('|');

        $payload = Http::withHeaders([
            'Accept' => 'application/json,text/plain,*/*',
            'Referer' => 'https://mis.twse.com.tw/stock/index.jsp',
            'User-Agent' => 'Mozilla/5.0',
        ])
            ->timeout($this->timeout())
            ->retry(1, 150)
            ->get(self::TWSE_MIS_URL, [
                'ex_ch' => $channels,
                'json' => '1',
                'delay' => '0',
                '_' => (string) floor(microtime(true) * 1000),
            ])
            ->throw()
            ->json();

        $rows = $payload['msgArray'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $quotes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = $this->normalizeCode((string) ($row['c'] ?? ''));
            if ($code === '') {
                continue;
            }

            $quote = $this->twseRowToQuote($row);
            if ($quote !== null) {
                $quotes[$code] = $quote;
            }
        }

        return $quotes;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function twseRowToQuote(array $row): ?array
    {
        $last = $this->numberOrNull($row['z'] ?? null);
        $previousClose = $this->numberOrNull($row['y'] ?? null);
        $bestBid = $this->firstPrice($row['b'] ?? null);
        $bestAsk = $this->firstPrice($row['a'] ?? null);
        $priceType = 'last';
        $price = $last;

        if ($price === null && $bestBid !== null && $bestAsk !== null) {
            $price = round(($bestBid + $bestAsk) / 2, 2);
            $priceType = 'mid';
        } elseif ($price === null && $bestBid !== null) {
            $price = $bestBid;
            $priceType = 'bid';
        } elseif ($price === null && $bestAsk !== null) {
            $price = $bestAsk;
            $priceType = 'ask';
        } elseif ($price === null && $previousClose !== null) {
            $price = $previousClose;
            $priceType = 'previous';
        }

        if ($price === null) {
            return null;
        }

        $change = $previousClose === null ? null : $price - $previousClose;
        $changeRate = $previousClose > 0 ? $change / $previousClose * 100 : null;

        return [
            'code' => $this->normalizeCode((string) ($row['c'] ?? '')),
            'name' => (string) ($row['n'] ?? ''),
            'price' => $price,
            'priceType' => $priceType,
            'lastPrice' => $last,
            'previousClose' => $previousClose,
            'dayChange' => $change,
            'dayChangeRate' => $changeRate,
            'bestBid' => $bestBid,
            'bestAsk' => $bestAsk,
            'open' => $this->numberOrNull($row['o'] ?? null),
            'high' => $this->numberOrNull($row['h'] ?? null),
            'low' => $this->numberOrNull($row['l'] ?? null),
            'volumeLots' => $this->numberOrNull($row['v'] ?? null),
            'exchange' => (string) ($row['ex'] ?? ''),
            'quotedAt' => $this->twseTimestamp($row),
            'source' => 'twse',
            'sourceLabel' => $this->providerLabel('twse'),
        ];
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchYahooQuotes(array $codes): array
    {
        $quotes = [];

        foreach ($codes as $code) {
            $quote = $this->fetchYahooQuote($code, 'TW') ?? $this->fetchYahooQuote($code, 'TWO');
            if ($quote !== null) {
                $quotes[$code] = $quote;
            }
        }

        return $quotes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchYahooQuote(string $code, string $suffix): ?array
    {
        $symbol = $code . '.' . $suffix;
        $payload = Http::withHeaders([
            'Accept' => 'application/json,text/plain,*/*',
            'User-Agent' => 'Mozilla/5.0',
        ])
            ->timeout($this->timeout())
            ->get(sprintf(self::YAHOO_CHART_URL, rawurlencode($symbol)), [
                'interval' => '1m',
                'range' => '1d',
            ])
            ->throw()
            ->json();

        $meta = $payload['chart']['result'][0]['meta'] ?? null;
        if (!is_array($meta)) {
            return null;
        }

        $price = $this->numberOrNull($meta['regularMarketPrice'] ?? null);
        if ($price === null) {
            return null;
        }

        $previousClose = $this->numberOrNull($meta['previousClose'] ?? $meta['chartPreviousClose'] ?? null);
        $change = $previousClose === null ? null : $price - $previousClose;

        return [
            'code' => $code,
            'name' => (string) ($meta['shortName'] ?? $meta['longName'] ?? ''),
            'price' => $price,
            'priceType' => 'last',
            'lastPrice' => $price,
            'previousClose' => $previousClose,
            'dayChange' => $change,
            'dayChangeRate' => $previousClose > 0 ? $change / $previousClose * 100 : null,
            'bestBid' => null,
            'bestAsk' => null,
            'open' => $this->numberOrNull($meta['regularMarketOpen'] ?? null),
            'high' => $this->numberOrNull($meta['regularMarketDayHigh'] ?? null),
            'low' => $this->numberOrNull($meta['regularMarketDayLow'] ?? null),
            'volumeLots' => $this->numberOrNull($meta['regularMarketVolume'] ?? null),
            'exchange' => $suffix,
            'quotedAt' => $this->yahooTimestamp($meta['regularMarketTime'] ?? null),
            'source' => 'yahoo',
            'sourceLabel' => $this->providerLabel('yahoo'),
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeCodes(array $codes): array
    {
        return collect($codes)
            ->map(fn (mixed $code): string => $this->normalizeCode((string) $code))
            ->filter()
            ->unique()
            ->take(30)
            ->values()
            ->all();
    }

    private function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[^0-9A-Z]/i', '', $code) ?? '');
    }

    /**
     * @return list<string>
     */
    private function providerOrder(): array
    {
        $providers = explode(',', (string) config('esun.quote_providers', 'twse,yahoo'));

        return collect($providers)
            ->map(fn (string $provider): string => strtolower(trim($provider)))
            ->filter(fn (string $provider): bool => in_array($provider, ['twse', 'yahoo'], true))
            ->unique()
            ->values()
            ->all() ?: ['twse', 'yahoo'];
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'twse' => 'TWSE MIS',
            'yahoo' => 'Yahoo Finance',
            default => strtoupper($provider),
        };
    }

    private function timeout(): int
    {
        return max(1, (int) config('esun.quote_timeout_seconds', 4));
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'));
    }

    private function firstPrice(mixed $value): ?float
    {
        $first = explode('_', (string) $value)[0] ?? null;

        return $this->numberOrNull($first);
    }

    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-' || $value === '--') {
            return null;
        }

        $numeric = (float) str_replace(',', '', (string) $value);

        return is_finite($numeric) ? $numeric : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function twseTimestamp(array $row): ?string
    {
        $date = preg_replace('/\D/', '', (string) ($row['d'] ?? ''));
        $time = (string) ($row['t'] ?? $row['%'] ?? '');

        if ($date === null || strlen($date) !== 8 || $time === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2) . ' ' . $time,
                (string) config('esun.timezone', 'Asia/Taipei'),
            )->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function yahooTimestamp(mixed $timestamp): ?string
    {
        $seconds = $this->numberOrNull($timestamp);
        if ($seconds === null) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $seconds)
            ->setTimezone((string) config('esun.timezone', 'Asia/Taipei'))
            ->toIso8601String();
    }

    private function emptyPayload(int $ttl): array
    {
        return [
            'servedAt' => $this->now()->toIso8601String(),
            'cacheSeconds' => $ttl,
            'source' => [
                'status' => 'empty',
                'providers' => [],
                'label' => '無庫存代號',
                'message' => '沒有需要更新的庫存報價。',
                'errors' => [],
            ],
            'quotes' => [],
            'missing' => [],
        ];
    }
}
