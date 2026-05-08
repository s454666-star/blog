<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockQ1FinancialReportFetcher
{
    private const TWSE_DAILY_PRICE_URL = 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL';

    private const TPEX_DAILY_PRICE_URL = 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes';

    private const TWSE_STOCK_DAY_MONTH_URL = 'https://www.twse.com.tw/rwd/zh/afterTrading/STOCK_DAY';

    private const TPEX_STOCK_DAY_MONTH_URL = 'https://www.tpex.org.tw/www/zh-tw/afterTrading/tradingStock';

    private const NSTOCK_EPS_URL = 'https://www.nstock.tw/api/v2/eps/data';

    private const NSTOCK_DAILY_STOCK_DATA_URL = 'https://www.nstock.tw/api/v2/daily-stock-data/data';

    private const NSTOCK_MONTHLY_REVENUE_URL = 'https://www.nstock.tw/api/v2/monthly-revenue/data';

    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s';

    private const CNYES_SYMBOL_NEWS_URL = 'https://api.cnyes.com/media/api/v1/newslist/TWS:%s:STOCK/symbolNews';

    private const CNYES_NEWS_URL = 'https://news.cnyes.com/news/id/%s';

    private const EPS_GROWTH_WEIGHT = 0.35;

    private const GROSS_MARGIN_WEIGHT = 0.25;

    private const OPERATING_MARGIN_WEIGHT = 0.25;

    private const NET_MARGIN_WEIGHT = 0.15;

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $nstockQuarterRowsCache = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $nstockMonthlyRevenueRowsCache = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(int $year, int $quarter = 1, int $minVolumeLots = 1000, int $sleepMs = 80, ?int $limit = null): array
    {
        $period = sprintf('%04d%02d', $year, $quarter);
        $candidates = $this->volumeQualifiedCandidates($minVolumeLots);

        if ($limit !== null && $limit > 0) {
            $candidates = array_slice($candidates, 0, $limit);
        }

        $rows = [];
        $now = now();
        foreach ($candidates as $candidate) {
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            $financialRow = $this->fetchQuarterFinancialRow($candidate['stock_code'], $period, $year, $quarter);
            if ($financialRow === null) {
                continue;
            }

            $dailyRows = $this->fetchOfficialDailyRows($candidate);
            $dailyPriceSource = $dailyRows !== []
                ? $candidate['exchange'] . ' official monthly trading data'
                : null;

            if ($dailyRows === []) {
                $dailyRows = $this->fetchDailyRows($candidate['stock_code']);
                $dailyPriceSource = $dailyRows !== [] ? 'nStock api/v2/daily-stock-data/data' : null;
            }

            if ($dailyRows === []) {
                $dailyRows = $this->fetchYahooDailyRows($candidate);
                $dailyPriceSource = $dailyRows !== [] ? 'Yahoo Finance chart public endpoint' : null;
            }

            $latestDaily = $dailyRows[0] ?? null;
            $latestVolumeLots = $this->parseInteger($latestDaily['成交量'] ?? null) ?? $candidate['volume_lots'];

            if ($latestVolumeLots < $minVolumeLots) {
                continue;
            }

            $latestClose = $this->parseDecimal($latestDaily['收盤價'] ?? null) ?? $candidate['latest_close_price'];
            $latestPriceDate = $this->parseYmdDate((string) ($latestDaily['交易日'] ?? '')) ?? $candidate['latest_price_date'];
            $recentMonthlyRevenues = $this->fetchRecentMonthlyRevenueRows($candidate['stock_code']);

            $rows[] = [
                'fiscal_year' => $year,
                'quarter' => $quarter,
                'financial_period' => $period,
                'exchange' => $candidate['exchange'],
                'stock_code' => $candidate['stock_code'],
                'stock_name' => $candidate['stock_name'],
                'industry' => null,
                'q1_revenue_billion' => $this->decimal($this->parseDecimal($financialRow['季營收(億)'] ?? null), 4),
                'q1_revenue_yoy_percent' => $this->decimal($this->parseDecimal($financialRow['單季年成長(％)'] ?? $financialRow['單季年成長(%)'] ?? null), 4),
                'q1_revenue_score' => null,
                'q1_eps' => $this->decimal($this->parseDecimal($financialRow['公告基本每股盈餘(元)'] ?? null), 4),
                'q1_eps_yoy_percent' => $this->decimal($this->parseDecimal($financialRow['公告基本每股盈餘年成長2(%)'] ?? $financialRow['EPS年增率'] ?? null), 4),
                'q1_gross_margin_percent' => $this->decimal($this->parseDecimal($financialRow['單季毛利率(％)'] ?? $financialRow['單季毛利率(%)'] ?? null), 4, 1000),
                'q1_operating_margin_percent' => $this->decimal($this->parseDecimal($financialRow['單季營業利益率(％)'] ?? $financialRow['單季營業利益率(%)'] ?? null), 4, 1000),
                'q1_net_margin_percent' => $this->decimal($this->parseDecimal($financialRow['單季稅後淨利率(％)'] ?? $financialRow['單季稅後淨利率(%)'] ?? null), 4, 1000),
                'q1_net_income_billion' => $this->decimal($this->parseDecimal($financialRow['單季稅後淨利(億)'] ?? null), 4),
                'roe_percent' => $this->decimal($this->parseDecimal($financialRow['稅後權益報酬率(%)'] ?? null), 4, 1000),
                'roa_percent' => $this->decimal($this->parseDecimal($financialRow['稅後資產報酬率(%)'] ?? null), 4, 1000),
                'operating_profit_mix_percent' => $this->decimal($this->parseDecimal($financialRow['本業佔比'] ?? null), 4, 1000),
                'recent_monthly_revenues' => $recentMonthlyRevenues,
                'latest_close_price' => $this->decimal($latestClose, 4),
                'latest_price_date' => $latestPriceDate,
                'volume_lots' => $latestVolumeLots,
                'price_change_1d_percent' => $this->decimal($this->oneDayChange($dailyRows), 4),
                'price_change_5d_percent' => $this->decimal($this->periodChange($dailyRows, 5), 4),
                'price_change_20d_percent' => $this->decimal($this->periodChange($dailyRows, 20), 4),
                'rank' => null,
                'source_payload' => [
                    'quote_source' => $candidate['source_payload']['source'] ?? null,
                    'eps_source' => 'nStock api/v2/eps/data',
                    'daily_price_source' => $dailyPriceSource,
                    'official_quote_row' => $candidate['source_payload']['row'] ?? null,
                    'financial_row' => $financialRow,
                    'monthly_revenue_source' => 'nStock api/v2/monthly-revenue/data',
                    'latest_daily_rows' => array_slice($dailyRows, 0, 21),
                    'score_formula' => 'EPS YoY分位數 35% + 毛利率分位數 25% + 營益率分位數 25% + 淨利率分位數 15%',
                ],
                'fetched_at' => $now,
            ];
        }

        return $this->scoreAndRank($rows);
    }

    public function hasTodayOfficialQuote(?CarbonImmutable $date = null): bool
    {
        $targetDate = ($date ?? CarbonImmutable::now('Asia/Taipei'))->format('Y-m-d');

        return $this->officialQuoteHasDate(self::TWSE_DAILY_PRICE_URL, $targetDate)
            || $this->officialQuoteHasDate(self::TPEX_DAILY_PRICE_URL, $targetDate);
    }

    private function officialQuoteHasDate(string $url, string $targetDate): bool
    {
        try {
            $rows = $this->http()->get($url)->throw()->json();
        } catch (Throwable $e) {
            report($e);

            return false;
        }

        if (!is_array($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (is_array($row) && $this->parseRocDate((string) ($row['Date'] ?? '')) === $targetDate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function volumeQualifiedCandidates(int $minVolumeLots): array
    {
        $rows = [
            ...$this->twseCandidates($minVolumeLots),
            ...$this->tpexCandidates($minVolumeLots),
        ];

        usort($rows, fn (array $left, array $right): int => [
            $left['stock_code'],
            $left['exchange'],
        ] <=> [
            $right['stock_code'],
            $right['exchange'],
        ]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function twseCandidates(int $minVolumeLots): array
    {
        $rows = $this->http()->get(self::TWSE_DAILY_PRICE_URL)->throw()->json();
        if (!is_array($rows)) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['Code'] ?? ''));
            $volumeLots = (int) floor(($this->parseInteger($row['TradeVolume'] ?? null) ?? 0) / 1000);
            $close = $this->parseDecimal($row['ClosingPrice'] ?? null);
            $date = $this->parseRocDate((string) ($row['Date'] ?? ''));

            if (!$this->isCommonStockCode($code) || $volumeLots < $minVolumeLots || $close === null || $date === null) {
                continue;
            }

            $candidates[] = [
                'exchange' => 'TWSE',
                'stock_code' => $code,
                'stock_name' => trim((string) ($row['Name'] ?? '')),
                'latest_close_price' => $close,
                'latest_price_date' => $date,
                'volume_lots' => $volumeLots,
                'source_payload' => ['source' => 'TWSE STOCK_DAY_ALL', 'row' => $row],
            ];
        }

        return $candidates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tpexCandidates(int $minVolumeLots): array
    {
        $rows = $this->http()->get(self::TPEX_DAILY_PRICE_URL)->throw()->json();
        if (!is_array($rows)) {
            return [];
        }

        $candidates = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = trim((string) ($row['SecuritiesCompanyCode'] ?? ''));
            $volumeLots = (int) floor(($this->parseInteger($row['TradingShares'] ?? null) ?? 0) / 1000);
            $close = $this->parseDecimal($row['Close'] ?? null);
            $date = $this->parseRocDate((string) ($row['Date'] ?? ''));

            if (!$this->isCommonStockCode($code) || $volumeLots < $minVolumeLots || $close === null || $date === null) {
                continue;
            }

            $candidates[] = [
                'exchange' => 'TPEx',
                'stock_code' => $code,
                'stock_name' => trim((string) ($row['CompanyName'] ?? '')),
                'latest_close_price' => $close,
                'latest_price_date' => $date,
                'volume_lots' => $volumeLots,
                'source_payload' => ['source' => 'TPEx tpex_mainboard_quotes', 'row' => $row],
            ];
        }

        return $candidates;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchQuarterFinancialRow(string $stockCode, string $period, int $year, int $quarter): ?array
    {
        return $this->fetchNstockQuarterFinancialRow($stockCode, $period)
            ?? $this->fetchCnyesQuarterFinancialAnnouncementRow($stockCode, $year, $quarter);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchNstockQuarterFinancialRow(string $stockCode, string $period): ?array
    {
        foreach ($this->fetchNstockQuarterRows($stockCode) as $row) {
            if ((string) ($row['年季'] ?? '') === $period) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchNstockQuarterRows(string $stockCode): array
    {
        if (array_key_exists($stockCode, $this->nstockQuarterRowsCache)) {
            return $this->nstockQuarterRowsCache[$stockCode];
        }

        try {
            $response = $this->http()
                ->get(self::NSTOCK_EPS_URL, ['stock_id' => $stockCode])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return $this->nstockQuarterRowsCache[$stockCode] = [];
        }

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            return $this->nstockQuarterRowsCache[$stockCode] = [];
        }

        foreach ($response['data'] as $stockData) {
            if (!is_array($stockData) || !is_array($stockData['季度EPS'] ?? null)) {
                continue;
            }

            return $this->nstockQuarterRowsCache[$stockCode] = array_values(array_filter($stockData['季度EPS'], 'is_array'));
        }

        return $this->nstockQuarterRowsCache[$stockCode] = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCnyesQuarterFinancialAnnouncementRow(string $stockCode, int $year, int $quarter): ?array
    {
        $newsId = $this->findCnyesQuarterFinancialNewsId($stockCode, $year, $quarter);
        if ($newsId === null) {
            return null;
        }

        try {
            $html = $this->http()
                ->get(sprintf(self::CNYES_NEWS_URL, $newsId))
                ->throw()
                ->body();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $text = $this->plainTextFromHtml($html);
        $rocYear = $year - 1911;
        if (!str_contains($text, sprintf('%03d/01/01~%03d/03/31', $rocYear, $rocYear))
            && !str_contains($text, sprintf('%d/01/01~%d/03/31', $rocYear, $rocYear))) {
            return null;
        }

        $revenueThousand = $this->announcementValue($text, '營業收入');
        $grossProfitThousand = $this->announcementValue($text, '營業毛利');
        $operatingProfitThousand = $this->announcementValue($text, '營業利益');
        $netIncomeThousand = $this->announcementValue($text, '本期淨利');
        $parentNetIncomeThousand = $this->announcementValue($text, '歸屬於母公司業主淨利');
        $eps = $this->announcementValue($text, '基本每股盈餘');
        $totalAssetsThousand = $this->announcementValue($text, '期末總資產');
        $parentEquityThousand = $this->announcementValue($text, '期末歸屬於母公司業主之權益');

        if ($revenueThousand === null || $revenueThousand <= 0.0 || $eps === null) {
            return null;
        }

        $revenueBillion = $revenueThousand / 100000;
        $previousYearRevenueBillion = $this->previousYearRevenueBillion($stockCode, $year, $quarter);
        $previousYearEps = $this->previousYearEps($stockCode, $year, $quarter);

        return [
            '年季' => sprintf('%04d%02d', $year, $quarter),
            '公告基本每股盈餘(元)' => $this->decimal($eps, 2),
            '公告基本每股盈餘年成長2(%)' => $this->growthPercent($eps, $previousYearEps),
            '季營收(億)' => $this->decimal($revenueBillion, 4),
            '單季年成長(％)' => $this->growthPercent($revenueBillion, $previousYearRevenueBillion),
            '單季毛利率(％)' => $this->marginPercent($grossProfitThousand, $revenueThousand),
            '單季營業利益率(％)' => $this->marginPercent($operatingProfitThousand, $revenueThousand),
            '單季稅後淨利率(％)' => $this->marginPercent($netIncomeThousand, $revenueThousand),
            '單季稅後淨利(億)' => $netIncomeThousand === null ? null : $this->decimal($netIncomeThousand / 100000, 4),
            '稅後權益報酬率(%)' => $this->marginPercent($parentNetIncomeThousand, $parentEquityThousand),
            '稅後資產報酬率(%)' => $this->marginPercent($netIncomeThousand, $totalAssetsThousand),
            '本業佔比' => $this->marginPercent($operatingProfitThousand, $netIncomeThousand),
            '_source' => 'Cnyes TW stock announcement',
            '_source_url' => sprintf(self::CNYES_NEWS_URL, $newsId),
            '_raw' => [
                'news_id' => $newsId,
                'revenue_thousand' => $revenueThousand,
                'gross_profit_thousand' => $grossProfitThousand,
                'operating_profit_thousand' => $operatingProfitThousand,
                'net_income_thousand' => $netIncomeThousand,
                'parent_net_income_thousand' => $parentNetIncomeThousand,
                'total_assets_thousand' => $totalAssetsThousand,
                'parent_equity_thousand' => $parentEquityThousand,
            ],
        ];
    }

    private function findCnyesQuarterFinancialNewsId(string $stockCode, int $year, int $quarter): ?int
    {
        try {
            $response = $this->http()
                ->get(sprintf(self::CNYES_SYMBOL_NEWS_URL, $stockCode), [
                    'page' => 1,
                    'limit' => 20,
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $rows = $response['items']['data'] ?? null;
        if (!is_array($rows)) {
            return null;
        }

        $rocYear = (string) ($year - 1911);
        $quarterLabels = [
            $rocYear . '年第' . $quarter . '季',
            $rocYear . '年第一季',
            str_pad($rocYear, 3, '0', STR_PAD_LEFT) . '年第' . $quarter . '季',
            str_pad($rocYear, 3, '0', STR_PAD_LEFT) . '年第一季',
        ];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = (string) ($row['title'] ?? '');
            $matchesQuarter = false;
            foreach ($quarterLabels as $quarterLabel) {
                if (str_contains($title, $quarterLabel)) {
                    $matchesQuarter = true;
                    break;
                }
            }

            if ($matchesQuarter
                && str_contains($title, '董事會通過')
                && str_contains($title, '財務報告')
                && isset($row['newsId'])) {
                return (int) $row['newsId'];
            }
        }

        return null;
    }

    private function previousYearRevenueBillion(string $stockCode, int $year, int $quarter): ?float
    {
        $row = $this->fetchNstockQuarterFinancialRow($stockCode, sprintf('%04d%02d', $year - 1, $quarter));

        return $this->parseDecimal($row['季營收(億)'] ?? null);
    }

    private function previousYearEps(string $stockCode, int $year, int $quarter): ?float
    {
        $row = $this->fetchNstockQuarterFinancialRow($stockCode, sprintf('%04d%02d', $year - 1, $quarter));

        return $this->parseDecimal($row['公告基本每股盈餘(元)'] ?? null);
    }

    private function growthPercent(?float $value, ?float $baseline): ?string
    {
        if ($value === null || $baseline === null || abs($baseline) < 0.000001) {
            return null;
        }

        return $this->decimal((($value - $baseline) / abs($baseline)) * 100, 4, 100000);
    }

    private function marginPercent(?float $value, ?float $denominator): ?string
    {
        if ($value === null || $denominator === null || abs($denominator) < 0.000001) {
            return null;
        }

        return $this->decimal(($value / $denominator) * 100, 4, 1000);
    }

    private function announcementValue(string $text, string $label): ?float
    {
        if (!preg_match('/' . preg_quote($label, '/') . '[^:：]*[:：]\s*([\(（]?-?[\d,]+(?:\.\d+)?[\)）]?)/u', $text, $matches)) {
            return null;
        }

        return $this->parseAccountingNumber($matches[1]);
    }

    private function parseAccountingNumber(string $value): ?float
    {
        $normalized = trim(str_replace([',', "\xc2\xa0", ' '], '', $value));
        $negative = (str_starts_with($normalized, '(') && str_ends_with($normalized, ')'))
            || (str_starts_with($normalized, '（') && str_ends_with($normalized, '）'));
        $normalized = trim($normalized, "()（）");

        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        return $negative ? -$number : $number;
    }

    private function plainTextFromHtml(string $html): string
    {
        $html = preg_replace('/<\s*\/p\s*>/i', "\n", $html) ?? $html;
        $html = preg_replace('/<\s*br\s*\/?>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/[ \t\r]+/u', '', $text);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchOfficialDailyRows(array $candidate): array
    {
        $latestPriceDate = $candidate['latest_price_date'] ?? null;
        $startMonth = $latestPriceDate
            ? CarbonImmutable::parse((string) $latestPriceDate)->startOfMonth()
            : CarbonImmutable::today()->startOfMonth();

        $rows = [];
        for ($offset = 0; $offset < 3 && count($rows) < 25; $offset++) {
            $month = $startMonth->subMonthsNoOverflow($offset);
            $rows = [
                ...$rows,
                ...($candidate['exchange'] === 'TPEx'
                    ? $this->fetchTpexMonthlyDailyRows($candidate['stock_code'], $month)
                    : $this->fetchTwseMonthlyDailyRows($candidate['stock_code'], $month)),
            ];
        }

        $deduped = [];
        foreach ($rows as $row) {
            $deduped[(string) $row['交易日']] = $row;
        }

        $rows = array_values($deduped);
        usort($rows, fn (array $left, array $right): int => (string) $right['交易日'] <=> (string) $left['交易日']);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTwseMonthlyDailyRows(string $stockCode, CarbonImmutable $month): array
    {
        try {
            $response = $this->http()
                ->get(self::TWSE_STOCK_DAY_MONTH_URL, [
                    'date' => $month->format('Ymd'),
                    'stockNo' => $stockCode,
                    'response' => 'json',
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            return [];
        }

        $rows = [];
        foreach ($response['data'] as $row) {
            if (!is_array($row) || count($row) < 7) {
                continue;
            }

            $date = $this->parseRocDate((string) $row[0]);
            $close = $this->parseDecimal($row[6] ?? null);
            $shares = $this->parseInteger($row[1] ?? null);
            if ($date === null || $close === null || $shares === null) {
                continue;
            }

            $rows[] = [
                '交易日' => str_replace('-', '', $date),
                '收盤價' => (string) $close,
                '成交量' => (string) (int) floor($shares / 1000),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTpexMonthlyDailyRows(string $stockCode, CarbonImmutable $month): array
    {
        try {
            $response = $this->http()
                ->get(self::TPEX_STOCK_DAY_MONTH_URL, [
                    'code' => $stockCode,
                    'date' => $month->format('Y/m/d'),
                    'response' => 'json',
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $data = $response['tables'][0]['data'] ?? null;
        if (!is_array($response) || !is_array($data)) {
            return [];
        }

        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row) || count($row) < 7) {
                continue;
            }

            $date = $this->parseRocDate((string) $row[0]);
            $close = $this->parseDecimal($row[6] ?? null);
            $volumeLots = $this->parseInteger($row[1] ?? null);
            if ($date === null || $close === null || $volumeLots === null) {
                continue;
            }

            $rows[] = [
                '交易日' => str_replace('-', '', $date),
                '收盤價' => (string) $close,
                '成交量' => (string) $volumeLots,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchDailyRows(string $stockCode): array
    {
        try {
            $response = $this->http()
                ->get(self::NSTOCK_DAILY_STOCK_DATA_URL, ['stock_id' => $stockCode])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            return [];
        }

        foreach ($response['data'] as $stockData) {
            if (!is_array($stockData) || !is_array($stockData['日K'] ?? null)) {
                continue;
            }

            return array_values(array_filter($stockData['日K'], 'is_array'));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    private function fetchYahooDailyRows(array $candidate): array
    {
        $suffix = $candidate['exchange'] === 'TPEx' ? '.TWO' : '.TW';

        try {
            $response = $this->http()
                ->get(sprintf(self::YAHOO_CHART_URL, $candidate['stock_code'] . $suffix), [
                    'range' => '3mo',
                    'interval' => '1d',
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        $result = $response['chart']['result'][0] ?? null;
        $timestamps = $result['timestamp'] ?? null;
        $quote = $result['indicators']['quote'][0] ?? null;
        if (!is_array($timestamps) || !is_array($quote)) {
            return [];
        }

        $closes = $quote['close'] ?? [];
        $volumes = $quote['volume'] ?? [];
        $rows = [];
        foreach ($timestamps as $index => $timestamp) {
            $close = $this->parseDecimal($closes[$index] ?? null);
            $volumeShares = $this->parseInteger($volumes[$index] ?? null);
            if ($close === null || $volumeShares === null) {
                continue;
            }

            $date = CarbonImmutable::createFromTimestamp((int) $timestamp, 'UTC')
                ->setTimezone('Asia/Taipei')
                ->format('Ymd');

            $rows[] = [
                '交易日' => $date,
                '收盤價' => (string) $close,
                '成交量' => (string) (int) floor($volumeShares / 1000),
            ];
        }

        usort($rows, fn (array $left, array $right): int => (string) $right['交易日'] <=> (string) $left['交易日']);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecentMonthlyRevenueRows(string $stockCode, int $months = 4): array
    {
        if (array_key_exists($stockCode, $this->nstockMonthlyRevenueRowsCache)) {
            return array_slice($this->nstockMonthlyRevenueRowsCache[$stockCode], 0, $months);
        }

        try {
            $response = $this->http()
                ->get(self::NSTOCK_MONTHLY_REVENUE_URL, ['stock_id' => $stockCode])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return $this->nstockMonthlyRevenueRowsCache[$stockCode] = [];
        }

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            return $this->nstockMonthlyRevenueRowsCache[$stockCode] = [];
        }

        foreach ($response['data'] as $stockData) {
            if (!is_array($stockData) || !is_array($stockData['月營收'] ?? null)) {
                continue;
            }

            $rows = [];
            foreach ($stockData['月營收'] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $yearMonth = preg_replace('/\D+/', '', (string) ($row['年月'] ?? '')) ?? '';
                if (strlen($yearMonth) !== 6) {
                    continue;
                }

                $rows[] = [
                    'year_month' => $yearMonth,
                    'revenue_billion' => $this->decimal($this->parseDecimal($row['單月營收(億)'] ?? null), 4),
                    'revenue_yoy_percent' => $this->decimal($this->parseDecimal($row['單月營收年成長(%)'] ?? null), 4, 100000),
                    'revenue_mom_percent' => $this->decimal($this->parseDecimal($row['單月營收月變動(%)'] ?? null), 4, 100000),
                    'cumulative_revenue_billion' => $this->decimal($this->parseDecimal($row['累計營收(億)'] ?? null), 4),
                    'cumulative_yoy_percent' => $this->decimal($this->parseDecimal($row['累計營收成長(%)'] ?? null), 4, 100000),
                ];
            }

            usort($rows, fn (array $left, array $right): int => (string) $right['year_month'] <=> (string) $left['year_month']);

            return $this->nstockMonthlyRevenueRowsCache[$stockCode] = array_slice($rows, 0, $months);
        }

        return $this->nstockMonthlyRevenueRowsCache[$stockCode] = [];
    }

    private function oneDayChange(array $dailyRows): ?float
    {
        $latest = $dailyRows[0] ?? null;
        if (is_array($latest)) {
            $change = $this->parseDecimal($latest['漲幅(%)'] ?? null);
            if ($change !== null) {
                return $change;
            }
        }

        return $this->periodChange($dailyRows, 1);
    }

    /**
     * @param list<array<string, mixed>> $dailyRows
     */
    private function periodChange(array $dailyRows, int $daysBack): ?float
    {
        $latest = $dailyRows[0] ?? null;
        $baseline = $dailyRows[$daysBack] ?? null;

        if (!is_array($latest) || !is_array($baseline)) {
            return null;
        }

        $latestClose = $this->parseDecimal($latest['收盤價'] ?? null);
        $baselineClose = $this->parseDecimal($baseline['收盤價'] ?? null);

        if ($latestClose === null || $baselineClose === null || $baselineClose <= 0.0) {
            return null;
        }

        return (($latestClose - $baselineClose) / $baselineClose) * 100;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function scoreAndRank(array $rows): array
    {
        $epsGrowthPercentiles = $this->percentiles($rows, 'q1_eps_yoy_percent');
        $grossMarginPercentiles = $this->percentiles($rows, 'q1_gross_margin_percent');
        $operatingMarginPercentiles = $this->percentiles($rows, 'q1_operating_margin_percent');
        $netMarginPercentiles = $this->percentiles($rows, 'q1_net_margin_percent');

        foreach ($rows as $index => $row) {
            $score = (($epsGrowthPercentiles[$index] ?? 0.0) * self::EPS_GROWTH_WEIGHT)
                + (($grossMarginPercentiles[$index] ?? 0.0) * self::GROSS_MARGIN_WEIGHT)
                + (($operatingMarginPercentiles[$index] ?? 0.0) * self::OPERATING_MARGIN_WEIGHT)
                + (($netMarginPercentiles[$index] ?? 0.0) * self::NET_MARGIN_WEIGHT);

            $rows[$index]['q1_revenue_score'] = $this->decimal($score * 100, 4);
        }

        usort($rows, fn (array $left, array $right): int => [
            (float) ($right['q1_revenue_score'] ?? 0),
            (float) ($right['q1_eps_yoy_percent'] ?? -999999),
            (float) ($right['q1_operating_margin_percent'] ?? -999999),
            (float) ($right['q1_gross_margin_percent'] ?? -999999),
            (float) ($right['q1_net_margin_percent'] ?? -999999),
            $left['stock_code'],
        ] <=> [
            (float) ($left['q1_revenue_score'] ?? 0),
            (float) ($left['q1_eps_yoy_percent'] ?? -999999),
            (float) ($left['q1_operating_margin_percent'] ?? -999999),
            (float) ($left['q1_gross_margin_percent'] ?? -999999),
            (float) ($left['q1_net_margin_percent'] ?? -999999),
            $right['stock_code'],
        ]);

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, float>
     */
    private function percentiles(array $rows, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            if ($row[$field] !== null) {
                $values[] = (float) $row[$field];
            }
        }

        $values = array_values(array_unique($values, SORT_REGULAR));
        sort($values, SORT_NUMERIC);

        if ($values === []) {
            return array_fill(0, count($rows), 0.0);
        }

        $map = [];
        $denominator = max(count($values) - 1, 1);
        foreach ($values as $index => $value) {
            $map[$this->numericKey($value)] = count($values) === 1 ? 1.0 : $index / $denominator;
        }

        $percentiles = [];
        foreach ($rows as $index => $row) {
            $percentiles[$index] = $row[$field] === null ? 0.0 : ($map[$this->numericKey((float) $row[$field])] ?? 0.0);
        }

        return $percentiles;
    }

    private function numericKey(float $value): string
    {
        return number_format($value, 8, '.', '');
    }

    private function isCommonStockCode(string $stockCode): bool
    {
        return preg_match('/^[1-9]\d{3}$/', $stockCode) === 1;
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

    private function parseYmdDate(string $value): ?string
    {
        $normalized = preg_replace('/\D+/', '', trim($value)) ?? '';
        if (strlen($normalized) !== 8) {
            return null;
        }

        $year = (int) substr($normalized, 0, 4);
        $month = (int) substr($normalized, 4, 2);
        $day = (int) substr($normalized, 6, 2);

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseDecimal(mixed $value): ?float
    {
        $normalized = str_replace([',', "\xc2\xa0", ' ', '%'], '', trim((string) $value));

        if ($normalized === '' || $normalized === '-' || $normalized === '－' || $normalized === '--') {
            return null;
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function parseInteger(mixed $value): ?int
    {
        $normalized = str_replace([',', "\xc2\xa0", ' '], '', trim((string) $value));

        if ($normalized === '' || $normalized === '-' || $normalized === '－' || !is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    private function decimal(?float $value, int $scale, ?float $maxAbs = null): ?string
    {
        if ($value !== null && $maxAbs !== null && abs($value) > $maxAbs) {
            return null;
        }

        return $value === null ? null : number_format($value, $scale, '.', '');
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/json,text/html',
        ])
            ->timeout(30)
            ->retry(2, 500);
    }
}
