<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class TwStockInstitutionalFlowFetcher
{
    private const TWSE_URL = 'https://www.twse.com.tw/fund/BFI82U';

    private const TAIEX_URL = 'https://www.twse.com.tw/rwd/zh/TAIEX/MI_5MINS_HIST';

    private const TAIFEX_URL = 'https://www.taifex.com.tw/cht/3/futContractsDate';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $taiexMonthCache = [];

    /**
     * @return array<string, mixed>|null
     */
    public function fetchDate(CarbonInterface $date): ?array
    {
        $twse = $this->fetchTwseInstitutionalFlows($date);
        if ($twse === null) {
            return null;
        }

        $taifex = $this->fetchTaifexTxfFlows($date);
        $taiex = $this->fetchTaiexDailyIndex($date);

        return [
            'trade_date' => $date->toDateString(),
            'foreign_stock_buy_amount' => $twse['foreign']['buy_amount'],
            'foreign_stock_sell_amount' => $twse['foreign']['sell_amount'],
            'foreign_stock_net_amount' => $twse['foreign']['net_amount'],
            'investment_trust_stock_buy_amount' => $twse['investment_trust']['buy_amount'],
            'investment_trust_stock_sell_amount' => $twse['investment_trust']['sell_amount'],
            'investment_trust_stock_net_amount' => $twse['investment_trust']['net_amount'],
            'foreign_txf_trade_net_contracts' => $taifex['foreign']['trade_net_contracts'] ?? null,
            'investment_trust_txf_trade_net_contracts' => $taifex['investment_trust']['trade_net_contracts'] ?? null,
            'foreign_txf_open_interest_long_contracts' => $taifex['foreign']['open_interest_long_contracts'] ?? null,
            'foreign_txf_open_interest_short_contracts' => $taifex['foreign']['open_interest_short_contracts'] ?? null,
            'foreign_txf_open_interest_net_contracts' => $taifex['foreign']['open_interest_net_contracts'] ?? null,
            'investment_trust_txf_open_interest_long_contracts' => $taifex['investment_trust']['open_interest_long_contracts'] ?? null,
            'investment_trust_txf_open_interest_short_contracts' => $taifex['investment_trust']['open_interest_short_contracts'] ?? null,
            'investment_trust_txf_open_interest_net_contracts' => $taifex['investment_trust']['open_interest_net_contracts'] ?? null,
            'taiex_open_index' => $taiex['open_index'] ?? null,
            'taiex_high_index' => $taiex['high_index'] ?? null,
            'taiex_low_index' => $taiex['low_index'] ?? null,
            'taiex_close_index' => $taiex['close_index'] ?? null,
            'taiex_source_title' => $taiex['title'] ?? null,
            'twse_source_title' => $twse['title'] ?? null,
            'twse_payload' => $twse['payload'],
            'taifex_payload' => $taifex['payload'],
            'taiex_payload' => $taiex['payload'] ?? null,
            'fetched_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTwseInstitutionalFlows(CarbonInterface $date): ?array
    {
        $response = $this->http()
            ->get(self::TWSE_URL, [
                'response' => 'json',
                'dayDate' => $date->format('Ymd'),
                'type' => 'day',
            ])
            ->throw()
            ->json();

        if (!is_array($response) || ($response['stat'] ?? null) !== 'OK') {
            return null;
        }

        $foreign = null;
        $investmentTrust = null;

        foreach (($response['data'] ?? []) as $row) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }

            $name = (string) $row[0];
            $parsed = [
                'buy_amount' => $this->parseInteger((string) $row[1]),
                'sell_amount' => $this->parseInteger((string) $row[2]),
                'net_amount' => $this->parseInteger((string) $row[3]),
            ];

            if (Str::startsWith($name, '外資及陸資')) {
                $foreign = $parsed;
                continue;
            }

            if ($name === '投信') {
                $investmentTrust = $parsed;
            }
        }

        if ($foreign === null || $investmentTrust === null) {
            throw new RuntimeException('TWSE 回應缺少外資或投信資料列。');
        }

        return [
            'title' => $response['title'] ?? null,
            'foreign' => $foreign,
            'investment_trust' => $investmentTrust,
            'payload' => [
                'stat' => $response['stat'] ?? null,
                'date' => $response['date'] ?? null,
                'title' => $response['title'] ?? null,
                'fields' => $response['fields'] ?? null,
                'data' => $response['data'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTaifexTxfFlows(CarbonInterface $date): array
    {
        $queryDate = $date->format('Y/m/d');
        $html = $this->http()
            ->get(self::TAIFEX_URL, [
                'doQuery' => '1',
                'queryDate' => $queryDate,
                'queryType' => '1',
            ])
            ->throw()
            ->body();

        if (str_contains($html, '查無資料')) {
            return [
                'foreign' => null,
                'investment_trust' => null,
                'payload' => [
                    'status' => '查無資料',
                    'query_date' => $queryDate,
                ],
            ];
        }

        $rows = $this->parseTaifexRows($html);
        $foreign = $rows['外資'] ?? null;
        $investmentTrust = $rows['投信'] ?? null;

        return [
            'foreign' => $foreign,
            'investment_trust' => $investmentTrust,
            'payload' => [
                'status' => 'OK',
                'query_date' => $queryDate,
                'product' => '臺股期貨',
                'rows' => [
                    'foreign' => $foreign,
                    'investment_trust' => $investmentTrust,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTaiexDailyIndex(CarbonInterface $date): ?array
    {
        $monthKey = $date->format('Ym');

        if (!array_key_exists($monthKey, $this->taiexMonthCache)) {
            $this->taiexMonthCache[$monthKey] = $this->fetchTaiexMonthIndexes($date);
        }

        return $this->taiexMonthCache[$monthKey]['by_date'][$date->toDateString()] ?? null;
    }

    /**
     * @return array{title: string|null, by_date: array<string, array<string, mixed>>}
     */
    private function fetchTaiexMonthIndexes(CarbonInterface $date): array
    {
        $month = CarbonImmutable::parse($date->toDateString());
        $targetMonth = $month->format('Y-m');
        $queryDates = array_values(array_unique([
            $month->format('Ymd'),
            $month->startOfMonth()->format('Ymd'),
            $month->day(15)->format('Ymd'),
            $month->endOfMonth()->format('Ymd'),
        ]));

        foreach ($queryDates as $queryDate) {
            $response = $this->http()
                ->get(self::TAIEX_URL, [
                    'response' => 'json',
                    'date' => $queryDate,
                ])
                ->throw()
                ->json();

            if (!is_array($response) || ($response['stat'] ?? null) !== 'OK') {
                continue;
            }

            $title = isset($response['title']) ? (string) $response['title'] : null;
            $byDate = [];

            foreach (($response['data'] ?? []) as $row) {
                if (!is_array($row) || count($row) < 5) {
                    continue;
                }

                $tradeDate = $this->parseRocDate((string) $row[0]);
                if ($tradeDate === null || !str_starts_with($tradeDate, $targetMonth)) {
                    continue;
                }

                $byDate[$tradeDate] = [
                    'title' => $title,
                    'open_index' => $this->parseDecimal((string) $row[1]),
                    'high_index' => $this->parseDecimal((string) $row[2]),
                    'low_index' => $this->parseDecimal((string) $row[3]),
                    'close_index' => $this->parseDecimal((string) $row[4]),
                    'payload' => [
                        'status' => 'OK',
                        'title' => $title,
                        'fields' => $response['fields'] ?? null,
                        'row' => $row,
                    ],
                ];
            }

            if ($byDate !== []) {
                return [
                    'title' => $title,
                    'by_date' => $byDate,
                ];
            }
        }

        return [
            'title' => null,
            'by_date' => [],
        ];
    }

    /**
     * @return array<string, array<string, int|null>>
     */
    private function parseTaifexRows(string $html): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $currentProduct = null;
        $result = [];

        foreach ($dom->getElementsByTagName('tr') as $tr) {
            $cells = $this->extractCellTexts($tr);
            if ($cells === []) {
                continue;
            }

            $identity = null;
            $numbers = [];

            if (preg_match('/^\d+$/', $cells[0]) === 1 && count($cells) >= 4) {
                $currentProduct = $cells[1];
                $identity = $cells[2];
                $numbers = array_slice($cells, 3);
            } elseif ($currentProduct !== null && count($cells) >= 2) {
                $identity = $cells[0];
                $numbers = array_slice($cells, 1);
            }

            if (!in_array($currentProduct, ['臺股期貨', '台股期貨'], true)) {
                continue;
            }

            if (!in_array($identity, ['外資', '投信'], true)) {
                continue;
            }

            if (count($numbers) < 11) {
                throw new RuntimeException('TAIFEX 臺股期貨 ' . $identity . ' 欄位不足。');
            }

            $result[$identity] = [
                'trade_long_contracts' => $this->parseInteger($numbers[0]),
                'trade_short_contracts' => $this->parseInteger($numbers[2]),
                'trade_net_contracts' => $this->parseInteger($numbers[4]),
                'open_interest_long_contracts' => $this->parseInteger($numbers[6]),
                'open_interest_short_contracts' => $this->parseInteger($numbers[8]),
                'open_interest_net_contracts' => $this->parseInteger($numbers[10]),
            ];
        }

        if (!array_key_exists('外資', $result) && !array_key_exists('投信', $result)) {
            throw new RuntimeException('TAIFEX 回應找不到臺股期貨外資/投信列。');
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function extractCellTexts(DOMElement $tr): array
    {
        $cells = [];

        foreach ($tr->childNodes as $node) {
            if (!$node instanceof DOMElement || !in_array(strtolower($node->tagName), ['td', 'th'], true)) {
                continue;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if ($text !== '') {
                $cells[] = $text;
            }
        }

        return $cells;
    }

    private function parseInteger(string $value): ?int
    {
        $normalized = str_replace([',', "\xc2\xa0", ' '], '', trim($value));

        if ($normalized === '' || $normalized === '-' || $normalized === '－') {
            return null;
        }

        return (int) $normalized;
    }

    private function parseDecimal(string $value): ?string
    {
        $normalized = str_replace([',', "\xc2\xa0", ' '], '', trim($value));

        if ($normalized === '' || $normalized === '-' || $normalized === '－') {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function parseRocDate(string $value): ?string
    {
        $parts = explode('/', trim($value));
        if (count($parts) !== 3) {
            return null;
        }

        $year = (int) $parts[0] + 1911;
        $month = (int) $parts[1];
        $day = (int) $parts[2];

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/json,text/html,application/xhtml+xml',
        ])
            ->timeout(30)
            ->retry(2, 500);
    }
}
