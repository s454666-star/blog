<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockRealtimeQuoteService
{
    private const CNYES_QUOTES_URL = 'https://ws.api.cnyes.com/ws/api/v1/quote/quotes/';
    private const CNYES_HISTORY_URL = 'https://ws.api.cnyes.com/ws/api/v1/charting/history';
    private const TRADINGVIEW_SCAN_URL = 'https://scanner.tradingview.com/taiwan/scan';
    private const TWSE_MIS_URL = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp';
    private const YAHOO_TW_STOCK_LIST_URL = 'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList;symbols=';
    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s';
    private const YAHOO_TW_QUOTE_URL = 'https://tw.stock.yahoo.com/quote/%s';
    private const TWSE_MARKET_QUOTE_CIRCUIT_KEY = 'tw-stock:official-market-quotes:twse-circuit-open:v1';

    /**
     * Fetches direct official exchange quotes in parallel for a large stock
     * universe. Unlike the portfolio quote method, this intentionally does not
     * require a second provider because it is used for a public market ranking,
     * not account PnL state.
     *
     * @param list<array{code: string, exchange: string}> $stocks
     * @return array<string, mixed>
     */
    public function officialMarketQuotes(array $stocks): array
    {
        $stocks = collect($stocks)
            ->map(function (array $stock): array {
                $code = $this->normalizeCode((string) ($stock['code'] ?? ''));
                $exchange = strtoupper(trim((string) ($stock['exchange'] ?? '')));

                return [
                    'code' => $code,
                    'exchange' => $exchange === 'TWSE' ? 'TWSE' : 'TPEx',
                ];
            })
            ->filter(fn (array $stock): bool => $stock['code'] !== '')
            ->unique(fn (array $stock): string => $stock['exchange'] . '|' . $stock['code'])
            ->values()
            ->all();

        $ttl = 60;
        if ($stocks === []) {
            return [
                'servedAt' => $this->now()->toIso8601String(),
                'cacheSeconds' => $ttl,
                'source' => ['status' => 'empty', 'label' => '證交所即時報價', 'errors' => []],
                'quotes' => [],
                'missing' => [],
            ];
        }

        $cacheKey = 'tw-stock:official-market-quotes:v1:' . sha1(serialize($stocks));
        $staleCacheKey = $cacheKey . ':stale';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $payload = $this->buildOfficialMarketQuotes($stocks, $ttl);
            Cache::put($cacheKey, $payload, now()->addSeconds($ttl));
            if (($payload['quotes'] ?? []) !== []) {
                Cache::put($staleCacheKey, $payload, now()->addMinutes(10));
            }

            return $payload;
        } catch (Throwable $exception) {
            $stale = Cache::get($staleCacheKey);
            if (is_array($stale)) {
                $stale['servedAt'] = $this->now()->toIso8601String();
                $stale['source']['status'] = 'stale';
                $stale['source']['label'] = ((string) ($stale['source']['label'] ?? '即時報價')) . '（沿用上一筆成功資料）';
                $stale['source']['errors']['stale_fallback'] = $exception->getMessage();

                return $stale;
            }

            throw $exception;
        }
    }

    /**
     * @param list<array{code: string, exchange: string}> $stocks
     * @return array<string, mixed>
     */
    private function buildOfficialMarketQuotes(array $stocks, int $ttl): array
    {
        $twseCircuitOpen = Cache::get(self::TWSE_MARKET_QUOTE_CIRCUIT_KEY) === true || count($stocks) > 500;
        $chunks = array_chunk($stocks, 60);
        [$quotes, $errors] = $twseCircuitOpen
            ? [[], []]
            : $this->fetchOfficialMarketQuoteChunks($chunks);

        $codes = collect($stocks)->pluck('code')->unique()->values()->all();
        $missing = array_values(array_diff($codes, array_keys($quotes)));
        $fallbackProviders = [];
        if ($missing !== []) {
            [$fallbackQuotes, $fallbackErrors, $fallbackProviders] = $this->fetchMarketQuoteFallbacks($missing);
            foreach ($fallbackQuotes as $code => $quote) {
                if (!isset($quotes[$code])) {
                    $quotes[$code] = $quote;
                }
            }
            $errors = [...$errors, ...$fallbackErrors];
            $missing = array_values(array_diff($codes, array_keys($quotes)));
        }
        if ($missing !== [] && (count($stocks) <= 500 || count($missing) <= 100)) {
            $missingStocks = collect($stocks)
                ->filter(fn (array $stock): bool => in_array($stock['code'], $missing, true))
                ->values()
                ->all();
            [$directQuotes, $directErrors] = $this->fetchOfficialMarketQuoteChunks(array_chunk($missingStocks, 20));
            foreach ($directQuotes as $code => $quote) {
                if (!isset($quotes[$code])) {
                    $quotes[$code] = $quote;
                }
            }
            $errors = [...$errors, ...collect($directErrors)
                ->mapWithKeys(fn (string $error, string $key): array => ['twse_retry_' . $key => $error])
                ->all()];
            $missing = array_values(array_diff($codes, array_keys($quotes)));
        }

        $labels = $twseCircuitOpen ? [] : ['證交所即時報價'];
        if ($fallbackProviders !== []) {
            $labels[] = implode(' + ', array_map(fn (string $provider): string => $this->providerLabel($provider), $fallbackProviders));
        }
        if (!$twseCircuitOpen && $quotes !== [] && count($errors) >= count($chunks)) {
            Cache::put(self::TWSE_MARKET_QUOTE_CIRCUIT_KEY, true, now()->addSeconds(90));
        }

        return [
            'servedAt' => $this->now()->toIso8601String(),
            'cacheSeconds' => $ttl,
            'source' => [
                'status' => $quotes === [] ? 'unavailable' : ($missing === [] ? 'live' : 'partial'),
                'label' => $labels === [] ? '即時報價' : implode(' / ', $labels),
                'errors' => $errors,
            ],
            'quotes' => $quotes,
            'missing' => $missing,
        ];
    }

    /**
     * @param list<list<array{code: string, exchange: string}>> $chunks
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>}
     */
    private function fetchOfficialMarketQuoteChunks(array $chunks): array
    {
        $quotes = [];
        $errors = [];
        foreach (array_chunk($chunks, 4, true) as $chunkGroup) {
            $responses = Http::pool(fn (Pool $pool): array => collect($chunkGroup)
                ->mapWithKeys(function (array $chunk, int|string $index) use ($pool): array {
                    $channels = collect($chunk)
                        ->map(fn (array $stock): string => sprintf(
                            '%s_%s.tw',
                            $stock['exchange'] === 'TWSE' ? 'tse' : 'otc',
                            $stock['code'],
                        ))
                        ->implode('|');

                    return [
                        (string) $index => $pool->as((string) $index)
                            ->withHeaders([
                                'Accept' => 'application/json,text/plain,*/*',
                                'Referer' => 'https://mis.twse.com.tw/stock/index.jsp',
                                'User-Agent' => 'Mozilla/5.0',
                            ])
                            ->timeout($this->timeout())
                            ->retry(1, 200)
                            ->get(self::TWSE_MIS_URL, [
                                'ex_ch' => $channels,
                                'json' => '1',
                                'delay' => '0',
                                '_' => (string) floor(microtime(true) * 1000),
                            ]),
                    ];
                })
                ->all());

            foreach ($chunkGroup as $index => $chunk) {
                try {
                    $response = $responses[(string) $index] ?? null;
                    if (!$response instanceof HttpResponse) {
                        throw $response instanceof Throwable
                            ? $response
                            : new \RuntimeException('TWSE MIS response is unavailable.');
                    }

                    $payload = $response->throw()->json();
                    $marketRows = is_array($payload) ? ($payload['msgArray'] ?? []) : [];
                    if (!is_array($marketRows)) {
                        continue;
                    }

                    foreach ($marketRows as $marketRow) {
                        if (!is_array($marketRow)) {
                            continue;
                        }

                        $quote = $this->twseRowToQuote($marketRow, allowPreviousClose: true);
                        $code = $this->normalizeCode((string) ($marketRow['c'] ?? ''));
                        if ($quote !== null && $code !== '') {
                            $quotes[$code] = $quote;
                        }
                    }
                } catch (Throwable $exception) {
                    $errors['twse_chunk_' . $index] = $exception->getMessage();
                }
            }

            if (count($chunkGroup) === 4) {
                usleep(120_000);
            }
        }

        return [$quotes, $errors];
    }

    /**
     * @param list<string> $codes
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, string>, 2: list<string>}
     */
    private function fetchMarketQuoteFallbacks(array $codes): array
    {
        $quotes = [];
        $errors = [];
        $providers = [];
        $marketProviders = count($codes) > 500 ? ['cnyes'] : ['cnyes', 'yahoo_tw', 'yahoo_tw_page'];
        foreach ($marketProviders as $provider) {
            $missing = array_values(array_diff($codes, array_keys($quotes)));
            if ($missing === []) {
                break;
            }

            try {
                $providerQuotes = $provider === 'cnyes'
                    ? $this->fetchCnyesMarketQuotes($missing)
                    : $this->fetchProviderQuotes($provider, $missing);
                foreach ($providerQuotes as $code => $quote) {
                    $code = $this->normalizeCode((string) $code);
                    $marketQuote = $this->providerQuoteToMarketQuote($quote, $provider);
                    if ($code !== '' && $marketQuote !== null) {
                        $quotes[$code] = $marketQuote;
                    }
                }
                if ($providerQuotes !== []) {
                    $providers[] = $provider;
                }
            } catch (Throwable $exception) {
                $errors['fallback_' . $provider] = $exception->getMessage();
            }
        }

        return [$quotes, $errors, array_values(array_unique($providers))];
    }

    /**
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchCnyesMarketQuotes(array $codes): array
    {
        $chunks = array_chunk($codes, 100);
        $quotes = [];
        foreach (array_chunk($chunks, 5, true) as $chunkGroup) {
            $responses = Http::pool(fn (Pool $pool): array => collect($chunkGroup)
                ->mapWithKeys(function (array $chunk, int|string $index) use ($pool): array {
                    $symbols = collect($chunk)
                        ->map(fn (string $code): string => 'TWS:' . $code . ':STOCK')
                        ->implode(',');

                    return [
                        (string) $index => $pool->as((string) $index)
                            ->withHeaders([
                                'Accept' => 'application/json,text/plain,*/*',
                                'Referer' => 'https://www.cnyes.com/',
                                'User-Agent' => 'Mozilla/5.0',
                            ])
                            ->connectTimeout(1)
                            ->timeout(2)
                            ->get(self::CNYES_QUOTES_URL . $symbols, ['column' => 'G']),
                    ];
                })
                ->all());

            foreach ($chunkGroup as $index => $chunk) {
                $response = $responses[(string) $index] ?? null;
                if (!$response instanceof HttpResponse) {
                    continue;
                }

                try {
                    $payload = $response->throw()->json();
                    $rows = is_array($payload) ? ($payload['data'] ?? []) : [];
                    if (is_array($rows)) {
                        foreach ($this->cnyesRowsToQuotes($rows) as $code => $quote) {
                            $quotes[(string) $code] = $quote;
                        }
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return $quotes;
    }

    /**
     * @param array<string, mixed> $quote
     * @return array<string, mixed>|null
     */
    private function providerQuoteToMarketQuote(array $quote, string $provider): ?array
    {
        $lastPrice = $this->priceOrNull($quote['lastPrice'] ?? $quote['price'] ?? null);
        $previousClose = $this->numberOrNull($quote['previousClose'] ?? null);
        if ($lastPrice === null || $previousClose === null || $previousClose <= 0) {
            return null;
        }
        $quotedAt = $quote['quotedAt'] ?? null;
        $quotedAtTime = $this->quoteTimestamp($quotedAt);
        if ($provider !== 'twse' && $quotedAtTime?->toDateString() !== $this->now()->toDateString()) {
            return null;
        }

        return [
            'code' => $this->normalizeCode((string) ($quote['code'] ?? '')),
            'name' => (string) ($quote['name'] ?? ''),
            'price' => $lastPrice,
            'priceType' => (string) ($quote['priceType'] ?? 'last'),
            'lastPrice' => $lastPrice,
            'previousClose' => $previousClose,
            'dayChange' => $lastPrice - $previousClose,
            'dayChangeRate' => ($lastPrice - $previousClose) / $previousClose * 100,
            'bestBid' => $this->numberOrNull($quote['bestBid'] ?? null),
            'bestAsk' => $this->numberOrNull($quote['bestAsk'] ?? null),
            'open' => $this->numberOrNull($quote['open'] ?? null),
            'high' => $this->numberOrNull($quote['high'] ?? null),
            'low' => $this->numberOrNull($quote['low'] ?? null),
            'volumeLots' => $this->numberOrNull($quote['volumeLots'] ?? null),
            'exchange' => (string) ($quote['exchange'] ?? ''),
            'quotedAt' => $quotedAt,
            'source' => $provider,
            'sourceLabel' => $this->providerLabel($provider),
        ];
    }

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
                $providerQuotes = $this->fetchProviderQuotes($provider, $missing);

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

        foreach ($codes as $code) {
            if (isset($quotes[$code])) {
                continue;
            }

            $provisional = $this->provisionalQuote($candidates[$code] ?? []);
            if ($provisional !== null) {
                $quotes[$code] = $provisional;
            }
        }

        $missing = array_values(array_diff($codes, array_keys($quotes)));
        $hasProvisional = collect($quotes)->contains(
            fn (array $quote): bool => ($quote['priceType'] ?? null) === 'provisional',
        );
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
                'status' => $quotes === [] ? 'unavailable' : ($missing === [] && !$hasProvisional ? 'live' : 'partial'),
                'providers' => $providers,
                'label' => $quotes === []
                    ? ($providers === [] ? '無可用報價源' : '未達雙來源一致')
                    : $this->confirmedSourceLabel($quotes),
                'message' => $this->sourceMessage($quotes, $missing),
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
    private function fetchProviderQuotes(string $provider, array $codes): array
    {
        $quotes = [];
        foreach (array_chunk($codes, $this->providerChunkSize($provider)) as $chunk) {
            $chunkQuotes = match ($provider) {
                'twse' => $this->fetchTwseQuotes($chunk),
                'cnyes' => $this->fetchCnyesQuotes($chunk),
                'yahoo_tw' => $this->fetchYahooTwQuotes($chunk),
                'yahoo_tw_page' => $this->fetchYahooTwPageQuotes($chunk),
                'tradingview' => $this->fetchTradingViewQuotes($chunk),
                'yahoo_chart' => $this->fetchYahooChartQuotes($chunk),
                default => [],
            };

            foreach ($chunkQuotes as $code => $quote) {
                $quotes[(string) $code] = $quote;
            }
        }

        return $quotes;
    }

    private function providerChunkSize(string $provider): int
    {
        return match ($provider) {
            'twse', 'yahoo_tw' => 25,
            'yahoo_tw_page' => 10,
            'cnyes', 'tradingview' => 40,
            'yahoo_chart' => 30,
            default => 30,
        };
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
    private function twseRowToQuote(array $row, bool $allowPreviousClose = false): ?array
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

        if ($allowPreviousClose && $price === null && $previousClose !== null && $previousClose > 0 && $this->twseTimestamp($row) !== null) {
            $price = $previousClose;
            $priceType = 'previous-close';
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

        return is_array($rows) ? $this->cnyesRowsToQuotes($rows) : [];
    }

    /**
     * @param list<mixed> $rows
     * @return array<string, array<string, mixed>>
     */
    private function cnyesRowsToQuotes(array $rows): array
    {
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
     * Yahoo Taiwan's quote page embeds a second current-day chart payload. Use
     * it only for symbols that still lack a confirmed quote after the batched
     * primary providers, so sparse or emerging stocks can validate both their
     * current price and previous close without trusting TradingView's baseline.
     *
     * @param list<string> $codes
     * @return array<string, array<string, mixed>>
     */
    private function fetchYahooTwPageQuotes(array $codes): array
    {
        $quotes = [];

        foreach (['TWO', 'TW'] as $suffix) {
            $pending = array_values(array_diff($codes, array_keys($quotes)));
            if ($pending === []) {
                break;
            }

            $responses = Http::pool(fn (Pool $pool): array => collect($pending)
                ->mapWithKeys(function (string $code) use ($pool, $suffix): array {
                    $key = $code . ':' . $suffix;

                    return [
                        $key => $pool->as($key)
                            ->withHeaders([
                                'Accept' => 'text/html,application/xhtml+xml',
                                'Referer' => 'https://tw.stock.yahoo.com/',
                                'User-Agent' => 'Mozilla/5.0',
                            ])
                            ->timeout($this->timeout())
                            ->get(sprintf(self::YAHOO_TW_QUOTE_URL, rawurlencode($code . '.' . $suffix))),
                    ];
                })
                ->all());

            foreach ($pending as $code) {
                $key = $code . ':' . $suffix;
                $response = $responses[$key] ?? null;
                if (!$response instanceof HttpResponse || !$response->successful()) {
                    continue;
                }

                $chart = $this->yahooTwPageChart($response->body(), $code . '.' . $suffix);
                $meta = is_array($chart['meta'] ?? null) ? $chart['meta'] : [];
                $price = $this->priceOrNull($meta['regularMarketPrice'] ?? null);
                if ($price === null) {
                    continue;
                }

                $previousClose = $this->numberOrNull(
                    $meta['previousClose'] ?? $meta['chartPreviousClose'] ?? null,
                );
                $change = $previousClose === null ? null : $price - $previousClose;

                $quotes[$code] = [
                    'code' => $code,
                    'name' => (string) ($meta['shortName'] ?? $meta['longName'] ?? ''),
                    'price' => $price,
                    'priceType' => 'last',
                    'lastPrice' => $price,
                    'previousClose' => $previousClose,
                    'dayChange' => $change,
                    'dayChangeRate' => $previousClose > 0 && $change !== null
                        ? $change / $previousClose * 100
                        : null,
                    'bestBid' => null,
                    'bestAsk' => null,
                    'open' => $this->numberOrNull($meta['regularMarketOpen'] ?? null),
                    'high' => $this->numberOrNull($meta['regularMarketDayHigh'] ?? null),
                    'low' => $this->numberOrNull($meta['regularMarketDayLow'] ?? null),
                    'volumeLots' => $this->volumeSharesToLots($meta['regularMarketVolume'] ?? null),
                    'exchange' => (string) ($meta['exchangeName'] ?? ''),
                    'quotedAt' => $this->yahooTimestamp($meta['regularMarketTime'] ?? null),
                    'source' => 'yahoo_tw_page',
                    'sourceLabel' => $this->providerLabel('yahoo_tw_page'),
                ];
            }
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
                // TradingView's scanner response does not include the quote timestamp.
                // Do not stamp it with the current request time: that makes a previous
                // trading day's close look fresher than timestamped primary quotes.
                'quotedAt' => null,
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
            ->take(120)
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

        $ordered = collect([...$primary, ...$fallback])
            ->unique()
            ->values()
            ->all();

        if ($ordered === []) {
            return ['twse', 'cnyes', 'yahoo_tw', 'yahoo_tw_page', 'tradingview', 'yahoo_chart'];
        }

        if (in_array('yahoo_tw', $primary, true) && !in_array('yahoo_tw_page', $ordered, true)) {
            array_splice($ordered, count($primary), 0, ['yahoo_tw_page']);
        }

        return $ordered;
    }

    /**
     * @return list<string>
     */
    private function configuredProviders(string $value): array
    {
        $allowed = ['twse', 'cnyes', 'yahoo_tw', 'yahoo_tw_page', 'tradingview', 'yahoo_chart'];

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
            'yahoo_tw_page' => 'Yahoo 台股頁面',
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

            if (!$this->canConfirmMatches($matches, $candidates)) {
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
                return $this->withConfirmedPreviousClose([
                    ...$base,
                    'confirmedBy' => $labels,
                    'confirmationCount' => count($matches),
                    'candidatePrices' => $this->summarizeCandidates($matches),
                ], $matches, $required);
            }

            return $this->withConfirmedPreviousClose([
                ...$base,
                'price' => (float) $priceKey,
                'priceType' => 'confirmed',
                'source' => 'confirmed',
                'sourceLabel' => implode(' + ', $labels),
                'confirmedBy' => $labels,
                'confirmationCount' => count($matches),
                'candidatePrices' => $this->summarizeCandidates($matches),
            ], $matches, $required);
        }

        $nearby = $this->nearbyConfirmedQuote($candidates, $required);
        if ($nearby !== null) {
            return $nearby;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>|null
     */
    private function provisionalQuote(array $candidates): ?array
    {
        $valid = collect($candidates)
            ->filter(fn (array $candidate): bool => $this->priceOrNull($candidate['price'] ?? null) !== null)
            ->sortByDesc(fn (array $candidate): int => $this->timestampScore($candidate['quotedAt'] ?? null))
            ->unique(fn (array $candidate): string => (string) ($candidate['source'] ?? ''))
            ->values()
            ->all();
        if ($valid === []) {
            return null;
        }

        $best = collect($valid)
            ->sortByDesc(fn (array $candidate): int => $this->provisionalCandidateScore($candidate))
            ->first() ?? $valid[0];
        $labels = collect($valid)
            ->map(fn (array $quote): string => (string) ($quote['sourceLabel'] ?? $quote['source'] ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $bestLabel = (string) ($best['sourceLabel'] ?? $best['source'] ?? '可用來源');

        return [
            ...$best,
            'price' => $this->priceOrNull($best['price'] ?? null) ?? 0.0,
            'priceType' => 'provisional',
            'source' => 'provisional',
            'sourceLabel' => '暫用報價：' . $bestLabel,
            'confirmedBy' => $labels,
            'confirmationCount' => count($valid),
            'candidatePrices' => $this->summarizeCandidates($valid),
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function provisionalCandidateScore(array $candidate): int
    {
        $completeness = 0;
        $completeness += $this->numberOrNull($candidate['previousClose'] ?? null) !== null ? 3 : 0;
        $completeness += $this->numberOrNull($candidate['dayChangeRate'] ?? null) !== null ? 2 : 0;
        $completeness += $this->timestampScore($candidate['quotedAt'] ?? null) > 0 ? 1 : 0;

        return $completeness * 100_000_000_000
            + $this->sourcePriorityScore((string) ($candidate['source'] ?? '')) * 1_000_000_000
            + $this->timestampScore($candidate['quotedAt'] ?? null);
    }

    private function sourcePriorityScore(string $source): int
    {
        return match ($source) {
            'yahoo_tw' => 50,
            'yahoo_tw_page' => 48,
            'cnyes' => 45,
            'twse' => 40,
            'tradingview' => 30,
            'yahoo_chart' => 25,
            default => 0,
        };
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>|null
     */
    private function nearbyConfirmedQuote(array $candidates, int $required): ?array
    {
        if ($required <= 1 || $this->quoteToleranceTicks() <= 0.0) {
            return null;
        }

        $valid = collect($candidates)
            ->filter(fn (array $candidate): bool => $this->priceOrNull($candidate['price'] ?? null) !== null)
            ->sortByDesc(fn (array $candidate): int => $this->timestampScore($candidate['quotedAt'] ?? null))
            ->unique(fn (array $candidate): string => (string) ($candidate['source'] ?? ''))
            ->values()
            ->all();
        if (count($valid) < $required) {
            return null;
        }

        $sorted = collect($valid)
            ->sortBy(fn (array $candidate): float => $this->priceOrNull($candidate['price'] ?? null) ?? 0.0)
            ->values()
            ->all();

        $best = null;
        $bestRange = null;
        $bestTimestamp = 0;
        $candidateCount = count($sorted);
        for ($start = 0; $start <= $candidateCount - $required; $start++) {
            $window = array_slice($sorted, $start, $required);
            $prices = array_map(
                fn (array $candidate): float => $this->priceOrNull($candidate['price'] ?? null) ?? 0.0,
                $window,
            );
            $range = max($prices) - min($prices);
            $allowedRange = max(array_map(fn (float $price): float => $this->priceTick($price), $prices))
                * $this->quoteToleranceTicks();
            if ($range - $allowedRange > 0.000001) {
                continue;
            }

            if (!$this->canConfirmMatches($window, $valid)) {
                continue;
            }

            $timestamp = max(array_map(
                fn (array $candidate): int => $this->timestampScore($candidate['quotedAt'] ?? null),
                $window,
            ));
            if (
                $best === null
                || $range < $bestRange - 0.000001
                || (abs($range - $bestRange) <= 0.000001 && $timestamp > $bestTimestamp)
            ) {
                $best = $window;
                $bestRange = $range;
                $bestTimestamp = $timestamp;
            }
        }

        if ($best === null || $bestRange === null) {
            return null;
        }

        $base = $this->latestCandidate($best);
        $labels = collect($best)
            ->map(fn (array $quote): string => (string) ($quote['sourceLabel'] ?? $quote['source'] ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->withConfirmedPreviousClose([
            ...$base,
            'price' => $this->priceOrNull($base['price'] ?? null) ?? 0.0,
            'priceType' => 'nearby-confirmed',
            'source' => 'nearby-confirmed',
            'sourceLabel' => '近似確認：' . implode(' + ', $labels),
            'confirmedBy' => $labels,
            'confirmationCount' => count($best),
            'confirmationRange' => $bestRange,
            'confirmationTickTolerance' => $this->quoteToleranceTicks(),
            'candidatePrices' => $this->summarizeCandidates($best),
        ], $best, $required);
    }

    public function intradayPrices(array $codes, ?string $targetDate = null): array
    {
        $codes = $this->normalizeCodes($codes);
        $ttl = 15;
        $now = $this->now();
        $timezone = (string) config('esun.timezone', 'Asia/Taipei');
        $requestDay = $now->startOfDay();
        if (is_string($targetDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate) === 1) {
            try {
                $candidate = CarbonImmutable::createFromFormat('!Y-m-d', $targetDate, $timezone);
                if ($candidate !== false && $candidate->toDateString() === $targetDate && $candidate->lessThanOrEqualTo($requestDay)) {
                    $requestDay = $candidate->startOfDay();
                }
            } catch (Throwable) {
                // Invalid dates fall back to the current Taipei calendar date.
            }
        }
        $date = $requestDay->toDateString();
        $requestUntil = $date === $now->toDateString() ? $now : $requestDay->endOfDay();

        if ($codes === []) {
            return [
                'servedAt' => $now->toIso8601String(),
                'date' => $date,
                'cacheSeconds' => $ttl,
                'source' => ['status' => 'empty', 'label' => '台股分時'],
                'series' => [],
                'missing' => [],
            ];
        }

        $series = [];
        $providerByCode = [];
        $uncached = [];
        foreach ($codes as $code) {
            $cached = Cache::get($this->intradayCacheKey($date, $code));
            if (is_array($cached) && isset($cached['points']) && is_array($cached['points'])) {
                $cachedPoints = $this->filterIntradayPointsByDate($cached['points'], $date, $timezone);
                if ($cachedPoints !== [] || $cached['points'] === []) {
                    $series[$code] = $cachedPoints;
                    $providerByCode[$code] = (string) ($cached['provider'] ?? 'cnyes');
                    continue;
                }
            }

            $uncached[] = $code;
        }

        $errors = [];
        $needsYahoo = [];
        if ($uncached !== []) {
            $responses = Http::pool(fn (Pool $pool): array => collect($uncached)
                ->mapWithKeys(fn (string $code): array => [
                    $code => $pool->as($code)
                        ->withHeaders([
                            'Accept' => 'application/json,text/plain,*/*',
                            'Referer' => 'https://invest.cnyes.com/',
                            'User-Agent' => 'Mozilla/5.0',
                        ])
                        ->timeout($this->timeout())
                        ->get(self::CNYES_HISTORY_URL, [
                            'resolution' => '1',
                            'symbol' => 'TWS:' . $code . ':STOCK',
                            'from' => (string) $requestDay->getTimestamp(),
                            'to' => (string) $requestUntil->getTimestamp(),
                            'quote' => '1',
                        ]),
                ])
                ->all());

            foreach ($uncached as $code) {
                try {
                    $response = $responses[$code] ?? null;
                    if (!$response instanceof HttpResponse) {
                        throw $response instanceof Throwable
                            ? $response
                            : new \RuntimeException('CNYES intraday response is unavailable.');
                    }

                    $payload = $response->throw()->json();
                    $points = $this->cnyesIntradayPoints(is_array($payload) ? $payload : [], $date, $timezone);
                    $series[$code] = $points;
                    $providerByCode[$code] = 'cnyes';
                    if (count($points) < 2) {
                        $needsYahoo[] = $code;
                    }
                } catch (Throwable $exception) {
                    $errors['cnyes:' . $code] = $exception->getMessage();
                    $needsYahoo[] = $code;
                }
            }

            if ($needsYahoo !== []) {
                $fallback = $this->fetchYahooTwIntradayFallback(
                    array_values(array_unique($needsYahoo)),
                    $date,
                    $timezone,
                );
                $errors = [...$errors, ...$fallback['errors']];
                foreach ($fallback['series'] as $code => $points) {
                    if (count($points) < 2) {
                        continue;
                    }

                    $series[$code] = $points;
                    $providerByCode[$code] = 'yahoo_tw_page';
                }
            }

            foreach ($uncached as $code) {
                Cache::put($this->intradayCacheKey($date, $code), [
                    'points' => $series[$code] ?? [],
                    'provider' => $providerByCode[$code] ?? 'unavailable',
                ], now()->addSeconds($ttl));
            }
        }

        $orderedSeries = [];
        foreach ($codes as $code) {
            if (isset($series[$code]) && $series[$code] !== []) {
                $orderedSeries[$code] = $series[$code];
            }
        }
        $missing = array_values(array_diff($codes, array_keys($orderedSeries)));
        $providers = collect(array_keys($orderedSeries))
            ->map(fn (string|int $code): string => $providerByCode[$code] ?? 'cnyes')
            ->unique()
            ->values()
            ->all();
        $sourceLabel = match (true) {
            $providers === ['yahoo_tw_page'] => 'Yahoo 台股分時',
            in_array('yahoo_tw_page', $providers, true) => 'CNYES + Yahoo 台股分時',
            default => 'CNYES 分時',
        };

        return [
            'servedAt' => $now->toIso8601String(),
            'date' => $date,
            'cacheSeconds' => $ttl,
            'source' => [
                'status' => $orderedSeries === [] ? 'unavailable' : ($missing === [] ? 'live' : 'partial'),
                'label' => $sourceLabel,
                'providers' => $providers,
                'errors' => $errors,
            ],
            'series' => $orderedSeries,
            'missing' => $missing,
        ];
    }

    private function intradayCacheKey(string $date, string $code): string
    {
        return "tw-stock:intraday:v3:{$date}:{$code}";
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function filterIntradayPointsByDate(array $points, string $date, string $timezone): array
    {
        $filtered = [];
        foreach ($points as $point) {
            $timestamp = is_numeric($point['time'] ?? null) ? (int) $point['time'] : 0;
            $price = $this->priceOrNull($point['price'] ?? null);
            if ($timestamp <= 0 || $price === null) {
                continue;
            }

            if (CarbonImmutable::createFromTimestamp($timestamp, $timezone)->toDateString() !== $date) {
                continue;
            }

            $filteredPoint = ['time' => $timestamp, 'price' => $price];
            foreach (['open', 'low', 'high'] as $rangeKey) {
                $rangeValue = $this->priceOrNull($point[$rangeKey] ?? null);
                if ($rangeValue !== null) {
                    $filteredPoint[$rangeKey] = $rangeValue;
                }
            }
            $volume = $this->numberOrNull($point['volume'] ?? null);
            if ($volume !== null && $volume >= 0) {
                $filteredPoint['volume'] = $volume;
            }
            $filtered[$timestamp] = $filteredPoint;
        }

        ksort($filtered, SORT_NUMERIC);

        return array_values($filtered);
    }

    /**
     * @param list<string> $codes
     * @return array{series: array<string, list<array{time: int, price: float}>>, errors: array<string, string>}
     */
    private function fetchYahooTwIntradayFallback(array $codes, string $date, string $timezone): array
    {
        $series = [];
        $errors = [];

        foreach (['TWO', 'TW'] as $suffix) {
            $pending = array_values(array_diff($codes, array_keys($series)));
            if ($pending === []) {
                break;
            }

            $responses = Http::pool(fn (Pool $pool): array => collect($pending)
                ->mapWithKeys(function (string $code) use ($pool, $suffix): array {
                    $key = $code . ':' . $suffix;

                    return [
                        $key => $pool->as($key)
                            ->withHeaders([
                                'Accept' => 'text/html,application/xhtml+xml',
                                'Referer' => 'https://tw.stock.yahoo.com/',
                                'User-Agent' => 'Mozilla/5.0',
                            ])
                            ->timeout($this->timeout())
                            ->get(sprintf(self::YAHOO_TW_QUOTE_URL, rawurlencode($code . '.' . $suffix))),
                    ];
                })
                ->all());

            foreach ($pending as $code) {
                $key = $code . ':' . $suffix;
                try {
                    $response = $responses[$key] ?? null;
                    if (!$response instanceof HttpResponse) {
                        throw $response instanceof Throwable
                            ? $response
                            : new \RuntimeException('Yahoo Taiwan quote page is unavailable.');
                    }

                    $points = $this->yahooTwPageIntradayPoints(
                        $response->throw()->body(),
                        $code . '.' . $suffix,
                        $date,
                        $timezone,
                    );
                    if ($points !== []) {
                        $series[$code] = $points;
                    }
                } catch (Throwable $exception) {
                    $errors['yahoo_tw:' . $key] = $exception->getMessage();
                }
            }
        }

        return ['series' => $series, 'errors' => $errors];
    }

    /**
     * @return list<array{time: int, price: float}>
     */
    private function yahooTwPageIntradayPoints(
        string $html,
        string $symbol,
        string $date,
        string $timezone,
    ): array {
        $chart = $this->yahooTwPageChart($html, $symbol);
        if ($chart === null) {
            return [];
        }

        return $this->intradayPointsFromArrays(
            $chart['timestamp'] ?? [],
            $chart['indicators']['quote'][0]['close'] ?? [],
            $date,
            $timezone,
            $chart['indicators']['quote'][0]['low'] ?? [],
            $chart['indicators']['quote'][0]['high'] ?? [],
            $chart['indicators']['quote'][0]['open'] ?? [],
            $chart['indicators']['quote'][0]['volume'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function yahooTwPageChart(string $html, string $symbol): ?array
    {
        $storePosition = strpos($html, '"MarketChartStore":');
        if ($storePosition === false) {
            return null;
        }

        $libraPosition = strpos($html, '"libra":{', $storePosition);
        $sparkPosition = $libraPosition === false ? false : strpos($html, ',"spark":', $libraPosition);
        if ($libraPosition === false || $sparkPosition === false) {
            return null;
        }

        $symbolPosition = strpos($html, '"' . $symbol . '":', $libraPosition);
        if ($symbolPosition === false || $symbolPosition >= $sparkPosition) {
            return null;
        }

        $objectStart = strpos($html, '{', $symbolPosition + strlen($symbol) + 3);
        if ($objectStart === false || $objectStart >= $sparkPosition) {
            return null;
        }

        $json = $this->extractJsonObject($html, $objectStart);
        $chart = $json === null ? null : json_decode($json, true);

        return is_array($chart) ? $chart : null;
    }

    private function extractJsonObject(string $source, int $start): ?string
    {
        if (($source[$start] ?? null) !== '{') {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($source);
        for ($index = $start; $index < $length; $index++) {
            $character = $source[$index];
            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($character === '"') {
                $inString = true;
            } elseif ($character === '{') {
                $depth++;
            } elseif ($character === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $index - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{time: int, price: float}>
     */
    private function cnyesIntradayPoints(array $payload, string $date, string $timezone): array
    {
        return $this->intradayPointsFromArrays(
            $payload['data']['t'] ?? [],
            $payload['data']['c'] ?? [],
            $date,
            $timezone,
            $payload['data']['l'] ?? [],
            $payload['data']['h'] ?? [],
            $payload['data']['o'] ?? [],
            $payload['data']['v'] ?? [],
        );
    }

    /**
     * @return list<array{time: int, price: float}>
     */
    private function intradayPointsFromArrays(
        mixed $timestamps,
        mixed $closes,
        string $date,
        string $timezone,
        mixed $lows = [],
        mixed $highs = [],
        mixed $opens = [],
        mixed $volumes = [],
    ): array {
        if (!is_array($timestamps) || !is_array($closes)) {
            return [];
        }

        $points = [];
        foreach ($timestamps as $index => $timestamp) {
            $timestamp = is_numeric($timestamp) ? (int) $timestamp : 0;
            $price = $this->priceOrNull($closes[$index] ?? null);
            if ($timestamp <= 0 || $price === null) {
                continue;
            }

            if (CarbonImmutable::createFromTimestamp($timestamp, $timezone)->toDateString() !== $date) {
                continue;
            }

            $point = ['time' => $timestamp, 'price' => $price];
            $open = is_array($opens) ? $this->priceOrNull($opens[$index] ?? null) : null;
            $low = is_array($lows) ? $this->priceOrNull($lows[$index] ?? null) : null;
            $high = is_array($highs) ? $this->priceOrNull($highs[$index] ?? null) : null;
            $volume = is_array($volumes) ? $this->numberOrNull($volumes[$index] ?? null) : null;
            if ($open !== null) {
                $point['open'] = $open;
            }
            if ($low !== null) {
                $point['low'] = $low;
            }
            if ($high !== null) {
                $point['high'] = $high;
            }
            if ($volume !== null && $volume >= 0) {
                $point['volume'] = $volume;
            }

            $points[$timestamp] = $point;
        }

        ksort($points, SORT_NUMERIC);

        return array_values($points);
    }

    /**
     * A fallback-only agreement must not override an available primary quote.
     *
     * TradingView and Yahoo chart can agree on the previous trading day's close
     * while CNYES or Yahoo Taiwan already has today's price. In that situation,
     * keeping the primary quote provisional is safer than promoting two stale
     * fallback values to a confirmed realtime price.
     *
     * @param list<array<string, mixed>> $matches
     * @param list<array<string, mixed>> $candidates
     */
    private function canConfirmMatches(array $matches, array $candidates): bool
    {
        $primaryProviders = $this->configuredProviders(
            (string) config('esun.quote_providers', 'twse,cnyes,yahoo_tw'),
        );
        if ($primaryProviders === []) {
            return true;
        }

        $matchesContainPrimary = collect($matches)->contains(
            fn (array $candidate): bool => in_array(
                (string) ($candidate['source'] ?? ''),
                $primaryProviders,
                true,
            ),
        );
        if ($matchesContainPrimary) {
            return true;
        }

        $hasPrimaryCandidate = collect($candidates)->contains(
            fn (array $candidate): bool => in_array(
                (string) ($candidate['source'] ?? ''),
                $primaryProviders,
                true,
            ),
        );

        return !$hasPrimaryCandidate;
    }

    /**
     * @param array<string, mixed> $quote
     * @param list<array<string, mixed>> $candidates
     * @return array<string, mixed>
     */
    private function withConfirmedPreviousClose(array $quote, array $candidates, int $required): array
    {
        if ($required <= 1) {
            return $quote;
        }

        $previousClose = $this->confirmedPreviousClose($candidates, $required);
        if ($previousClose === null) {
            return [
                ...$quote,
                'previousClose' => null,
                'dayChange' => null,
                'dayChangeRate' => null,
            ];
        }

        $price = $this->priceOrNull($quote['price'] ?? null);
        $dayChange = $price === null ? null : $price - $previousClose;

        return [
            ...$quote,
            'previousClose' => $previousClose,
            'dayChange' => $dayChange,
            'dayChangeRate' => $previousClose > 0 && $dayChange !== null ? $dayChange / $previousClose * 100 : null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     */
    private function confirmedPreviousClose(array $candidates, int $required): ?float
    {
        $decimals = max(0, (int) config('esun.quote_confirmation_decimals', 2));
        $groups = [];
        foreach ($candidates as $candidate) {
            $previousClose = $this->numberOrNull($candidate['previousClose'] ?? null);
            if ($previousClose === null) {
                continue;
            }

            $key = number_format(round($previousClose, $decimals), $decimals, '.', '');
            $source = (string) ($candidate['source'] ?? '');
            $alreadyCounted = collect($groups[$key] ?? [])
                ->contains(fn (array $item): bool => (string) ($item['source'] ?? '') === $source);

            if (!$alreadyCounted) {
                $groups[$key][] = $candidate;
            }
        }

        foreach ($groups as $previousCloseKey => $matches) {
            if (count($matches) >= $required) {
                return (float) $previousCloseKey;
            }
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

        if (collect($quotes)->contains(fn (array $quote): bool => ($quote['priceType'] ?? null) === 'provisional')) {
            return $labels === [] ? '多來源暫用報價' : '多來源暫用：' . implode(' + ', $labels);
        }

        $prefix = collect($quotes)->contains(
            fn (array $quote): bool => ($quote['priceType'] ?? null) === 'nearby-confirmed',
        )
            ? '近似雙來源確認：'
            : '雙來源確認：';

        return $labels === [] ? '雙來源確認報價' : $prefix . implode(' + ', $labels);
    }

    /**
     * @param array<string, array<string, mixed>> $quotes
     * @param list<string> $missing
     */
    private function sourceMessage(array $quotes, array $missing): string
    {
        if ($quotes === []) {
            return '目前沒有任何來源提供可用即時報價。';
        }

        $hasProvisional = collect($quotes)->contains(
            fn (array $quote): bool => ($quote['priceType'] ?? null) === 'provisional',
        );

        if ($missing !== []) {
            return $hasProvisional
                ? '部分庫存未達雙來源一致，已使用多來源暫用報價；仍有少數代號沒有可用報價。'
                : '部分庫存報價尚未有兩個來源同價，已保留上一筆確認價格。';
        }

        return $hasProvisional
            ? '部分庫存未達雙來源一致，已改用多來源暫用報價避免空白。'
            : '庫存報價已通過雙來源一致性驗證。';
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

    private function quoteToleranceTicks(): float
    {
        return max(0.0, (float) config('esun.quote_confirmation_tick_tolerance', 1));
    }

    private function priceTick(float $price): float
    {
        return match (true) {
            $price >= 1000 => 5.0,
            $price >= 500 => 1.0,
            $price >= 100 => 0.5,
            $price >= 50 => 0.1,
            $price >= 10 => 0.05,
            default => 0.01,
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

    private function quoteTimestamp(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone((string) config('esun.timezone', 'Asia/Taipei'));
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
