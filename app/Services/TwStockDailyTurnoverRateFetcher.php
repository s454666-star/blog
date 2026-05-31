<?php

namespace App\Services;

use App\Models\TwStockDailyTurnoverRate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockDailyTurnoverRateFetcher
{
    private const TWSE_MI_INDEX_URL = 'https://www.twse.com.tw/exchangeReport/MI_INDEX';

    private const TWSE_COMPANY_PROFILE_URL = 'https://openapi.twse.com.tw/v1/opendata/t187ap03_L';

    private const TPEX_DAILY_TURNOVER_URL = 'https://www.tpex.org.tw/web/stock/aftertrading/daily_turnover/trn_result.php';

    private const TWSE_ISSUED_SHARES_CACHE_TTL_SECONDS = 21600;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRowsForDate(string|CarbonInterface $date): array
    {
        $tradeDate = $this->date($date);

        return $this->withFetchedAt([
            ...$this->fetchTwseRows($tradeDate),
            ...$this->fetchTpexRows($tradeDate),
        ]);
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

        TwStockDailyTurnoverRate::query()->upsert(
            $payloads,
            ['exchange', 'stock_code', 'trade_date'],
            [
                'stock_name',
                'rank',
                'trading_shares',
                'issued_shares',
                'turnover_rate_percent',
                'source',
                'source_payload',
                'fetched_at',
                'updated_at',
            ],
        );

        return count($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTwseRows(CarbonImmutable $date): array
    {
        $issuedSharesByCode = $this->fetchTwseIssuedSharesMap();
        if ($issuedSharesByCode === []) {
            return [];
        }

        try {
            $payload = $this->http()
                ->get(self::TWSE_MI_INDEX_URL, [
                    'response' => 'json',
                    'date' => $date->format('Ymd'),
                    'type' => 'ALLBUT0999',
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $table = $this->twseDailyTradingTable($payload, $date);
        if ($table === null) {
            return [];
        }

        $fields = $table['fields'];
        $codeIndex = $this->fieldIndex($fields, '證券代號');
        $nameIndex = $this->fieldIndex($fields, '證券名稱');
        $volumeIndex = $this->fieldIndex($fields, '成交股數');
        if ($codeIndex === null || $nameIndex === null || $volumeIndex === null) {
            return [];
        }

        $rows = [];
        foreach ($table['data'] as $row) {
            if (!is_array($row)) {
                continue;
            }

            $stockCode = trim((string) ($row[$codeIndex] ?? ''));
            if (!$this->isCommonStockCode($stockCode)) {
                continue;
            }

            $tradingShares = $this->parseInteger($row[$volumeIndex] ?? null) ?? 0;
            $issuedShares = $issuedSharesByCode[$stockCode] ?? null;

            $rows[] = [
                'exchange' => 'TWSE',
                'stock_code' => $stockCode,
                'stock_name' => trim((string) ($row[$nameIndex] ?? $stockCode)),
                'trade_date' => $date->toDateString(),
                'rank' => null,
                'trading_shares' => $tradingShares,
                'issued_shares' => $issuedShares,
                'turnover_rate_percent' => $issuedShares !== null && $issuedShares > 0
                    ? round(($tradingShares / $issuedShares) * 100, 4)
                    : null,
                'source' => 'TWSE MI_INDEX + t187ap03_L',
                'source_payload' => [
                    'date' => $date->format('Ymd'),
                    'fields' => $fields,
                    'row' => $row,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return array{fields: list<string>, data: list<array<int, mixed>>}|null
     */
    private function twseDailyTradingTable(array $payload, CarbonImmutable $date): ?array
    {
        $tables = $payload['tables'] ?? null;
        if (!is_array($tables)) {
            return null;
        }

        $titleDate = $this->rocTitleDate($date);
        foreach ($tables as $table) {
            if (!is_array($table)) {
                continue;
            }

            $title = (string) ($table['title'] ?? '');
            $fields = $table['fields'] ?? null;
            $data = $table['data'] ?? null;
            if (!is_array($fields) || !is_array($data)) {
                continue;
            }

            $fields = array_values(array_map(fn ($field): string => (string) $field, $fields));
            if (!str_contains($title, $titleDate) || !str_contains($title, '每日收盤行情')) {
                continue;
            }

            if ($this->fieldIndex($fields, '證券代號') === null || $this->fieldIndex($fields, '成交股數') === null) {
                continue;
            }

            return [
                'fields' => $fields,
                'data' => array_values(array_filter($data, 'is_array')),
            ];
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTpexRows(CarbonImmutable $date): array
    {
        try {
            $payload = $this->http()
                ->get(self::TPEX_DAILY_TURNOVER_URL, [
                    'l' => 'zh-tw',
                    't' => 'D',
                    'd' => $this->rocSlashDate($date),
                ])
                ->throw()
                ->json();
        } catch (Throwable $e) {
            report($e);

            return [];
        }

        if (!is_array($payload) || ($payload['date'] ?? null) !== $date->format('Ymd')) {
            return [];
        }

        $table = $payload['tables'][0] ?? null;
        if (!is_array($table)) {
            return [];
        }

        $fields = $table['fields'] ?? [];
        $data = $table['data'] ?? [];
        if (!is_array($fields) || !is_array($data)) {
            return [];
        }

        $fields = array_values(array_map(fn ($field): string => (string) $field, $fields));
        $rankIndex = $this->fieldIndex($fields, '排行');
        $codeIndex = $this->fieldIndex($fields, '股票代號');
        $nameIndex = $this->fieldIndex($fields, '股票名稱');
        $volumeIndex = $this->fieldIndex($fields, '總成交股數');
        $capitalizationIndex = $this->fieldIndex($fields, '發行股數');
        $turnoverIndex = $this->fieldIndex($fields, '週轉率(%)');
        if ($codeIndex === null || $nameIndex === null || $volumeIndex === null || $capitalizationIndex === null || $turnoverIndex === null) {
            return [];
        }

        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }

            $stockCode = trim((string) ($row[$codeIndex] ?? ''));
            if (!$this->isCommonStockCode($stockCode)) {
                continue;
            }

            $rows[] = [
                'exchange' => 'TPEx',
                'stock_code' => $stockCode,
                'stock_name' => trim((string) ($row[$nameIndex] ?? $stockCode)),
                'trade_date' => $date->toDateString(),
                'rank' => $rankIndex === null ? null : $this->parseInteger($row[$rankIndex] ?? null),
                'trading_shares' => $this->parseInteger($row[$volumeIndex] ?? null) ?? 0,
                'issued_shares' => $this->parseInteger($row[$capitalizationIndex] ?? null),
                'turnover_rate_percent' => $this->parseDecimal($row[$turnoverIndex] ?? null),
                'source' => 'TPEx daily_turnover',
                'source_payload' => [
                    'date' => $date->format('Ymd'),
                    'fields' => $fields,
                    'row' => $row,
                ],
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, int>
     */
    private function fetchTwseIssuedSharesMap(): array
    {
        return Cache::remember(
            'tw-stock:daily-turnover:twse-issued-shares:v1',
            now()->addSeconds(self::TWSE_ISSUED_SHARES_CACHE_TTL_SECONDS),
            function (): array {
                try {
                    $payload = $this->http()
                        ->get(self::TWSE_COMPANY_PROFILE_URL)
                        ->throw()
                        ->json();
                } catch (Throwable $e) {
                    report($e);

                    return [];
                }

                if (!is_array($payload)) {
                    return [];
                }

                $shares = [];
                foreach ($payload as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $stockCode = trim((string) ($row['公司代號'] ?? ''));
                    $issuedShares = $this->parseInteger($row['已發行普通股數或TDR原股發行股數'] ?? null);
                    if ($this->isCommonStockCode($stockCode) && $issuedShares !== null && $issuedShares > 0) {
                        $shares[$stockCode] = $issuedShares;
                    }
                }

                return $shares;
            },
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withFetchedAt(array $rows): array
    {
        $now = now();

        return array_map(function (array $row) use ($now): array {
            $row['fetched_at'] = $now;

            return $row;
        }, $rows);
    }

    private function date(string|CarbonInterface $date): CarbonImmutable
    {
        if ($date instanceof CarbonInterface) {
            return CarbonImmutable::instance($date)->setTimezone('Asia/Taipei')->startOfDay();
        }

        return CarbonImmutable::parse($date, 'Asia/Taipei')->startOfDay();
    }

    private function rocTitleDate(CarbonImmutable $date): string
    {
        return sprintf('%d年%02d月%02d日', $date->year - 1911, $date->month, $date->day);
    }

    private function rocSlashDate(CarbonImmutable $date): string
    {
        return sprintf('%03d/%02d/%02d', $date->year - 1911, $date->month, $date->day);
    }

    /**
     * @param list<string> $fields
     */
    private function fieldIndex(array $fields, string $name): ?int
    {
        $index = array_search($name, $fields, true);

        return $index === false ? null : (int) $index;
    }

    private function isCommonStockCode(string $stockCode): bool
    {
        return preg_match('/^[1-9]\d{3}$/', $stockCode) === 1;
    }

    private function parseDecimal(mixed $value): ?float
    {
        $normalized = str_replace([',', "\xc2\xa0", ' ', '%', '+'], '', trim((string) $value));

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

    private function http(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(2, 300)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'Mozilla/5.0']);
    }
}
