<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockRealtimeQuoteService
{
    private const CNYES_QUOTES_URL = 'https://ws.api.cnyes.com/ws/api/v1/quote/quotes/';
    private const TRADINGVIEW_SCAN_URL = 'https://scanner.tradingview.com/taiwan/scan';
    private const TWSE_MIS_URL = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp';
    private const YAHOO_TW_STOCK_LIST_URL = 'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList;symbols=';
    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s';

    public function quotes(array $codes): array
    {
        $codes = $this->normalizeCodes($codes);
        $ttl = max(1, (int) config('esun.quote_cache_seconds', 1));

        if ($codes === []) {
            return $this->emptyPayload($ttl);
        }

        $cacheKey = 'tw-stock:realtime-quotes:v2:' . sha1(implode(',', $codes));

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
        $candidates = [];
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
                    'cnyes' => $this->fetchCnyesQuotes($missing),
                    'yahoo_tw' => $this->fetchYahooTwQuotes($missing),
                    'tradingview' => $this->fetchTradingViewQuotes($missing),
                    'yahoo_chart' => $this->fetchYahooChartQuotes($missing),
                    default => [],
                };

                foreach ($providerQuotes as $code => $quote) {
                    $code = (string) $code;
                    $price = $this->priceOrNull($quote['price'] ?? null);
                    if (!in_array($code, $missing, true) || $price === null) {
                        continue;
                    }

                    $quote['price'] = $price;
                    $quote['source'] = (string) ($quote['source'] ?? $provider);
                    $quote['sourceLabel'] = (string) ($quote['sourceLabel'] ?? $this->providerLabel($provider));
                    $candidates[$code][] = $quote;
                }

                foreach ($missing as $code) {
                    if (!isset($quotes[$code])) {
                        $confirmed = $this->confirmedQuote($candidates[$code] ?? []);
                        if ($confirmed !== null) {
                            $quotes[$code] = $confirmed;
                        }
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
        $unconfirmed = [];
        foreach ($missing as $code) {
            if (($candidates[$code] ?? []) !== []) {
                $unconfirmed[$code] = $this->summarizeCandidates($candidates[$code]);
            }
        }

        return [
            'servedAt' => $this->now()->toIso8601String(),
            'cacheSeconds' => $ttl,
            'source' => [
                'status' => $quotes === [] ? 'unavailable' : ($missing === [] ? 'live' : 'partial'),
                'providers' => $providers,
                'label' => $quotes === []
                    ? ($providers === [] ? '無可用報價源' : '未達雙來源一致')
                    : $this->confirmedSourceLabel($quotes),
                'message' => $missing === []
                    ? '庫存報價已通過雙來源一致性驗證。'
                    : '部分庫存報價尚未有兩個來源同價，已保留上一筆確認價格。',
                'errors' => $errors,
                'confirmationRequired' => $this->confirmationRequired(),
            ],
            'quotes' => $quotes,
            'missing' => $missing,
            'unconfirmed' => $unconfirmed,
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
        $last = $this->priceOrNull($row['z'] ?? null);
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
    private function fetchCnyesQuotes(array $codes): array
    {
        $symbols = collect($codes)
            ->map(fn (string $code): string => 'TWS:' . $code . ':STOCK')
            ->implode(',');

        $payload = Http::withHeaders([
            'Accept' => 'application/json,text/plain,*/*',
            'Referer' => 'https://www.cnyes.com/',
            'User-Agent' => 'Mozilla/5.0',
        ])
            ->timeout($this->timeout())
            ->retry(1, 150)
            ->get(self::CNYES_QUOTES_URL . $symbols, ['column' => 'G'])
            ->throw()
            ->json();

        $rows = $payload['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $quotes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = $this->normalizeCode((string) ($row['200010'] ?? ''));
            $price = $this->priceOrNull($row['6'] ?? null);
            if ($code === '' || $price === null) {
                continue;
            }

            $change = $this->numberOrNull($row['11'] ?? null);
            $previousClose = $change === null ? null : $price - $change;

            $quotes[$code] = [
                'code' => $code,
                'name' => (string) ($row['200009'] ?? ''),
                'price' => $price,
                'priceType' => 'last',
                'lastPrice' => $price,
                'previousClose' => $previousClose,
                'dayChange' => $change,
                'dayChangeRate' => $this->numberOrNull($row['56'] ?? null),
                'bestBid' => $this->numberOrNull($row['22'] ?? null),
                'bestAsk' => $this->numberOrNull($row['25'] ?? null),
                'open' => null,
                'high' => $this->numberOrNull($row['12'] ?? null),
                'low' => $this->numberOrNull($row['13'] ?? null),
                'volumeLots' => $this->numberOrNull($row['800001'] ?? $row['200013'] ?? null),
                'exchange' => 'TWS',
                'quotedAt' => $this->unixTimestamp($row['200007'] ?? null),
                'source' => 'cnyes',
                'sourceLabel' => $this->providerLabel('cnyes'),
            ];
        }

        return $quotes;
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchYahooTwQuotes(array $codes): array
    {
        $symbols = collect($codes)
            ->flatMap(fn (string $code): array => [$code . '.TW', $code . '.TWO'])
            ->implode(',');

        $payload = Http::withHeaders([
            'Accept' => 'application/json,text/plain,*/*',
            'Referer' => 'https://tw.stock.yahoo.com/',
            'User-Agent' => 'Mozilla/5.0',
        ])
            ->timeout($this->timeout())
            ->retry(1, 150)
            ->get(self::YAHOO_TW_STOCK_LIST_URL . rawurlencode($symbols))
            ->throw()
            ->json();

        if (!is_array($payload)) {
            return [];
        }

        $quotes = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = $this->normalizeCode((string) ($row['systexId'] ?? strtok((string) ($row['symbol'] ?? ''), '.')));
            if ($code === '' || isset($quotes[$code])) {
                continue;
            }

            $price = $this->priceOrNull($this->rawField($row, 'price'));
            if ($price === null) {
                continue;
            }

            $previousClose = $this->numberOrNull($this->rawField($row, 'regularMarketPreviousClose'));
            $change = $this->numberOrNull($this->rawField($row, 'change'));
            $changeRate = $this->numberOrNull(str_replace('%', '', (string) ($row['changePercent'] ?? '')));

            $quotes[$code] = [
                'code' => $code,
                'name' => (string) ($row['symbolName'] ?? ''),
                'price' => $price,
                'priceType' => 'last',
                'lastPrice' => $price,
                'previousClose' => $previousClose,
                'dayChange' => $change,
                'dayChangeRate' => $changeRate,
                'bestBid' => $this->numberOrNull($this->rawField($row, 'bid')),
                'bestAsk' => $this->numberOrNull($this->rawField($row, 'ask')),
                'open' => $this->numberOrNull($this->rawField($row, 'regularMarketOpen')),
                'high' => $this->numberOrNull($this->rawField($row, 'regularMarketDayHigh')),
                'low' => $this->numberOrNull($this->rawField($row, 'regularMarketDayLow')),
                'volumeLots' => $this->volumeSharesToLots($row['volume'] ?? null),
                'exchange' => (string) ($row['exchange'] ?? ''),
                'quotedAt' => $this->isoTimestamp($row['regularMarketTime'] ?? null),
                'source' => 'yahoo_tw',
                'sourceLabel' => $this->providerLabel('yahoo_tw'),
            ];
        }

        return $quotes;
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchTradingViewQuotes(array $codes): array
    {
        $tickers = collect($codes)
            ->flatMap(fn (string $code): array => ['TWSE:' . $code, 'TPEX:' . $code])
            ->values()
            ->all();

        $payload = Http::withHeaders([
            'Accept' => 'application/json,text/plain,*/*',
            'User-Agent' => 'Mozilla/5.0',
        ])
            ->asJson()
            ->timeout($this->timeout())
            ->retry(1, 150)
            ->post(self::TRADINGVIEW_SCAN_URL, [
                'symbols' => [
                    'tickers' => $tickers,
                    'query' => ['types' => []],
                ],
                'columns' => ['name', 'description', 'close', 'change', 'change_abs', 'volume', 'update_mode'],
            ])
            ->throw()
            ->json();

        $rows = $payload['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $quotes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $symbol = (string) ($row['s'] ?? '');
            $data = $row['d'] ?? [];
            if (!is_array($data)) {
                continue;
            }

            $code = $this->normalizeCode((string) ($data[0] ?? substr(strrchr($symbol, ':'), 1) ?: ''));
            if ($code === '' || isset($quotes[$code])) {
                continue;
            }

            $price = $this->priceOrNull($data[2] ?? null);
            if ($price === null) {
                continue;
            }

            $changeRate = $this->numberOrNull($data[3] ?? null);
            $change = $this->numberOrNull($data[4] ?? null);
            $previousClose = $change === null ? null : $price - $change;

            $quotes[$code] = [
                'code' => $code,
                'name' => (string) ($data[1] ?? ''),
                'price' => $price,
                'priceType' => 'last',
                'lastPrice' => $price,
                'previousClose' => $previousClose,
                'dayChange' => $change,
                'dayChangeRate' => $changeRate,
                'bestBid' => null,
                'bestAsk' => null,
                'open' => null,
                'high' => null,
                'low' => null,
                'volumeLots' => $this->numberOrNull($data[5] ?? null),
                'exchange' => str_contains($symbol, ':') ? strstr($symbol, ':', true) : '',
                'quotedAt' => $this->now()->toIso8601String(),
                'source' => 'tradingview',
                'sourceLabel' => $this->providerLabel('tradingview'),
                'updateMode' => (string) ($data[6] ?? ''),
            ];
        }

        return $quotes;
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchYahooChartQuotes(array $codes): array
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

        $price = $this->priceOrNull($meta['regularMarketPrice'] ?? null);
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
            'source' => 'yahoo_chart',
            'sourceLabel' => $this->providerLabel('yahoo_chart'),
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
        $primary = $this->configuredProviders((string) config('esun.quote_providers', 'twse,cnyes,yahoo_tw'));
        $fallback = $this->configuredProviders((string) config('esun.quote_fallback_providers', 'tradingview,yahoo_chart'));

        if (count($primary) > 1) {
            $rotationSeconds = max(1, (int) config('esun.quote_rotation_seconds', 1));
            $offset = ((int) floor(microtime(true) / $rotationSeconds)) % count($primary);
            $primary = array_merge(array_slice($primary, $offset), array_slice($primary, 0, $offset));
        }

        return collect([...$primary, ...$fallback])
            ->unique()
            ->values()
            ->all() ?: ['twse', 'cnyes', 'yahoo_tw', 'tradingview', 'yahoo_chart'];
    }

    /**
     * @return list<string>
     */
    private function configuredProviders(string $value): array
    {
        $allowed = ['twse', 'cnyes', 'yahoo_tw', 'tradingview', 'yahoo_chart'];

        return collect(explode(',', $value))
            ->map(fn (string $provider): string => strtolower(trim($provider)))
            ->filter(fn (string $provider): bool => in_array($provider, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }

    private function providerLabel(string $provider): string
    {
        return match ($provider) {
            'twse' => 'TWSE MIS',
            'cnyes' => 'CNYES',
            'yahoo_tw' => 'Yahoo 台股',
            'tradingview' => 'TradingView',
            'yahoo_chart' => 'Yahoo Finance chart',
            default => strtoupper($provider),
        };
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>|null
     */
    private function confirmedQuote(array $candidates): ?array
    {
        $required = $this->confirmationRequired();
        $decimals = max(0, (int) config('esun.quote_confirmation_decimals', 2));
        $groups = [];

        foreach ($candidates as $candidate) {
            $price = $this->priceOrNull($candidate['price'] ?? null);
            if ($price === null) {
                continue;
            }

            $key = number_format(round($price, $decimals), $decimals, '.', '');
            $source = (string) ($candidate['source'] ?? '');
            $alreadyCounted = collect($groups[$key] ?? [])
                ->contains(fn (array $item): bool => (string) ($item['source'] ?? '') === $source);

            if (!$alreadyCounted) {
                $candidate['price'] = (float) $key;
                $groups[$key][] = $candidate;
            }
        }

        foreach ($groups as $priceKey => $matches) {
            if (count($matches) < $required) {
                continue;
            }

            $base = $this->latestCandidate($matches);
            $labels = collect($matches)
                ->map(fn (array $quote): string => (string) ($quote['sourceLabel'] ?? $quote['source'] ?? ''))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($required <= 1) {
                return [
                    ...$base,
                    'confirmedBy' => $labels,
                    'confirmationCount' => count($matches),
                    'candidatePrices' => $this->summarizeCandidates($matches),
                ];
            }

            return [
                ...$base,
                'price' => (float) $priceKey,
                'priceType' => 'confirmed',
                'source' => 'confirmed',
                'sourceLabel' => implode(' + ', $labels),
                'confirmedBy' => $labels,
                'confirmationCount' => count($matches),
                'candidatePrices' => $this->summarizeCandidates($matches),
            ];
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $quotes
     */
    private function confirmedSourceLabel(array $quotes): string
    {
        $labels = collect($quotes)
            ->flatMap(fn (array $quote): array => $quote['confirmedBy'] ?? [$quote['sourceLabel'] ?? $quote['source'] ?? ''])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($this->confirmationRequired() <= 1) {
            return $labels === [] ? '可用報價源' : implode(' + ', $labels);
        }

        return $labels === [] ? '雙來源確認報價' : '雙來源確認：' . implode(' + ', $labels);
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function summarizeCandidates(array $candidates): array
    {
        return collect($candidates)
            ->map(fn (array $quote): array => [
                'source' => (string) ($quote['source'] ?? ''),
                'sourceLabel' => (string) ($quote['sourceLabel'] ?? $quote['source'] ?? ''),
                'price' => $this->priceOrNull($quote['price'] ?? null),
                'previousClose' => $this->numberOrNull($quote['previousClose'] ?? null),
                'dayChange' => $this->numberOrNull($quote['dayChange'] ?? null),
                'dayChangeRate' => $this->numberOrNull($quote['dayChangeRate'] ?? null),
                'quotedAt' => $quote['quotedAt'] ?? null,
            ])
            ->filter(fn (array $quote): bool => $quote['price'] !== null)
            ->values()
            ->all();
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function latestCandidate(array $candidates): array
    {
        return collect($candidates)
            ->sortByDesc(fn (array $quote): int => $this->timestampScore($quote['quotedAt'] ?? null))
            ->first() ?? $candidates[0];
    }

    private function timestampScore(mixed $value): int
    {
        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        try {
            return CarbonImmutable::parse($value)->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }

    private function confirmationRequired(): int
    {
        return max(1, (int) config('esun.quote_confirmation_required', 2));
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

        return $this->priceOrNull($first);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rawField(array $row, string $key): mixed
    {
        $value = $row[$key] ?? null;
        if (is_array($value) && array_key_exists('raw', $value)) {
            return $value['raw'];
        }

        return $value;
    }

    private function volumeSharesToLots(mixed $value): ?float
    {
        $volume = $this->numberOrNull($value);

        return $volume === null ? null : $volume / 1000;
    }

    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === '-' || $value === '--') {
            return null;
        }

        $numeric = (float) str_replace(',', '', (string) $value);

        return is_finite($numeric) ? $numeric : null;
    }

    private function priceOrNull(mixed $value): ?float
    {
        $number = $this->numberOrNull($value);

        return $number !== null && $number > 0 ? $number : null;
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
        return $this->unixTimestamp($timestamp);
    }

    private function unixTimestamp(mixed $timestamp): ?string
    {
        $seconds = $this->numberOrNull($timestamp);
        if ($seconds === null) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $seconds)
            ->setTimezone((string) config('esun.timezone', 'Asia/Taipei'))
            ->toIso8601String();
    }

    private function isoTimestamp(mixed $timestamp): ?string
    {
        if (!is_string($timestamp) || trim($timestamp) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)
                ->setTimezone((string) config('esun.timezone', 'Asia/Taipei'))
                ->toIso8601String();
        } catch (Throwable) {
            return null;
        }
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
