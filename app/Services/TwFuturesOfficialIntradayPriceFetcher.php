<?php

namespace App\Services;

use App\Models\TwFuturesHourlyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use ZipArchive;

class TwFuturesOfficialIntradayPriceFetcher
{
    public const SOURCE_NAME = 'TAIFEX official futures tick CSV aggregate';

    private const LIST_URL = 'https://www.taifex.com.tw/cht/3/dlFutPrevious30DaysSalesData';

    private const CSV_ZIP_URL_FORMAT = 'https://www.taifex.com.tw/file/taifex/Dailydownload/DailydownloadCSV/Daily_%s.zip';

    private const DEFAULT_EXCHANGE = 'TAIFEX';

    private const DEFAULT_SYMBOL = 'TXF1!';

    private const DEFAULT_SYMBOL_NAME = '台指期近月連續';

    private const DEFAULT_CONTRACT_CODE = 'TX';

    private const SUPPORTED_INTERVALS = ['5', '15', '30', '60'];

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRows(
        ?string $from = null,
        ?string $to = null,
        string $symbol = self::DEFAULT_SYMBOL,
        string $contractCode = self::DEFAULT_CONTRACT_CODE,
        string $interval = '60',
    ): array {
        $interval = $this->normalizeInterval($interval);
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $fromDate = $from !== null && $from !== ''
            ? CarbonImmutable::parse($from, $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->subDays(7)->startOfDay();
        $toDate = $to !== null && $to !== ''
            ? CarbonImmutable::parse($to, $timezone)->endOfDay()
            : CarbonImmutable::now($timezone)->endOfDay();

        if ($fromDate->greaterThan($toDate)) {
            throw new RuntimeException('from 不可晚於 to。');
        }

        $rows = [];
        foreach ($this->availableFileDates($fromDate, $toDate) as $fileDate) {
            foreach ($this->rowsFromFileDate($fileDate, $fromDate, $toDate, $symbol, $contractCode, $interval) as $row) {
                $rows[$row['interval'] . '|' . $row['started_at']] = $row;
            }
        }

        ksort($rows);

        return $this->attachExistingSourceValidation(array_values($rows));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function upsertRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = now();
        $payloads = array_map(function (array $row) use ($now): array {
            $row['source_payload'] = json_encode($row['source_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $rows);

        foreach (array_chunk($payloads, 200) as $chunk) {
            TwFuturesHourlyPrice::query()->upsert(
                $chunk,
                ['exchange', 'symbol', 'interval', 'started_at'],
                [
                    'symbol_name',
                    'started_at_unix',
                    'trade_date',
                    'session_type',
                    'open_price',
                    'high_price',
                    'low_price',
                    'close_price',
                    'volume_contracts',
                    'source',
                    'source_payload',
                    'fetched_at',
                    'updated_at',
                ],
            );
        }

        return count($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function filterRowsWithoutOfficialSource(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $intervals = array_values(array_unique(array_map(
            fn (array $row): string => (string) $row['interval'],
            $rows,
        )));
        $symbols = array_values(array_unique(array_map(
            fn (array $row): string => (string) $row['symbol'],
            $rows,
        )));
        $startedAts = array_values(array_unique(array_map(
            fn (array $row): string => (string) $row['started_at'],
            $rows,
        )));

        $officialKeys = TwFuturesHourlyPrice::query()
            ->whereIn('symbol', $symbols)
            ->whereIn('interval', $intervals)
            ->whereIn('started_at', $startedAts)
            ->where('source', self::SOURCE_NAME)
            ->select(['symbol', 'interval', 'started_at'])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->symbol . '|' . (string) $row->interval . '|' . $this->dateTimeString($row->started_at) => true,
            ])
            ->all();

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => ! isset($officialKeys[(string) $row['symbol'] . '|' . (string) $row['interval'] . '|' . (string) $row['started_at']]),
        ));
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function availableFileDates(CarbonImmutable $fromDate, CarbonImmutable $toDate): array
    {
        $response = Http::timeout(20)
            ->retry(2, 500)
            ->get(self::LIST_URL);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('TAIFEX 前30個交易日期貨每筆成交資料頁讀取失敗：HTTP %d', $response->status()));
        }

        preg_match_all('/DailydownloadCSV\/Daily_(\d{4}_\d{2}_\d{2})\.zip/u', $response->body(), $matches);
        $dates = array_values(array_unique($matches[1] ?? []));
        sort($dates);

        $scanToDate = $toDate->addDays(7)->endOfDay();
        $result = [];
        foreach ($dates as $dateText) {
            $date = CarbonImmutable::createFromFormat('Y_m_d', $dateText, 'Asia/Taipei');
            if ($date === false) {
                continue;
            }

            if ($date->lessThan($fromDate->startOfDay()) || $date->greaterThan($scanToDate)) {
                continue;
            }

            $result[] = $date->startOfDay();
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFromFileDate(
        CarbonImmutable $fileDate,
        CarbonImmutable $fromDate,
        CarbonImmutable $toDate,
        string $symbol,
        string $contractCode,
        string $interval,
    ): array {
        $url = sprintf(self::CSV_ZIP_URL_FORMAT, $fileDate->format('Y_m_d'));
        $contractMonth = $this->frontContractMonthForDate($fileDate);
        $bars = [];

        foreach ($this->recordsFromZipUrl($url) as $record) {
            $commodity = trim((string) ($record['商品代號'] ?? ''));
            $month = trim((string) ($record['到期月份(週別)'] ?? ''));
            if ($commodity !== $contractCode || $month !== $contractMonth) {
                continue;
            }

            $tradedAt = $this->tradeTimestamp($record);
            if ($tradedAt === null) {
                continue;
            }

            $bucket = $this->bucketStartedAt($tradedAt, $interval);
            if ($bucket === null) {
                continue;
            }

            $fileDateInRange = $fileDate->betweenIncluded($fromDate->startOfDay(), $toDate->endOfDay());
            $bucketInRange = $bucket->betweenIncluded($fromDate, $toDate);
            if (! $fileDateInRange && ! $bucketInRange) {
                continue;
            }

            $sessionType = $this->sessionType($tradedAt);
            if ($sessionType === null) {
                continue;
            }

            $price = $this->numberValue($record['成交價格'] ?? null);
            if ($price === null || $price <= 0) {
                continue;
            }

            $volumeBPlusS = (int) ($this->numberValue($record['成交數量(B+S)'] ?? null) ?? 0);
            $key = $bucket->format('Y-m-d H:i:s');
            if (! isset($bars[$key])) {
                $bars[$key] = [
                    'exchange' => self::DEFAULT_EXCHANGE,
                    'symbol' => $symbol,
                    'symbol_name' => self::DEFAULT_SYMBOL_NAME,
                    'interval' => $interval,
                    'started_at' => $key,
                    'started_at_unix' => $bucket->timestamp,
                    'trade_date' => $fileDate->toDateString(),
                    'session_type' => $sessionType,
                    'open_price' => $this->decimal($price),
                    'high_price' => $this->decimal($price),
                    'low_price' => $this->decimal($price),
                    'close_price' => $this->decimal($price),
                    'volume_contracts' => 0,
                    'source' => self::SOURCE_NAME,
                    'source_payload' => [
                        'endpoint' => $url,
                        'source_file_date' => $fileDate->toDateString(),
                        'contract_code' => $contractCode,
                        'contract_month' => $contractMonth,
                        'interval' => $interval,
                        'official_volume_b_plus_s' => 0,
                        'official_tick_count' => 0,
                    ],
                    'fetched_at' => now(),
                ];
            }

            $bars[$key]['high_price'] = $this->decimal(max((float) $bars[$key]['high_price'], $price));
            $bars[$key]['low_price'] = $this->decimal(min((float) $bars[$key]['low_price'], $price));
            $bars[$key]['close_price'] = $this->decimal($price);
            $bars[$key]['volume_contracts'] = (int) round(((int) $bars[$key]['source_payload']['official_volume_b_plus_s'] + $volumeBPlusS) / 2);
            $bars[$key]['source_payload']['official_volume_b_plus_s'] += $volumeBPlusS;
            $bars[$key]['source_payload']['official_tick_count']++;
        }

        ksort($bars);

        return array_values($bars);
    }

    /**
     * @return iterable<array<string, string|null>>
     */
    private function recordsFromZipUrl(string $url): iterable
    {
        $contents = $this->zipContentsFromUrl($url);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'taifex_tick_zip_');
        if ($temporaryPath === false) {
            throw new RuntimeException('無法建立 TAIFEX ZIP 暫存檔。');
        }

        file_put_contents($temporaryPath, $contents);
        $zip = new ZipArchive();
        $zipOpened = false;
        $handle = null;
        try {
            if ($zip->open($temporaryPath) !== true) {
                throw new RuntimeException('無法開啟 TAIFEX 每筆成交 ZIP：' . $url);
            }
            $zipOpened = true;

            $entryName = $zip->getNameIndex(0);
            if ($entryName === false) {
                throw new RuntimeException('TAIFEX 每筆成交 ZIP 內沒有 CSV：' . $url);
            }

            $handle = $zip->getStream($entryName);
            if ($handle === false) {
                throw new RuntimeException('無法讀取 TAIFEX 每筆成交 CSV：' . $url);
            }

            stream_filter_append($handle, 'convert.iconv.CP950/UTF-8', STREAM_FILTER_READ);
            $headers = fgetcsv($handle, 0, ',', '"', '');
            if (! is_array($headers)) {
                return;
            }

            $headers = array_map(fn (string $header): string => trim($this->stripBom($header)), $headers);
            $headerCount = count($headers);
            if ($headerCount === 0 || ! in_array('商品代號', $headers, true)) {
                throw new RuntimeException('TAIFEX 每筆成交 CSV 欄位異常：' . $url);
            }

            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if (! is_array($values) || $values === []) {
                    continue;
                }

                $values = array_pad($values, $headerCount, null);
                $record = array_combine($headers, array_slice($values, 0, $headerCount));
                if (is_array($record)) {
                    yield $record;
                }
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if ($zipOpened) {
                $zip->close();
            }
            @unlink($temporaryPath);
        }
    }

    private function zipContentsFromUrl(string $url): string
    {
        if (app()->runningUnitTests() || config('database.default') === 'sqlite') {
            $response = Http::timeout(60)->get($url);
            if (! $response->successful()) {
                throw new RuntimeException(sprintf('TAIFEX 每筆成交 CSV 下載失敗：HTTP %d %s', $response->status(), $url));
            }

            return $response->body();
        }

        $contents = false;
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 60,
                'header' => implode("\r\n", [
                    'User-Agent: Mozilla/5.0',
                    'Accept: application/zip,*/*',
                ]),
            ],
        ]);
        for ($attempt = 0; $attempt < 3 && $contents === false; $attempt++) {
            if ($attempt > 0) {
                usleep(500_000);
            }

            $contents = @file_get_contents($url, false, $context);
        }

        if ($contents === false) {
            throw new RuntimeException(sprintf('TAIFEX 每筆成交 CSV 下載失敗：%s', $url));
        }

        return $contents;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function attachExistingSourceValidation(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $intervals = array_values(array_unique(array_map(
            fn (array $row): string => (string) $row['interval'],
            $rows,
        )));
        $symbols = array_values(array_unique(array_map(
            fn (array $row): string => (string) $row['symbol'],
            $rows,
        )));
        $startedAts = array_values(array_unique(array_map(
            fn (array $row): string => (string) $row['started_at'],
            $rows,
        )));

        $existingRows = TwFuturesHourlyPrice::query()
            ->whereIn('symbol', $symbols)
            ->whereIn('interval', $intervals)
            ->whereIn('started_at', $startedAts)
            ->select([
                'symbol',
                'interval',
                'started_at',
                'open_price',
                'high_price',
                'low_price',
                'close_price',
                'volume_contracts',
                'source',
            ])
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (string) $row->symbol . '|' . (string) $row->interval . '|' . $this->dateTimeString($row->started_at) => $row,
            ]);

