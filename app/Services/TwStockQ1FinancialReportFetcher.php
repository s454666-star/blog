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

    private const MOPS_MONTHLY_REVENUE_DOWNLOAD_URL = 'https://mopsov.twse.com.tw/server-java/FileDownLoad';

    private const MOPS_MONTHLY_REVENUE_MARKETS = ['sii', 'otc'];

    private const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s';

    private const CNYES_SYMBOL_NEWS_URL = 'https://api.cnyes.com/media/api/v1/newslist/TWS:%s:STOCK/symbolNews';

    private const CNYES_NEWS_URL = 'https://news.cnyes.com/news/id/%s';

    private const MOPS_MAJOR_ANNOUNCEMENT_URL = 'https://mopsov.twse.com.tw/mops/web/ajax_t05st01';

    private const MOPS_MAJOR_ANNOUNCEMENT_REFERER = 'https://mopsov.twse.com.tw/mops/web/t05st01';

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
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $mopsMonthlyRevenueRowsCache = [];

    /**
     * @var array<int, list<array<string, mixed>>>
     */
    private array $volumeQualifiedCandidatesCache = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $officialDailyRowsCache = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $nstockDailyRowsCache = [];

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $yahooDailyRowsCache = [];

    /**
     * @var array<string, list<array<string, string>>>
     */
    private array $mopsAnnouncementRowsCache = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(
        int $year,
        int $quarter = 1,
        int $minVolumeLots = 1000,
        int $sleepMs = 80,
        ?int $limit = null,
        int $monthlyRevenueMonths = 60,
        bool $refreshMarketData = true,
        bool $useAnnouncementFallbacks = true,
        int $shardCount = 1,
        int $shardIndex = 0,
    ): array {
        $period = sprintf('%04d%02d', $year, $quarter);
        $candidates = $this->volumeQualifiedCandidates($minVolumeLots);

        if ($limit !== null && $limit > 0) {
            $candidates = array_slice($candidates, 0, $limit);
        }
        $candidates = $this->filterCandidatesForShard($candidates, $shardCount, $shardIndex);

        $rows = [];
        $now = now();
        foreach ($candidates as $candidate) {
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            $financialRow = $this->fetchQuarterFinancialRow($candidate['stock_code'], $period, $year, $quarter, $useAnnouncementFallbacks);
            if ($financialRow === null) {
                continue;
            }

            $marketData = [
                'latest_close_price' => $candidate['latest_close_price'],
                'latest_price_date' => $candidate['latest_price_date'],
                'volume_lots' => $candidate['volume_lots'],
                'price_change_1d_percent' => null,
                'price_change_5d_percent' => null,
                'price_change_20d_percent' => null,
                'daily_price_source' => $candidate['source_payload']['source'] ?? null,
                'latest_daily_rows' => [],
            ];
            if ($refreshMarketData) {
                $marketData = $this->fetchMarketData($candidate);
            }

            if ((int) ($marketData['volume_lots'] ?? 0) < $minVolumeLots) {
                continue;
            }

            $recentMonthlyRevenues = $this->fetchRecentMonthlyRevenueRows($candidate['stock_code'], $monthlyRevenueMonths);

            $rows[] = [
                'fiscal_year' => $year,
                'quarter' => $quarter,
                'financial_period' => $period,
                'exchange' => $candidate['exchange'],
                'stock_code' => $candidate['stock_code'],
                'stock_name' => $candidate['stock_name'],
                'industry' => null,
                'q1_revenue_billion' => $this->decimal($this->parseDecimal($financialRow['季營收(億)'] ?? null), 4),
                'q1_revenue_yoy_percent' => $this->decimal($this->parseDecimal($financialRow['單季年成長(％)'] ?? $financialRow['單季年成長(%)'] ?? null), 4, 999999),
                'q1_revenue_score' => null,
                'q1_eps' => $this->decimal($this->parseDecimal($financialRow['公告基本每股盈餘(元)'] ?? null), 4),
                'q1_eps_yoy_percent' => $this->decimal($this->parseDecimal($financialRow['公告基本每股盈餘年成長2(%)'] ?? $financialRow['EPS年增率'] ?? null), 4, 999999),
                'q1_gross_margin_percent' => $this->decimal($this->parseDecimal($financialRow['單季毛利率(％)'] ?? $financialRow['單季毛利率(%)'] ?? null), 4, 1000),
                'q1_operating_margin_percent' => $this->decimal($this->parseDecimal($financialRow['單季營業利益率(％)'] ?? $financialRow['單季營業利益率(%)'] ?? null), 4, 1000),
                'q1_net_margin_percent' => $this->decimal($this->parseDecimal($financialRow['單季稅後淨利率(％)'] ?? $financialRow['單季稅後淨利率(%)'] ?? null), 4, 1000),
                'q1_net_income_billion' => $this->decimal($this->parseDecimal($financialRow['單季稅後淨利(億)'] ?? null), 4),
                'roe_percent' => $this->decimal($this->parseDecimal($financialRow['稅後權益報酬率(%)'] ?? null), 4, 1000),
                'roa_percent' => $this->decimal($this->parseDecimal($financialRow['稅後資產報酬率(%)'] ?? null), 4, 1000),
                'operating_profit_mix_percent' => $this->decimal($this->parseDecimal($financialRow['本業佔比'] ?? null), 4, 1000),
                'recent_monthly_revenues' => $recentMonthlyRevenues,
                'latest_close_price' => $this->decimal($this->parseDecimal($marketData['latest_close_price'] ?? null), 4),
                'latest_price_date' => $marketData['latest_price_date'],
                'volume_lots' => (int) ($marketData['volume_lots'] ?? 0),
                'price_change_1d_percent' => $refreshMarketData ? $this->decimal($this->parseDecimal($marketData['price_change_1d_percent'] ?? null), 4) : null,
                'price_change_5d_percent' => $refreshMarketData ? $this->decimal($this->parseDecimal($marketData['price_change_5d_percent'] ?? null), 4) : null,
                'price_change_20d_percent' => $refreshMarketData ? $this->decimal($this->parseDecimal($marketData['price_change_20d_percent'] ?? null), 4) : null,
                'rank' => null,
                'source_payload' => [
                    'quote_source' => $candidate['source_payload']['source'] ?? null,
                    'eps_source' => $financialRow['_source'] ?? 'nStock api/v2/eps/data',
                    'daily_price_source' => $marketData['daily_price_source'],
                    'official_quote_row' => $candidate['source_payload']['row'] ?? null,
                    'financial_row' => $financialRow,
                    'monthly_revenue_source' => 'nStock api/v2/monthly-revenue/data',
                    'latest_daily_rows' => array_slice($marketData['latest_daily_rows'], 0, 21),
                    'score_formula' => 'EPS YoY分位數 35% + 毛利率分位數 25% + 營益率分位數 25% + 淨利率分位數 15%',
                ],
                'fetched_at' => $now,
            ];
        }

        return $this->scoreAndRank($rows);
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    public function fetchMarketData(array $candidate): array
    {
        $officialQuoteCandidate = $this->fetchLatestOfficialQuoteCandidate($candidate);
        $marketCandidate = $officialQuoteCandidate === null
            ? $candidate
            : array_replace($candidate, [
                'latest_close_price' => $officialQuoteCandidate['latest_close_price'],
                'latest_price_date' => $officialQuoteCandidate['latest_price_date'],
                'volume_lots' => $officialQuoteCandidate['volume_lots'],
            ]);

        $dailyRows = $this->fetchOfficialDailyRows($marketCandidate);
        $dailyPriceSource = $dailyRows !== []
            ? $marketCandidate['exchange'] . ' official monthly trading data'
            : null;

        if ($dailyRows === []) {
            $dailyRows = $this->fetchDailyRows((string) $marketCandidate['stock_code']);
            $dailyPriceSource = $dailyRows !== [] ? 'nStock api/v2/daily-stock-data/data' : null;
        }

        if ($dailyRows === []) {
            $dailyRows = $this->fetchYahooDailyRows($marketCandidate);
            $dailyPriceSource = $dailyRows !== [] ? 'Yahoo Finance chart public endpoint' : null;
        }

        if ($officialQuoteCandidate !== null) {
            $dailyRows = $this->mergeOfficialQuoteDailyRow($dailyRows, $officialQuoteCandidate);
            $quoteSource = (string) ($officialQuoteCandidate['source_payload']['source'] ?? 'official quote');
            $dailyPriceSource = $dailyPriceSource === null || $dailyPriceSource === $quoteSource
                ? $quoteSource
                : $quoteSource . ' + ' . $dailyPriceSource;
        }

        $latestDaily = $dailyRows[0] ?? null;
        $latestClose = $this->parseDecimal(is_array($latestDaily) ? ($latestDaily['收盤價'] ?? null) : null)
            ?? $this->parseDecimal($marketCandidate['latest_close_price'] ?? null);
        $latestPriceDate = $this->parseYmdDate((string) (is_array($latestDaily) ? ($latestDaily['交易日'] ?? '') : ''))
            ?? $this->dateString($marketCandidate['latest_price_date'] ?? null);
        $latestVolumeLots = $this->parseInteger(is_array($latestDaily) ? ($latestDaily['成交量'] ?? null) : null)
            ?? (int) ($marketCandidate['volume_lots'] ?? 0);

        return [
            'latest_close_price' => $latestClose,
            'latest_price_date' => $latestPriceDate,
            'volume_lots' => $latestVolumeLots,
            'price_change_1d_percent' => $this->oneDayChange($dailyRows),
            'price_change_5d_percent' => $this->periodChange($dailyRows, 5),
            'price_change_20d_percent' => $this->periodChange($dailyRows, 20),
            'daily_price_source' => $dailyPriceSource,
            'latest_daily_rows' => $dailyRows,
            'official_quote_row' => $officialQuoteCandidate['source_payload']['row'] ?? null,
            'official_quote_source' => $officialQuoteCandidate['source_payload']['source'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>|null
     */
    private function fetchLatestOfficialQuoteCandidate(array $candidate): ?array
    {
        $exchange = (string) ($candidate['exchange'] ?? '');
        $stockCode = (string) ($candidate['stock_code'] ?? '');
        if ($exchange === '' || $stockCode === '') {
            return null;
        }

        try {
            $candidates = $exchange === 'TPEx'
                ? $this->tpexCandidates(0)
                : $this->twseCandidates(0);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        foreach ($candidates as $quoteCandidate) {
            if ((string) ($quoteCandidate['stock_code'] ?? '') === $stockCode) {
                return $quoteCandidate;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $dailyRows
     * @param array<string, mixed> $officialQuoteCandidate
     * @return list<array<string, mixed>>
     */
    private function mergeOfficialQuoteDailyRow(array $dailyRows, array $officialQuoteCandidate): array
    {
        $date = $this->dateString($officialQuoteCandidate['latest_price_date'] ?? null);
        $close = $this->parseDecimal($officialQuoteCandidate['latest_close_price'] ?? null);
        $volumeLots = $this->parseInteger($officialQuoteCandidate['volume_lots'] ?? null);
        if ($date === null || $close === null || $volumeLots === null) {
            return $dailyRows;
        }

        $rowsByDate = [
            str_replace('-', '', $date) => [
                '交易日' => str_replace('-', '', $date),
                '收盤價' => (string) $close,
                '成交量' => (string) $volumeLots,
            ],
        ];

        foreach ($dailyRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowDate = (string) ($row['交易日'] ?? '');
            if ($rowDate === '' || array_key_exists($rowDate, $rowsByDate)) {
                continue;
            }

            $rowsByDate[$rowDate] = $row;
        }

        krsort($rowsByDate);

        return array_values($rowsByDate);
    }

    public function hasTodayOfficialQuote(?CarbonImmutable $date = null): bool
    {
        return $this->hasExpectedLatestOfficialQuote($date);
    }

    public function hasExpectedLatestOfficialQuote(?CarbonImmutable $date = null): bool
    {
        $targetDate = $this->expectedLatestTradingDate($date);

        return $this->officialQuoteHasDate(self::TWSE_DAILY_PRICE_URL, $targetDate)
            || $this->officialQuoteHasDate(self::TPEX_DAILY_PRICE_URL, $targetDate);
    }

    public function expectedLatestTradingDate(?CarbonImmutable $date = null): string
    {
        $targetDate = $date ?? CarbonImmutable::now('Asia/Taipei');
        while ($targetDate->isWeekend()) {
            $targetDate = $targetDate->subDay();
        }

        return $targetDate->format('Y-m-d');
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
        if (array_key_exists($minVolumeLots, $this->volumeQualifiedCandidatesCache)) {
            return $this->volumeQualifiedCandidatesCache[$minVolumeLots];
        }

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

        return $this->volumeQualifiedCandidatesCache[$minVolumeLots] = $rows;
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function filterCandidatesForShard(array $candidates, int $shardCount, int $shardIndex): array
    {
        if ($shardCount <= 1) {
            return $candidates;
        }

        return array_values(array_filter($candidates, function (array $candidate) use ($shardCount, $shardIndex): bool {
            $stockCode = (string) ($candidate['stock_code'] ?? '');

            return ((int) sprintf('%u', crc32($stockCode)) % $shardCount) === $shardIndex;
        }));
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
    private function fetchQuarterFinancialRow(
        string $stockCode,
        string $period,
        int $year,
        int $quarter,
        bool $useAnnouncementFallbacks,
    ): ?array {
        $row = $this->fetchNstockQuarterFinancialRow($stockCode, $period);
        if ($row !== null || !$useAnnouncementFallbacks) {
            return $row;
        }

        return $this->fetchMopsQuarterFinancialAnnouncementRow($stockCode, $year, $quarter)
            ?? $this->fetchCnyesQuarterFinancialAnnouncementRow($stockCode, $year, $quarter);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRecentMonthlyRevenueRows(string $stockCode, int $months = 60): array
    {
        $rows = $this->fetchRecentMonthlyRevenueRowsFromNstock($stockCode, $months);

        if (count($rows) >= $months || $rows === [] || $months <= 60) {
            return $rows;
        }

        return $this->fillRecentMonthlyRevenueRowsFromMops($stockCode, $rows, $months);
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
        return $this->financialAnnouncementTextToRow(
            $text,
            $stockCode,
            $year,
            $quarter,
            'Cnyes TW stock announcement',
            sprintf(self::CNYES_NEWS_URL, $newsId),
            ['news_id' => $newsId],
        );
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

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = (string) ($row['title'] ?? '');
            if ($this->isQuarterFinancialAnnouncementText($title, $year, $quarter) && isset($row['newsId'])) {
                return (int) $row['newsId'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchMopsQuarterFinancialAnnouncementRow(string $stockCode, int $year, int $quarter): ?array
    {
        foreach ($this->mopsAnnouncementMonths($quarter) as $month) {
            foreach ($this->fetchMopsAnnouncementRows($stockCode, $year - 1911, $month) as $announcement) {
                if (!$this->isQuarterFinancialAnnouncementText($announcement['title'] ?? '', $year, $quarter)) {
                    continue;
                }

                $html = $this->fetchMopsAnnouncementDetail($announcement);
                if ($html === null) {
                    continue;
                }

                $row = $this->financialAnnouncementTextToRow(
                    $this->plainTextFromHtml($html),
                    $stockCode,
                    $year,
                    $quarter,
                    'MOPS ajax_t05st01',
                    self::MOPS_MAJOR_ANNOUNCEMENT_REFERER,
                    $announcement,
                );

                if ($row !== null) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function mopsAnnouncementMonths(int $quarter): array
    {
        $startMonth = ($quarter * 3) + 1;
        $endMonth = min($startMonth + 1, 12);
        if ($startMonth > 12) {
            return [];
        }

        return range($endMonth, $startMonth);
    }

    /**
     * @return list<array<string, string>>
     */
    private function fetchMopsAnnouncementRows(string $stockCode, int $rocYear, int $month): array
    {
        $key = $stockCode . ':' . $rocYear . ':' . $month;
        if (array_key_exists($key, $this->mopsAnnouncementRowsCache)) {
            return $this->mopsAnnouncementRowsCache[$key];
        }

        try {
            $html = $this->http()
                ->asForm()
                ->withHeaders(['Referer' => self::MOPS_MAJOR_ANNOUNCEMENT_REFERER])
                ->post(self::MOPS_MAJOR_ANNOUNCEMENT_URL, [
                    'step' => '1',
                    'firstin' => '1',
                    'off' => '1',
                    'keyword4' => '',
                    'code1' => '',
                    'TYPEK2' => '',
                    'checkbtn' => '',
                    'queryName' => 'co_id',
                    'inpuType' => 'co_id',
                    'TYPEK' => 'all',
                    'co_id' => $stockCode,
                    'year' => (string) $rocYear,
                    'month' => sprintf('%02d', $month),
                    'b_date' => '',
                    'e_date' => '',
                ])
                ->throw()
                ->body();
        } catch (Throwable $e) {
            report($e);

            return $this->mopsAnnouncementRowsCache[$key] = [];
        }

        return $this->mopsAnnouncementRowsCache[$key] = $this->parseMopsAnnouncementRows($html);
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseMopsAnnouncementRows(string $html): array
    {
        if (!preg_match_all("/<tr class='(?:even|odd)'>(.*?)<\\/tr>/is", $html, $matches)) {
            return [];
        }

        $rows = [];
        foreach ($matches[1] as $block) {
            $params = $this->mopsAnnouncementParams($block);
            if ($params === []) {
                continue;
            }

            $params['title'] = $this->plainTextFromHtml($block);
            $rows[] = $params;
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function mopsAnnouncementParams(string $html): array
    {
        $fields = [
            'seq_no' => "/seq_no\\.value='([^']+)'/i",
            'spoke_time' => "/spoke_time\\.value='([^']+)'/i",
            'spoke_date' => "/spoke_date\\.value='([^']+)'/i",
            'co_id' => "/co_id\\.value='([^']+)'/i",
            'typek' => "/TYPEK\\.value='([^']+)'/i",
        ];

        $params = [];
        foreach ($fields as $field => $pattern) {
            if (!preg_match($pattern, $html, $matches)) {
                return [];
            }

            $params[$field] = $matches[1];
        }

        return $params;
    }

    /**
     * @param array<string, string> $announcement
     */
    private function fetchMopsAnnouncementDetail(array $announcement): ?string
    {
        $date = $this->parseYmdDate($announcement['spoke_date'] ?? '');
        if ($date === null) {
            return null;
        }

        $dateParts = explode('-', $date);
        $rocYear = (int) $dateParts[0] - 1911;

        try {
            return $this->http()
                ->asForm()
                ->withHeaders(['Referer' => self::MOPS_MAJOR_ANNOUNCEMENT_REFERER])
                ->post(self::MOPS_MAJOR_ANNOUNCEMENT_URL, [
                    'step' => '2',
                    'firstin' => 'true',
                    'off' => '1',
                    'TYPEK' => $announcement['typek'] ?? '',
                    'co_id' => $announcement['co_id'] ?? '',
                    'year' => (string) $rocYear,
                    'month' => $dateParts[1],
                    'b_date' => $dateParts[2],
                    'e_date' => $dateParts[2],
                    'spoke_date' => $announcement['spoke_date'] ?? '',
                    'spoke_time' => $announcement['spoke_time'] ?? '',
                    'seq_no' => $announcement['seq_no'] ?? '',
                ])
                ->throw()
                ->body();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function isQuarterFinancialAnnouncementText(string $text, int $year, int $quarter): bool
    {
        $compact = preg_replace('/\s+/u', '', $text) ?? $text;
        if (!str_contains($compact, '財務報告') || !str_contains($compact, '董事會')) {
            return false;
        }

        $rocYear = (string) ($year - 1911);
        $paddedRocYear = str_pad($rocYear, 3, '0', STR_PAD_LEFT);
        $chineseQuarter = $this->chineseQuarter($quarter);
        $labels = [
            $rocYear . '年第' . $quarter . '季',
            $rocYear . '年度第' . $quarter . '季',
            '民國' . $rocYear . '年度第' . $quarter . '季',
            $paddedRocYear . '年第' . $quarter . '季',
            $paddedRocYear . '年度第' . $quarter . '季',
            $rocYear . '年' . $chineseQuarter . '季',
            $rocYear . '年度' . $chineseQuarter . '季',
            '民國' . $rocYear . '年度' . $chineseQuarter . '季',
            $paddedRocYear . '年' . $chineseQuarter . '季',
            $paddedRocYear . '年度' . $chineseQuarter . '季',
        ];

        foreach ($labels as $label) {
            if (str_contains($compact, $label)) {
                return true;
            }
        }

        return false;
    }

    private function chineseQuarter(int $quarter): string
    {
        return match ($quarter) {
            1 => '第一',
            2 => '第二',
            3 => '第三',
            4 => '第四',
            default => '第' . $quarter,
        };
    }

    /**
     * @param array<string, mixed> $sourceMeta
     * @return array<string, mixed>|null
     */
    private function financialAnnouncementTextToRow(
        string $text,
        string $stockCode,
        int $year,
        int $quarter,
        string $source,
        ?string $sourceUrl,
        array $sourceMeta = [],
    ): ?array {
        $valueText = $this->normalizeAnnouncementItemSeparators($text);
        $compact = preg_replace('/\s+/u', '', $valueText) ?? $valueText;
        $rocYear = $year - 1911;
        if (!str_contains($compact, sprintf('%03d/01/01~%03d/03/31', $rocYear, $rocYear))
            && !str_contains($compact, sprintf('%d/01/01~%d/03/31', $rocYear, $rocYear))) {
            return null;
        }

        $revenueThousand = $this->announcementValue($valueText, '營業收入');
        $grossProfitThousand = $this->announcementValue($valueText, '營業毛利');
        $operatingProfitThousand = $this->announcementValue($valueText, '營業利益');
        $netIncomeThousand = $this->announcementValue($valueText, '本期淨利');
        $parentNetIncomeThousand = $this->announcementValue($valueText, '歸屬於母公司業主淨利');
        $eps = $this->announcementValue($valueText, '基本每股盈餘');
        $totalAssetsThousand = $this->announcementValue($valueText, '期末總資產');
        $parentEquityThousand = $this->announcementValue($valueText, '期末歸屬於母公司業主之權益');

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
            '_source' => $source,
            '_source_url' => $sourceUrl,
            '_raw' => [
                ...$sourceMeta,
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

    private function normalizeAnnouncementItemSeparators(string $text): string
    {
        return (string) preg_replace(
            '/(?<!^)(?=(?:[1-9]|1[0-4])\\.(?:提報|審計|財務報告|1月1日|期末|其他))/u',
            "\n",
            $text,
        );
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
        $cacheKey = implode(':', [
            (string) ($candidate['exchange'] ?? ''),
            (string) ($candidate['stock_code'] ?? ''),
            (string) ($candidate['latest_price_date'] ?? ''),
        ]);
        if (array_key_exists($cacheKey, $this->officialDailyRowsCache)) {
            return $this->officialDailyRowsCache[$cacheKey];
        }

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

        return $this->officialDailyRowsCache[$cacheKey] = $rows;
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
        if (array_key_exists($stockCode, $this->nstockDailyRowsCache)) {
            return $this->nstockDailyRowsCache[$stockCode];
        }

        try {
            $response = $this->http()
                ->get(self::NSTOCK_DAILY_STOCK_DATA_URL, ['stock_id' => $stockCode])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return $this->nstockDailyRowsCache[$stockCode] = [];
        }

        if (!is_array($response) || !is_array($response['data'] ?? null)) {
            return $this->nstockDailyRowsCache[$stockCode] = [];
        }

        foreach ($response['data'] as $stockData) {
            if (!is_array($stockData) || !is_array($stockData['日K'] ?? null)) {
                continue;
            }

            return $this->nstockDailyRowsCache[$stockCode] = array_values(array_filter($stockData['日K'], 'is_array'));
        }

        return $this->nstockDailyRowsCache[$stockCode] = [];
    }

    /**
     * @param array<string, mixed> $candidate
     * @return list<array<string, mixed>>
     */
    private function fetchYahooDailyRows(array $candidate): array
    {
        $cacheKey = (string) ($candidate['exchange'] ?? '') . ':' . (string) ($candidate['stock_code'] ?? '');
        if (array_key_exists($cacheKey, $this->yahooDailyRowsCache)) {
            return $this->yahooDailyRowsCache[$cacheKey];
        }

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

            return $this->yahooDailyRowsCache[$cacheKey] = [];
        }

        $result = $response['chart']['result'][0] ?? null;
        $timestamps = $result['timestamp'] ?? null;
        $quote = $result['indicators']['quote'][0] ?? null;
        if (!is_array($timestamps) || !is_array($quote)) {
            return $this->yahooDailyRowsCache[$cacheKey] = [];
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

        return $this->yahooDailyRowsCache[$cacheKey] = $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRecentMonthlyRevenueRowsFromNstock(string $stockCode, int $months = 60): array
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

            $this->nstockMonthlyRevenueRowsCache[$stockCode] = $rows;

            return array_slice($rows, 0, $months);
        }

        return $this->nstockMonthlyRevenueRowsCache[$stockCode] = [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function fillRecentMonthlyRevenueRowsFromMops(string $stockCode, array $rows, int $months): array
    {
        $rowsByMonth = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $yearMonth = (string) ($row['year_month'] ?? '');
            if (strlen($yearMonth) === 6) {
                $rowsByMonth[$yearMonth] = $row;
            }
        }

        $availableMonths = array_keys($rowsByMonth);
        if ($availableMonths === []) {
            return $rows;
        }

        $latestYearMonth = max($availableMonths);

        try {
            $latestMonth = CarbonImmutable::createFromFormat('Ym', $latestYearMonth)->startOfMonth();
        } catch (Throwable $e) {
            report($e);

            return $rows;
        }

        for ($index = 0; $index < $months; $index++) {
            $yearMonth = $latestMonth->subMonths($index)->format('Ym');
            if (array_key_exists($yearMonth, $rowsByMonth)) {
                continue;
            }

            $mopsRow = $this->fetchMopsMonthlyRevenueRow($stockCode, $yearMonth);
            if ($mopsRow !== null) {
                $rowsByMonth[$yearMonth] = $mopsRow;
            }
        }

        krsort($rowsByMonth);

        return array_slice(array_values($rowsByMonth), 0, $months);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchMopsMonthlyRevenueRow(string $stockCode, string $yearMonth): ?array
    {
        $rows = $this->fetchMopsMonthlyRevenueRows($yearMonth);

        return $rows[$stockCode] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchMopsMonthlyRevenueRows(string $yearMonth): array
    {
        if (array_key_exists($yearMonth, $this->mopsMonthlyRevenueRowsCache)) {
            return $this->mopsMonthlyRevenueRowsCache[$yearMonth];
        }

        $year = (int) substr($yearMonth, 0, 4);
        $month = (int) substr($yearMonth, 4, 2);
        $rocYear = $year - 1911;
        $rows = [];

        foreach (self::MOPS_MONTHLY_REVENUE_MARKETS as $market) {
            try {
                $response = $this->http()
                    ->asForm()
                    ->post(self::MOPS_MONTHLY_REVENUE_DOWNLOAD_URL, [
                        'step' => '9',
                        'functionName' => 'show_file2',
                        'filePath' => sprintf('/t21/%s/', $market),
                        'fileName' => sprintf('t21sc03_%d_%d.csv', $rocYear, $month),
                    ])
                    ->throw()
                    ->body();
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            foreach ($this->parseMopsMonthlyRevenueCsv($response, $yearMonth) as $stockCode => $row) {
                $rows[$stockCode] = $row;
            }
        }

        return $this->mopsMonthlyRevenueRowsCache[$yearMonth] = $rows;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseMopsMonthlyRevenueCsv(string $csv, string $yearMonth): array
    {
        $lines = preg_split('/\r\n|\n|\r/', trim($csv));
        if (!is_array($lines) || $lines === []) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines), ',', '"', '\\');
        if ($header === []) {
            return [];
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]) ?? (string) $header[0];
        $indexes = array_flip($header);
        $required = [
            '公司代號',
            '營業收入-當月營收',
            '營業收入-上月比較增減(%)',
            '營業收入-去年同月增減(%)',
            '累計營業收入-當月累計營收',
            '累計營業收入-前期比較增減(%)',
        ];

        foreach ($required as $field) {
            if (!array_key_exists($field, $indexes)) {
                return [];
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, ',', '"', '\\');
            $stockCode = trim((string) ($columns[$indexes['公司代號']] ?? ''));
            if ($stockCode === '') {
                continue;
            }

            $monthlyRevenueThousand = $this->parseDecimal($columns[$indexes['營業收入-當月營收']] ?? null);
            $cumulativeRevenueThousand = $this->parseDecimal($columns[$indexes['累計營業收入-當月累計營收']] ?? null);

            $rows[$stockCode] = [
                'year_month' => $yearMonth,
                'revenue_billion' => $monthlyRevenueThousand === null
                    ? null
                    : $this->decimal($monthlyRevenueThousand / 100000, 4),
                'revenue_yoy_percent' => $this->decimal(
                    $this->parseDecimal($columns[$indexes['營業收入-去年同月增減(%)']] ?? null),
                    4,
                    100000,
                ),
                'revenue_mom_percent' => $this->decimal(
                    $this->parseDecimal($columns[$indexes['營業收入-上月比較增減(%)']] ?? null),
                    4,
                    100000,
                ),
                'cumulative_revenue_billion' => $cumulativeRevenueThousand === null
                    ? null
                    : $this->decimal($cumulativeRevenueThousand / 100000, 4),
                'cumulative_yoy_percent' => $this->decimal(
                    $this->parseDecimal($columns[$indexes['累計營業收入-前期比較增減(%)']] ?? null),
                    4,
                    100000,
                ),
            ];
        }

        return $rows;
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

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return $this->parseYmdDate($value) ?? $value;
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
