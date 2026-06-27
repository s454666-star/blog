<?php

namespace App\Services;

use App\Models\TwFuturesDailyPrice;
use App\Models\TwFuturesHourlyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class TwFuturesDailyPriceFetcher
{
    private const TAIFEX_DOWNLOAD_URL = 'https://www.taifex.com.tw/cht/3/futDataDown';

    private const TAIFEX_HISTORY_URL = 'https://www.taifex.com.tw/cht/3/futDailyMarketReport';

    private const DEFAULT_EXCHANGE = 'TAIFEX';

    private const DEFAULT_SYMBOL = 'TXF1!';

    private const DEFAULT_SYMBOL_NAME = '台指期近月連續';

    private const DEFAULT_CONTRACT_CODE = 'TX';

    private const SOURCE_CSV = 'TAIFEX official futures daily CSV';

    private const SOURCE_HISTORY_PAGE = 'TAIFEX official futures history page';

    private const SOURCE_SELF_CALCULATED = '15K self-calculated daily close';

    private const CLOSE_TOLERANCE_POINTS = 1.0;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRows(
        ?string $from = null,
        ?string $to = null,
        ?int $year = null,
        string $symbol = self::DEFAULT_SYMBOL,
        string $contractCode = self::DEFAULT_CONTRACT_CODE,
    ): array {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $fromDate = $from !== null && $from !== ''
            ? CarbonImmutable::parse($from, $timezone)->startOfDay()
            : null;
        $toDate = $to !== null && $to !== ''
            ? CarbonImmutable::parse($to, $timezone)->endOfDay()
            : null;

        if ($fromDate !== null && $toDate !== null && $fromDate->greaterThan($toDate)) {
            throw new RuntimeException('from 不可晚於 to。');
        }

        if ($year !== null && $fromDate === null && $toDate === null) {
            $fromDate = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $timezone);
            $toDate = CarbonImmutable::create($year, 12, 31, 23, 59, 59, $timezone);
        }

        return $this->fetchRangeRows(
            $fromDate ?? CarbonImmutable::now($timezone)->subDays(45)->startOfDay(),
            $toDate ?? CarbonImmutable::now($timezone)->endOfDay(),
            $symbol,
            $contractCode,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{stored: int, skipped: int, mismatches: list<array<string, mixed>>}
     */
    public function upsertVerifiedRows(array $rows): array
    {
        if ($rows === []) {
            return [
                'stored' => 0,
                'skipped' => 0,
                'mismatches' => [],
            ];
        }

        $dates = array_values(array_unique(array_map(
            fn (array $row): string => (string) $row['trade_date'],
            $rows,
        )));
        $selfCalculatedByDate = $this->selfCalculatedDailyRowsByDate((string) $rows[0]['symbol'], $dates);
        $verifiedRows = [];
        $mismatches = [];

        foreach ($rows as $row) {
            [$verifiedRow, $validation] = $this->verifyRow($row, $selfCalculatedByDate[$row['trade_date']] ?? null);
            if ($verifiedRow === null) {
                $mismatches[] = $validation;
                continue;
            }

            $verifiedRows[] = $verifiedRow;
            if (($validation['mismatches'] ?? []) !== []) {
                $mismatches[] = $validation;
            }
        }

        if ($verifiedRows === []) {
            return [
                'stored' => 0,
                'skipped' => count($rows),
                'mismatches' => $mismatches,
            ];
        }

        $now = now();
        $payloads = array_map(function (array $row) use ($now): array {
            $row['source_payload'] = json_encode($row['source_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $row['verified_sources'] = json_encode($row['verified_sources'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $verifiedRows);

        TwFuturesDailyPrice::query()->upsert(
            $payloads,
            ['exchange', 'symbol', 'session_type', 'trade_date'],
            [
                'symbol_name',
                'contract_code',
                'contract_month',
                'open_price',
                'high_price',
                'low_price',
                'close_price',
                'settlement_price',
                'volume_contracts',
                'open_interest',
                'source',
                'source_payload',
                'verified_sources',
                'validation_status',
                'fetched_at',
                'updated_at',
            ],
        );

        return [
            'stored' => count($verifiedRows),
            'skipped' => count($rows) - count($verifiedRows),
            'mismatches' => $mismatches,
        ];
    }

    private function fetchRangeContents(CarbonImmutable $fromDate, CarbonImmutable $toDate, string $contractCode): string
    {
        $response = Http::timeout(45)
            ->retry(2, 500)
            ->asForm()
            ->post(self::TAIFEX_DOWNLOAD_URL, [
                'down_type' => '1',
                'queryStartDate' => $fromDate->format('Y/m/d'),
                'queryEndDate' => $toDate->format('Y/m/d'),
                'commodity_id' => $contractCode,
                'commodity_id2' => '',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('TAIFEX 日行情下載失敗：HTTP %d', $response->status()));
        }

        return $response->body();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRangeRows(
        CarbonImmutable $fromDate,
        CarbonImmutable $toDate,
        string $symbol,
        string $contractCode,
    ): array {
        $rows = [];
        $cursor = $fromDate->startOfDay();
        $last = $toDate->endOfDay();

        while ($cursor->lessThanOrEqualTo($last)) {
            $chunkEnd = $cursor->addDays(30)->endOfDay();
            if ($chunkEnd->greaterThan($last)) {
                $chunkEnd = $last;
            }

            $contents = $this->fetchRangeContents($cursor, $chunkEnd, $contractCode);
            foreach ($this->rowsFromContents($contents, $symbol, $contractCode, $cursor, $chunkEnd, 'range_csv') as $row) {
                $rows[$row['trade_date']] = $row;
            }

            $cursor = $chunkEnd->addDay()->startOfDay();
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFromContents(
        string $contents,
        string $symbol,
        string $contractCode,
        ?CarbonImmutable $fromDate,
        ?CarbonImmutable $toDate,
        string $sourceType,
    ): array {
        $rows = [];
        foreach ($this->csvTextsFromContents($contents) as $csvText) {
            foreach ($this->rowsFromCsvText($csvText, $symbol, $contractCode, $fromDate, $toDate, $sourceType) as $row) {
                $rows[$row['trade_date']] = $row;
            }
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @return list<string>
     */
    private function csvTextsFromContents(string $contents): array
    {
        return [$this->decodeCsvText($contents)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFromCsvText(
        string $csvText,
        string $symbol,
        string $contractCode,
        ?CarbonImmutable $fromDate,
        ?CarbonImmutable $toDate,
        string $sourceType,
    ): array {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('無法建立 TAIFEX CSV 暫存串流。');
        }

        fwrite($handle, $csvText);
        rewind($handle);
        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }

        $headers = array_map(fn (string $header): string => trim($this->stripBom($header)), $headers);
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (! is_array($values) || $values === []) {
                continue;
            }

            $values = array_pad($values, count($headers), null);
            $record = array_combine($headers, array_slice($values, 0, count($headers)));
            if (! is_array($record)) {
                continue;
            }

            $row = $this->dailyRowFromRecord($record, $symbol, $contractCode, $sourceType);
            if ($row === null) {
                continue;
            }

            $tradeDate = CarbonImmutable::parse((string) $row['trade_date'], 'Asia/Taipei');
            if ($fromDate !== null && $tradeDate->lessThan($fromDate->startOfDay())) {
                continue;
            }
            if ($toDate !== null && $tradeDate->greaterThan($toDate->endOfDay())) {
                continue;
            }

            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function dailyRowFromRecord(array $record, string $symbol, string $contractCode, string $sourceType): ?array
    {
        $tradeDate = $this->parseTradeDate($this->recordValue($record, ['交易日期', '日期', 'Date']));
        if ($tradeDate === null) {
            return null;
        }

        $contract = trim((string) $this->recordValue($record, ['契約', '契約代號', 'Contract']));
        $contractMonth = trim((string) $this->recordValue($record, ['到期月份(週別)', 'ContractMonth(Week)']));
        if (
            $contract !== $contractCode
            || $contractMonth === ''
            || str_contains($contractMonth, '/')
            || str_contains($contractMonth, 'W')
            || $contractMonth !== $this->frontContractMonthForDate($tradeDate)
        ) {
            return null;
        }

        $sessionType = $this->normalizeTradingSession((string) $this->recordValue($record, ['交易時段', 'TradingSession']));
        if ($sessionType !== 'day') {
            return null;
        }

        $open = $this->numberValue($this->recordValue($record, ['開盤價', 'Open']));
        $high = $this->numberValue($this->recordValue($record, ['最高價', 'High']));
        $low = $this->numberValue($this->recordValue($record, ['最低價', 'Low']));
        $close = $this->numberValue($this->recordValue($record, ['收盤價', '最後成交價', 'Last']));
        if ($open === null || $high === null || $low === null || $close === null) {
            return null;
        }

        return [
            'exchange' => self::DEFAULT_EXCHANGE,
            'symbol' => $symbol,
            'symbol_name' => self::DEFAULT_SYMBOL_NAME,
            'contract_code' => $contractCode,
            'contract_month' => $contractMonth,
            'trade_date' => $tradeDate->toDateString(),
            'session_type' => 'day',
            'open_price' => $this->decimal($open),
            'high_price' => $this->decimal($high),
            'low_price' => $this->decimal($low),
            'close_price' => $this->decimal($close),
            'settlement_price' => $this->decimalOrNull($this->numberValue($this->recordValue($record, ['結算價', 'SettlementPrice']))),
            'volume_contracts' => (int) ($this->numberValue($this->recordValue($record, ['成交量', '合計成交量', 'Volume'])) ?? 0),
            'open_interest' => $this->integerOrNull($this->numberValue($this->recordValue($record, ['未沖銷契約數', 'OpenInterest']))),
            'source' => self::SOURCE_CSV,
            'source_payload' => [
                'endpoint' => self::TAIFEX_DOWNLOAD_URL,
                'source_type' => $sourceType,
                'contract_code' => $contract,
                'contract_month' => $contractMonth,
                'trading_session' => '一般',
            ],
            'verified_sources' => [],
            'validation_status' => 'pending',
            'fetched_at' => now(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $selfCalculated
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>}
     */
    private function verifyRow(array $row, ?array $selfCalculated): array
    {
        $sources = [
            [
                'name' => self::SOURCE_CSV,
                'close' => (float) $row['close_price'],
            ],
        ];
        $mismatches = [];

        if ($selfCalculated !== null) {
            if ($this->closeMatches((float) $row['close_price'], (float) $selfCalculated['close_price'])) {
                $sources[] = [
                    'name' => self::SOURCE_SELF_CALCULATED,
                    'close' => round((float) $selfCalculated['close_price'], 4),
                    'bar_count' => (int) $selfCalculated['bar_count'],
                ];
            } else {
                $mismatches[] = [
                    'source' => self::SOURCE_SELF_CALCULATED,
                    'close' => round((float) $selfCalculated['close_price'], 4),
                    'expected_close' => (float) $row['close_price'],
                ];
            }
        }

        if (count($sources) < 2) {
            $historyRow = $this->fetchHistoryPageDailyRow(
                CarbonImmutable::parse((string) $row['trade_date'], 'Asia/Taipei'),
                (string) $row['contract_code'],
                (string) $row['contract_month'],
            );
            if ($historyRow !== null && $this->closeMatches((float) $row['close_price'], (float) $historyRow['close_price'])) {
                $sources[] = [
                    'name' => self::SOURCE_HISTORY_PAGE,
                    'close' => round((float) $historyRow['close_price'], 4),
                ];
            } elseif ($historyRow !== null) {
                $mismatches[] = [
                    'source' => self::SOURCE_HISTORY_PAGE,
                    'close' => round((float) $historyRow['close_price'], 4),
                    'expected_close' => (float) $row['close_price'],
                ];
            } else {
                $mismatches[] = [
                    'source' => self::SOURCE_HISTORY_PAGE,
                    'close' => null,
                    'expected_close' => (float) $row['close_price'],
                ];
            }
        }

        $validation = [
            'trade_date' => (string) $row['trade_date'],
            'contract_month' => (string) $row['contract_month'],
            'verified_sources' => $sources,
            'mismatches' => $mismatches,
        ];

        if (count($sources) < 2) {
            return [null, $validation];
        }

        $row['verified_sources'] = $sources;
        $row['validation_status'] = 'verified';
        $row['source_payload']['validation'] = $validation;

        return [$row, $validation];
    }

    /**
     * @param list<string> $dates
     * @return array<string, array<string, mixed>>
     */
    private function selfCalculatedDailyRowsByDate(string $symbol, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $rows = TwFuturesHourlyPrice::query()
            ->where('symbol', $symbol)
            ->where('interval', '15')
            ->where('session_type', 'day')
            ->whereIn('trade_date', $dates)
            ->select([
                'trade_date',
                'started_at',
                'open_price',
                'high_price',
                'low_price',
                'close_price',
            ])
            ->orderBy('trade_date')
            ->orderBy('started_at')
            ->toBase()
            ->get();

        $dailyRows = [];
        foreach ($rows as $row) {
            $tradeDate = $this->dateString($row->trade_date);
            if ($tradeDate === null) {
                continue;
            }

            if (! isset($dailyRows[$tradeDate])) {
                $dailyRows[$tradeDate] = [
                    'open_price' => (float) $row->open_price,
                    'high_price' => (float) $row->high_price,
                    'low_price' => (float) $row->low_price,
                    'close_price' => (float) $row->close_price,
                    'bar_count' => 0,
                ];
            }

            $dailyRows[$tradeDate]['high_price'] = max((float) $dailyRows[$tradeDate]['high_price'], (float) $row->high_price);
            $dailyRows[$tradeDate]['low_price'] = min((float) $dailyRows[$tradeDate]['low_price'], (float) $row->low_price);
            $dailyRows[$tradeDate]['close_price'] = (float) $row->close_price;
            $dailyRows[$tradeDate]['bar_count']++;
        }

        return $dailyRows;
    }

    /**
     * @return array{close_price: float}|null
     */
    private function fetchHistoryPageDailyRow(CarbonImmutable $tradeDate, string $contractCode, string $contractMonth): ?array
    {
        static $cache = [];

        $cacheKey = $tradeDate->toDateString() . ':' . $contractCode . ':' . $contractMonth;
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        try {
            $response = Http::timeout(20)
                ->retry(1, 500)
                ->asForm()
                ->post(self::TAIFEX_HISTORY_URL, [
                    'queryType' => '',
                    'marketCode' => '0',
                    'MarketCode' => '0',
                    'dateaddcnt' => '',
                    'commodity_id' => $contractCode,
                    'commodity_id2' => '',
                    'commodity_idt' => $contractCode,
                    'commodity_id2t' => '',
                    'commodity_id2t2' => '',
                    'queryDate' => $tradeDate->format('Y/m/d'),
                    'button' => '送出查詢',
                ]);
        } catch (Throwable) {
            return $cache[$cacheKey] = null;
        }

        if (! $response->successful()) {
            return $cache[$cacheKey] = null;
        }

        $crawler = new Crawler($response->body());
        $matched = null;
        $crawler->filter('tr')->each(function (Crawler $tr) use (&$matched, $contractCode, $contractMonth): void {
            if ($matched !== null) {
                return;
            }

            $cells = $tr->filter('td')->each(
                fn (Crawler $td): string => trim((string) preg_replace('/\s+/u', ' ', $td->text())),
            );
            if (count($cells) < 6) {
                return;
            }

            if (($cells[0] ?? '') !== $contractCode || trim((string) ($cells[1] ?? '')) !== $contractMonth) {
                return;
            }

            $close = $this->numberValue($cells[5] ?? null);
            if ($close === null) {
                return;
            }

            $matched = [
                'close_price' => $close,
            ];
        });

        return $cache[$cacheKey] = $matched;
    }

    private function decodeCsvText(string $contents): string
    {
        $text = mb_check_encoding($contents, 'UTF-8')
            ? $contents
            : mb_convert_encoding($contents, 'UTF-8', 'CP950');

        return str_replace("\r\n", "\n", $text);
    }

    private function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    }

    /**
     * @param array<string, mixed> $record
     * @param list<string> $keys
     */
    private function recordValue(array $record, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $record)) {
                return $record[$key];
            }
        }

        return null;
    }

    private function parseTradeDate(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{8}$/', $value) === 1) {
                return CarbonImmutable::createFromFormat('Ymd', $value, 'Asia/Taipei') ?: null;
            }

            return CarbonImmutable::parse($value, 'Asia/Taipei')->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeTradingSession(string $value): ?string
    {
        $value = trim($value);
        if ($value === '一般' || strcasecmp($value, 'regular') === 0) {
            return 'day';
        }

        if ($value === '盤後' || strcasecmp($value, 'after-hours') === 0) {
            return 'night';
        }

        return null;
    }

    private function numberValue(mixed $value): ?float
    {
        $value = trim(str_replace([',', '▼', '▲', '%'], '', (string) $value));
        if ($value === '' || $value === '-' || strcasecmp($value, 'null') === 0) {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }

    private function decimalOrNull(?float $value): ?string
    {
        return $value === null ? null : $this->decimal($value);
    }

    private function integerOrNull(?float $value): ?int
    {
        return $value === null ? null : (int) round($value);
    }

    private function closeMatches(float $expected, float $actual): bool
    {
        return abs($expected - $actual) <= self::CLOSE_TOLERANCE_POINTS;
    }

    private function frontContractMonthForDate(CarbonImmutable $date): string
    {
        $month = $date->startOfMonth();
        $frontMonth = $date->greaterThanOrEqualTo($this->thirdWednesday($month)->startOfDay())
            ? $month->addMonthNoOverflow()
            : $month;

        return $frontMonth->format('Ym');
    }

    private function thirdWednesday(CarbonImmutable $month): CarbonImmutable
    {
        $date = $month->startOfMonth();
        while ((int) $date->dayOfWeekIso !== 3) {
            $date = $date->addDay();
        }

        return $date->addWeeks(2);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->toDateString();
        }

        return CarbonImmutable::parse((string) $value, 'Asia/Taipei')->toDateString();
    }
}