        return array_map(function (array $row) use ($existingRows): array {
            $key = (string) $row['symbol'] . '|' . (string) $row['interval'] . '|' . (string) $row['started_at'];
            $existing = $existingRows[$key] ?? null;
            $row['source_payload']['validation'] = $this->validationPayload($row, $existing);

            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function validationPayload(array $row, ?object $existing): array
    {
        $payload = [
            'primary_source' => self::SOURCE_NAME,
            'secondary_source' => $existing?->source,
            'status' => 'no_existing_secondary_source',
            'mismatches' => [],
        ];

        if ($existing === null) {
            return $payload;
        }

        $mismatches = [];
        foreach ([
            'open_price',
            'high_price',
            'low_price',
            'close_price',
            'volume_contracts',
        ] as $field) {
            $expected = (float) $row[$field];
            $actual = (float) $existing->{$field};
            if (abs($expected - $actual) > 0.0001) {
                $mismatches[] = [
                    'field' => $field,
                    'official' => $field === 'volume_contracts' ? (int) $expected : $expected,
                    'existing' => $field === 'volume_contracts' ? (int) $actual : $actual,
                ];
            }
        }

        $payload['status'] = $mismatches === [] ? 'matched_existing_secondary_source' : 'official_primary_mismatch_with_existing';
        $payload['mismatches'] = $mismatches;

        return $payload;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function tradeTimestamp(array $record): ?CarbonImmutable
    {
        $date = preg_replace('/\D/', '', (string) ($record['成交日期'] ?? ''));
        $time = preg_replace('/\D/', '', (string) ($record['成交時間'] ?? ''));
        if ($date === null || $time === null || strlen($date) !== 8) {
            return null;
        }

        $time = str_pad(substr($time, 0, 6), 6, '0', STR_PAD_LEFT);

        try {
            return CarbonImmutable::create(
                (int) substr($date, 0, 4),
                (int) substr($date, 4, 2),
                (int) substr($date, 6, 2),
                (int) substr($time, 0, 2),
                (int) substr($time, 2, 2),
                (int) substr($time, 4, 2),
                'Asia/Taipei',
            );
        } catch (Throwable) {
            return null;
        }
    }

    private function bucketStartedAt(CarbonImmutable $tradedAt, string $interval): ?CarbonImmutable
    {
        $sessionStart = $this->sessionStart($tradedAt);
        if ($sessionStart === null) {
            return null;
        }

        $elapsedSeconds = $tradedAt->timestamp - $sessionStart->timestamp;
        if ($elapsedSeconds < 0) {
            return null;
        }

        $intervalSeconds = (int) $interval * 60;

        return $sessionStart->addSeconds(intdiv($elapsedSeconds, $intervalSeconds) * $intervalSeconds);
    }

    private function sessionStart(CarbonImmutable $tradedAt): ?CarbonImmutable
    {
        $time = $tradedAt->format('H:i:s');
        if ($time >= '08:45:00' && $time <= '13:45:00') {
            return $tradedAt->setTime(8, 45, 0);
        }

        if ($time >= '15:00:00') {
            return $tradedAt->setTime(15, 0, 0);
        }

        if ($time <= '05:00:00') {
            return $tradedAt->subDay()->setTime(15, 0, 0);
        }

        return null;
    }

    private function sessionType(CarbonImmutable $tradedAt): ?string
    {
        $time = $tradedAt->format('H:i:s');
        if ($time >= '08:45:00' && $time <= '13:45:00') {
            return 'day';
        }

        if ($time >= '15:00:00' || $time <= '05:00:00') {
            return 'night';
        }

        return null;
    }

    private function normalizeInterval(string $interval): string
    {
        $interval = trim($interval);
        if (! in_array($interval, self::SUPPORTED_INTERVALS, true)) {
            throw new RuntimeException(sprintf('不支援的 K 線週期：%s。', $interval));
        }

        return $interval;
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

    private function stripBom(string $value): string
    {
        return preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
    }

    private function dateTimeString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('Y-m-d H:i:s');
        }

        return CarbonImmutable::parse((string) $value, 'Asia/Taipei')->format('Y-m-d H:i:s');
    }
}
