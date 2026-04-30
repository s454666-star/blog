<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TwStockUpcomingDividendFetcher
{
    private const TWSE_UPCOMING_DIVIDENDS_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/TWT48U_ALL';

    private const TPEX_UPCOMING_DIVIDENDS_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_exright_prepost';

    private const TWSE_DAILY_PRICE_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL';

    private const TPEX_DAILY_PRICE_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes';

    private const WINVEST_UPCOMING_DIVIDENDS_URL = 'https://winvest.tw/Stock/Dividend';

    private const FINMIND_URL = 'https://api.finmindtrade.com/api/v4/data';

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $lastFillCache = [];

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private array $priceChangeCache = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $finMindCache = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(CarbonInterface $asOf, int $days = 30, bool $includeFunds = false): array
    {
        $startDate = CarbonImmutable::parse($asOf->toDateString());
        $endDate = $startDate->addDays(max(0, $days));
        $prices = $this->fetchLatestPrices();
        $events = $this->fetchUpcomingDividendEvents($startDate, $endDate, $includeFunds, $this->buildExchangeByCode($prices));
        $now = now();

        return array_map(function (array $event) use ($prices, $startDate, $now): array {
            $price = $prices[$event['exchange']][$event['stock_code']] ?? null;
            $latestClose = $price['close'] ?? $event['latest_close_price'] ?? null;
            $yield = $latestClose !== null && (float) $latestClose > 0.0
                ? round(((float) $event['cash_dividend'] / (float) $latestClose) * 100, 4)
                : null;
            $lastFill = $this->fetchLastFillStats($event['stock_code'], $event['ex_dividend_date'], $startDate);
            $priceChange = $this->fetchTwentyDayPriceChange($event['stock_code'], $startDate);

            return [
                'exchange' => $event['exchange'],
                'stock_code' => $event['stock_code'],
                'stock_name' => $event['stock_name'],
                'security_type' => $event['security_type'],
                'ex_dividend_date' => $event['ex_dividend_date'],
                'ex_dividend_type' => $event['ex_dividend_type'],
                'cash_dividend' => $event['cash_dividend'],
                'stock_dividend' => $event['stock_dividend'],
                'latest_close_price' => $latestClose,
                'latest_price_date' => $price['date'] ?? null,
                'price_20_days_ago' => $priceChange['price_20_days_ago'] ?? null,
                'price_20_days_ago_date' => $priceChange['price_20_days_ago_date'] ?? null,
                'price_change_20_days_percent' => $priceChange['price_change_20_days_percent'] ?? null,
                'dividend_yield_percent' => $yield,
                'days_until_ex_dividend' => (int) $startDate->diffInDays(CarbonImmutable::parse($event['ex_dividend_date'])),
                'last_ex_dividend_date' => $lastFill['last_ex_dividend_date'] ?? null,
                'last_ex_dividend_cash_dividend' => $lastFill['last_ex_dividend_cash_dividend'] ?? null,
                'last_ex_dividend_before_price' => $lastFill['last_ex_dividend_before_price'] ?? null,
                'last_fill_date' => $lastFill['last_fill_date'] ?? null,
                'last_fill_days' => $lastFill['last_fill_days'] ?? null,
                'last_fill_status' => $lastFill['last_fill_status'] ?? 'no_history',
                'source_payload' => $event['source_payload'],
                'fetched_at' => $now,
            ];
        }, $events);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchUpcomingDividendEvents(CarbonImmutable $startDate, CarbonImmutable $endDate, bool $includeFunds, array $exchangeByCode): array
    {
        $events = [
            ...$this->fetchTwseUpcomingDividendEvents(),
            ...$this->fetchTpexUpcomingDividendEvents(),
            ...$this->fetchWinvestUpcomingDividendEvents($exchangeByCode),
        ];

        $filtered = array_filter($events, function (array $event) use ($startDate, $endDate, $includeFunds): bool {
            $date = CarbonImmutable::parse($event['ex_dividend_date']);

            return $date->betweenIncluded($startDate, $endDate)
                && (str_contains($event['ex_dividend_type'], '息') || str_contains($event['ex_dividend_type'], '權'))
                && ((float) $event['cash_dividend'] > 0.0 || (float) $event['stock_dividend'] > 0.0)
                && ($includeFunds || $this->isCommonStockCode($event['stock_code']));
        });

        usort($filtered, fn (array $left, array $right): int => [
            $left['ex_dividend_date'],
            $left['stock_code'],
            $left['source_priority'],
        ] <=> [
            $right['ex_dividend_date'],
            $right['stock_code'],
            $right['source_priority'],
        ]);

        $deduped = [];
        foreach ($filtered as $event) {
            $dedupeKey = $event['stock_code'] . ':' . $event['ex_dividend_date'];
            if (!isset($deduped[$dedupeKey])) {
                $deduped[$dedupeKey] = $event;
            }
        }

        return array_values($deduped);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTwseUpcomingDividendEvents(): array
    {
        $rows = $this->http()
            ->get(self::TWSE_UPCOMING_DIVIDENDS_URL)
            ->throw()
            ->json();

        if (!is_array($rows)) {
            return [];
        }

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = $this->parseRocDate((string) ($row['Date'] ?? ''));
            if ($date === null) {
                continue;
            }

            $events[] = [
                'exchange' => 'TWSE',
                'stock_code' => trim((string) ($row['Code'] ?? '')),
                'stock_name' => trim((string) ($row['Name'] ?? '')),
                'security_type' => $this->securityType((string) ($row['Code'] ?? '')),
                'ex_dividend_date' => $date,
                'ex_dividend_type' => trim((string) ($row['Exdividend'] ?? '')),
                'cash_dividend' => $this->parseDecimal($row['CashDividend'] ?? null) ?? '0.000000',
                'stock_dividend' => $this->parseStockDividendAmount($row['StockDividendRatio'] ?? null),
                'source_payload' => ['source' => 'TWSE TWT48U_ALL', 'row' => $row],
                'source_priority' => 10,
            ];
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTpexUpcomingDividendEvents(): array
    {
        $rows = $this->http()
            ->get(self::TPEX_UPCOMING_DIVIDENDS_URL)
            ->throw()
            ->json();

        if (!is_array($rows)) {
            return [];
        }

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = $this->parseRocDate((string) ($row['ExRrightsExDividendDate'] ?? ''));
            if ($date === null) {
                continue;
            }

            $code = trim((string) ($row['SecuritiesCompanyCode'] ?? ''));
            $events[] = [
                'exchange' => 'TPEx',
                'stock_code' => $code,
                'stock_name' => trim((string) ($row['CompanyName'] ?? '')),
                'security_type' => $this->securityType($code),
                'ex_dividend_date' => $date,
                'ex_dividend_type' => trim((string) ($row['ExRrightsExDividend'] ?? '')),
                'cash_dividend' => $this->parseDecimal($row['CashDividend'] ?? null) ?? '0.000000',
                'stock_dividend' => $this->parseStockDividendAmount($row['StockDividendRatio'] ?? null),
                'source_payload' => ['source' => 'TPEx tpex_exright_prepost', 'row' => $row],
                'source_priority' => 10,
            ];
        }

        return $events;
    }

    /**
     * @param array<string, string> $exchangeByCode
     * @return list<array<string, mixed>>
     */
    private function fetchWinvestUpcomingDividendEvents(array $exchangeByCode): array
    {
        $html = $this->http()
            ->get(self::WINVEST_UPCOMING_DIVIDENDS_URL)
            ->throw()
            ->body();

        $previous = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $events = [];
        foreach ($xpath->query('//tr') ?: [] as $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length < 5) {
                continue;
            }

            $date = $this->parseGregorianDate($this->nodeText($cells->item(0)));
            $stock = $this->parseWinvestStockCell($cells->item(1));
            if ($date === null || $stock === null) {
                continue;
            }

            $cashDividend = $this->parseDecimal($this->nodeText($cells->item(3))) ?? '0.000000';
            $stockDividend = $cells->length >= 7
                ? ($this->parseDecimal($this->nodeText($cells->item(6))) ?? '0.000000')
                : '0.000000';

            $events[] = [
                'exchange' => $exchangeByCode[$stock['code']] ?? 'TWSE',
                'stock_code' => $stock['code'],
                'stock_name' => $stock['name'],
                'security_type' => $this->securityType($stock['code']),
                'ex_dividend_date' => $date,
                'ex_dividend_type' => $this->dividendType($cashDividend, $stockDividend),
                'cash_dividend' => $cashDividend,
                'stock_dividend' => $stockDividend,
                'latest_close_price' => $this->parseDecimal($this->nodeText($cells->item(2))),
                'source_payload' => [
                    'source' => 'Winvest Stock Dividend',
                    'cash_dividend_payment_date' => $cells->length >= 6 ? $this->nodeText($cells->item(5)) : null,
                    'row_text' => $this->nodeText($row),
                ],
                'source_priority' => 20,
            ];
        }

        return $events;
    }

    /**
     * @return array{TWSE: array<string, array<string, mixed>>, TPEx: array<string, array<string, mixed>>}
     */
    private function fetchLatestPrices(): array
    {
        return [
            'TWSE' => $this->fetchTwseLatestPrices(),
            'TPEx' => $this->fetchTpexLatestPrices(),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchTwseLatestPrices(): array
    {
        $rows = $this->http()
            ->get(self::TWSE_DAILY_PRICE_URL)
            ->throw()
            ->json();

        if (!is_array($rows)) {
            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['Code'] ?? ''));
            $date = $this->parseRocDate((string) ($row['Date'] ?? ''));
            $close = $this->parseDecimal($row['ClosingPrice'] ?? null);
            if ($code === '' || $date === null || $close === null) {
                continue;
            }

            $prices[$code] = [
                'date' => $date,
                'close' => $close,
            ];
        }

        return $prices;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchTpexLatestPrices(): array
    {
        $rows = $this->http()
            ->get(self::TPEX_DAILY_PRICE_URL)
            ->throw()
            ->json();

        if (!is_array($rows)) {
            return [];
        }

        $prices = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['SecuritiesCompanyCode'] ?? ''));
            $date = $this->parseRocDate((string) ($row['Date'] ?? ''));
            $close = $this->parseDecimal($row['Close'] ?? null);
            if ($code === '' || $date === null || $close === null) {
                continue;
            }

            $prices[$code] = [
                'date' => $date,
                'close' => $close,
            ];
        }

        return $prices;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTwentyDayPriceChange(string $stockCode, CarbonImmutable $asOf): ?array
    {
        $cacheKey = $stockCode . ':' . $asOf->toDateString();
        if (array_key_exists($cacheKey, $this->priceChangeCache)) {
            return $this->priceChangeCache[$cacheKey];
        }

        $targetDate = $asOf->subDays(20)->toDateString();
        $priceRows = collect($this->fetchFinMindData([
            'dataset' => 'TaiwanStockPrice',
            'data_id' => $stockCode,
            'start_date' => $asOf->subDays(45)->toDateString(),
            'end_date' => $asOf->toDateString(),
        ]))
            ->filter(fn (array $row): bool => isset($row['date']) && (float) ($row['close'] ?? 0) > 0.0)
            ->sortBy('date')
            ->values();

        if ($priceRows->count() < 2) {
            return $this->priceChangeCache[$cacheKey] = null;
        }

        $latest = $priceRows->last();
        $baseline = $priceRows->first(fn (array $row): bool => (string) $row['date'] >= $targetDate);
        if (!is_array($latest) || !is_array($baseline) || (string) $latest['date'] === (string) $baseline['date']) {
            return $this->priceChangeCache[$cacheKey] = null;
        }

        $baselineClose = (float) $baseline['close'];
        $latestClose = (float) $latest['close'];
        if ($baselineClose <= 0.0) {
            return $this->priceChangeCache[$cacheKey] = null;
        }

        return $this->priceChangeCache[$cacheKey] = [
            'price_20_days_ago' => $this->parseDecimal($baselineClose),
            'price_20_days_ago_date' => (string) $baseline['date'],
            'price_change_20_days_percent' => round((($latestClose - $baselineClose) / $baselineClose) * 100, 4),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLastFillStats(string $stockCode, string $nextExDividendDate, CarbonImmutable $asOf): ?array
    {
        $cacheKey = $stockCode . ':' . $nextExDividendDate . ':' . $asOf->toDateString();
        if (array_key_exists($cacheKey, $this->lastFillCache)) {
            return $this->lastFillCache[$cacheKey];
        }

        $nextDate = CarbonImmutable::parse($nextExDividendDate);
        $dividendRows = $this->fetchFinMindData([
            'dataset' => 'TaiwanStockDividendResult',
            'data_id' => $stockCode,
            'start_date' => $nextDate->subYears(10)->toDateString(),
            'end_date' => $nextDate->subDay()->toDateString(),
        ]);

        $lastDividend = collect($dividendRows)
            ->filter(function (array $row) use ($nextExDividendDate): bool {
                return isset($row['date'])
                    && (string) $row['date'] < $nextExDividendDate
                    && str_contains((string) ($row['stock_or_cache_dividend'] ?? ''), '息')
                    && (float) ($row['stock_and_cache_dividend'] ?? 0) > 0.0
                    && (float) ($row['before_price'] ?? 0) > 0.0;
            })
            ->sortByDesc('date')
            ->first();

        if (!is_array($lastDividend)) {
            return $this->lastFillCache[$cacheKey] = [
                'last_fill_status' => 'no_history',
            ];
        }

        $lastDate = (string) $lastDividend['date'];
        $beforePrice = (float) $lastDividend['before_price'];
        $priceRows = $this->fetchFinMindData([
            'dataset' => 'TaiwanStockPrice',
            'data_id' => $stockCode,
            'start_date' => $lastDate,
            'end_date' => $asOf->toDateString(),
        ]);

        $fillDate = null;
        $fillDays = null;
        foreach (array_values($priceRows) as $index => $row) {
            if ((float) ($row['close'] ?? 0) >= $beforePrice) {
                $fillDate = (string) $row['date'];
                $fillDays = $index + 1;
                break;
            }
        }

        return $this->lastFillCache[$cacheKey] = [
            'last_ex_dividend_date' => $lastDate,
            'last_ex_dividend_cash_dividend' => $this->parseDecimal($lastDividend['stock_and_cache_dividend'] ?? null),
            'last_ex_dividend_before_price' => $this->parseDecimal($lastDividend['before_price'] ?? null),
            'last_fill_date' => $fillDate,
            'last_fill_days' => $fillDays,
            'last_fill_status' => $fillDate !== null ? 'filled' : 'unfilled',
        ];
    }

    /**
     * @param array<string, string> $query
     * @return list<array<string, mixed>>
     */
    private function fetchFinMindData(array $query): array
    {
        $cacheKey = md5(json_encode($query, JSON_THROW_ON_ERROR));
        if (isset($this->finMindCache[$cacheKey])) {
            return $this->finMindCache[$cacheKey];
        }

        $response = $this->http()
            ->get(self::FINMIND_URL, $query)
            ->throw()
            ->json();

        if (!is_array($response) || (int) ($response['status'] ?? 0) !== 200 || !is_array($response['data'] ?? null)) {
            return $this->finMindCache[$cacheKey] = [];
        }

        return $this->finMindCache[$cacheKey] = array_values(array_filter($response['data'], 'is_array'));
    }

    /**
     * @param array{TWSE: array<string, array<string, mixed>>, TPEx: array<string, array<string, mixed>>} $prices
     * @return array<string, string>
     */
    private function buildExchangeByCode(array $prices): array
    {
        $exchangeByCode = [];
        foreach ($prices as $exchange => $rows) {
            foreach (array_keys($rows) as $stockCode) {
                $exchangeByCode[$stockCode] = $exchange;
            }
        }

        return $exchangeByCode;
    }

    private function isCommonStockCode(string $stockCode): bool
    {
        return preg_match('/^\d{4}$/', $stockCode) === 1;
    }

    private function securityType(string $stockCode): string
    {
        return $this->isCommonStockCode($stockCode) ? 'stock' : 'fund';
    }

    private function parseRocDate(string $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim($value)) ?? '';
        if (strlen($normalized) !== 7) {
            return null;
        }

        $year = (int) substr($normalized, 0, 3) + 1911;
        $month = (int) substr($normalized, 3, 2);
        $day = (int) substr($normalized, 5, 2);

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseGregorianDate(string $value): ?string
    {
        if (!preg_match('/(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})/', trim($value), $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseDecimal(mixed $value): ?string
    {
        $normalized = str_replace([',', "\xc2\xa0", ' '], '', trim((string) $value));

        if ($normalized === '' || $normalized === '-' || $normalized === '－') {
            return null;
        }

        return number_format((float) $normalized, 6, '.', '');
    }

    private function parseStockDividendAmount(mixed $ratio): string
    {
        $stockDividendRatio = $this->parseDecimal($ratio);
        if ($stockDividendRatio === null) {
            return '0.000000';
        }

        return number_format((float) $stockDividendRatio * 10, 6, '.', '');
    }

    /**
     * @return array{code: string, name: string}|null
     */
    private function parseWinvestStockCell(?\DOMNode $cell): ?array
    {
        if ($cell === null) {
            return null;
        }

        $text = $this->nodeText($cell);
        if (!preg_match('/^(.+)\s+\(([0-9A-Z]+)\)$/u', $text, $matches)) {
            return null;
        }

        return [
            'code' => trim($matches[2]),
            'name' => trim($matches[1]),
        ];
    }

    private function dividendType(string $cashDividend, string $stockDividend): string
    {
        $hasCash = (float) $cashDividend > 0.0;
        $hasStock = (float) $stockDividend > 0.0;

        return match (true) {
            $hasCash && $hasStock => '除權息',
            $hasCash => '除息',
            default => '除權',
        };
    }

    private function nodeText(?\DOMNode $node): string
    {
        if ($node === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/json,text/csv,text/html',
        ])
            ->timeout(30)
            ->retry(2, 500);
    }
}
