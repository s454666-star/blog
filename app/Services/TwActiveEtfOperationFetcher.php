<?php

namespace App\Services;

use App\Models\TwActiveEtf;
use App\Models\TwActiveEtfOperationItem;
use App\Models\TwActiveEtfOperationReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwActiveEtfOperationFetcher
{
    private const TWSE_ACTIVE_ETF_URL = 'https://www.twse.com.tw/rwd/zh/ETF/activeList';

    private const CMONEY_FORUM_URL = 'https://www.cmoney.tw/forum/stock/%s';

    private const CMONEY_DTNO_URL = 'https://customreport.cmoney.tw/app/v2/dtno/JsonCsv';

    private const CMONEY_ACTIVE_ETF_OPERATION_DTNO = 140141644;

    private const CMONEY_TOKEN_CACHE_SECONDS = 1200;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchActiveEtfList(): array
    {
        $payload = $this->http()
            ->get(self::TWSE_ACTIVE_ETF_URL, ['response' => 'json'])
            ->throw()
            ->json();

        if (!is_array($payload) || ($payload['status'] ?? null) !== 'ok') {
            throw new RuntimeException('TWSE 主動式 ETF 清單回應格式不正確。');
        }

        $rows = [];
        foreach (($payload['data'] ?? []) as $row) {
            if (!is_array($row) || count($row) < 4) {
                continue;
            }

            $code = strtoupper(trim((string) $row[0]));
            if ($code === '') {
                continue;
            }

            $rows[] = [
                'stock_code' => $code,
                'stock_name' => trim((string) $row[1]),
                'management_type' => trim((string) $row[2]),
                'etf_category' => trim((string) $row[3]),
                'is_active' => true,
                'source' => 'TWSE activeList',
                'source_payload' => [
                    'fields' => $payload['fields'] ?? null,
                    'row' => $row,
                ],
                'fetched_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function syncActiveEtfs(array $rows): int
    {
        $codes = array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['stock_code'],
            $rows,
        )));

        return DB::transaction(function () use ($rows, $codes): int {
            if ($codes !== []) {
                TwActiveEtf::query()
                    ->whereNotIn('stock_code', $codes)
                    ->update(['is_active' => false]);
            }

            $saved = 0;
            foreach ($rows as $row) {
                TwActiveEtf::query()->updateOrCreate(
                    ['stock_code' => $row['stock_code']],
                    $row,
                );
                $saved++;
            }

            return $saved;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchOperationReports(
        string $etfCode,
        string $etfName,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?string $bearerToken = null,
    ): array {
        $code = strtoupper(trim($etfCode));
        $token = $bearerToken ?: $this->fetchCmoneyGuestToken($code);
        $payload = $this->http()
            ->withToken($token)
            ->asJson()
            ->post(self::CMONEY_DTNO_URL, [
                'Dtno' => self::CMONEY_ACTIVE_ETF_OPERATION_DTNO,
                'Params' => 'AssignID=' . $code,
            ])
            ->throw()
            ->json();

        if (!is_array($payload) || !isset($payload['rows']) || !is_array($payload['rows'])) {
            throw new RuntimeException('CMoney 主動式 ETF 操作日報回應格式不正確。');
        }

        $reports = [];
        foreach ($payload['rows'] as $row) {
            if (!is_array($row) || count($row) < 7) {
                continue;
            }

            $date = $this->parseYmdDate((string) $row[0]);
            if ($date === null) {
                continue;
            }

            if ($from !== null && $date->lt(CarbonImmutable::parse($from->toDateString()))) {
                continue;
            }

            if ($to !== null && $date->gt(CarbonImmutable::parse($to->toDateString()))) {
                continue;
            }

            $dateKey = $date->toDateString();
            if (!isset($reports[$dateKey])) {
                $reports[$dateKey] = [
                    'etf_code' => $code,
                    'etf_name' => $etfName,
                    'operation_date' => $dateKey,
                    'source_kind' => 'cmoney_dtno',
                    'source_url' => sprintf(self::CMONEY_FORUM_URL, rawurlencode($code)),
                    'source_row_count' => 0,
                    'changed_row_count' => 0,
                    'source_payload' => [
                        'dtno' => self::CMONEY_ACTIVE_ETF_OPERATION_DTNO,
                        'columns' => $payload['columns'] ?? null,
                    ],
                    'items' => [],
                    'fetched_at' => now(),
                ];
            }

            $reports[$dateKey]['source_row_count']++;
            $item = $this->operationItemFromRow($code, $etfName, $dateKey, $row);
            if ($item === null) {
                continue;
            }

            $reports[$dateKey]['items'][] = $item;
            $reports[$dateKey]['changed_row_count']++;
        }

        krsort($reports);

        return array_values($reports);
    }

    /**
     * @param array<string, mixed> $reportPayload
     */
    public function storeReport(array $reportPayload): TwActiveEtfOperationReport
    {
        return DB::transaction(function () use ($reportPayload): TwActiveEtfOperationReport {
            $items = $reportPayload['items'] ?? [];
            unset($reportPayload['items']);

            $report = TwActiveEtfOperationReport::query()->updateOrCreate(
                [
                    'etf_code' => $reportPayload['etf_code'],
                    'operation_date' => $reportPayload['operation_date'],
                ],
                $reportPayload,
            );

            TwActiveEtfOperationItem::query()
                ->where('report_id', $report->id)
                ->delete();

            foreach ($items as $item) {
                $report->items()->create($item);
            }

            return $report->refresh();
        });
    }

    public function fetchCmoneyGuestToken(string $referenceCode = '00403A'): string
    {
        $cacheKey = 'tw-stock:active-etf:cmoney-token:v1:' . strtoupper($referenceCode);

        return Cache::remember($cacheKey, now()->addSeconds(self::CMONEY_TOKEN_CACHE_SECONDS), function () use ($referenceCode): string {
            $html = $this->http()
                ->get(sprintf(self::CMONEY_FORUM_URL, rawurlencode(strtoupper($referenceCode))))
                ->throw()
                ->body();

            if (preg_match('/tokens:\{at:"([^"]+)"/', $html, $matches) !== 1) {
                throw new RuntimeException('無法從 CMoney 頁面取得訪客 token。');
            }

            return $matches[1];
        });
    }

    /**
     * @param list<mixed> $row
     * @return array<string, mixed>|null
     */
    private function operationItemFromRow(string $etfCode, string $etfName, string $dateKey, array $row): ?array
    {
        $tag = trim((string) ($row[6] ?? ''));
        $action = $this->actionFromTag($tag);
        if ($action === null) {
            return null;
        }

        $stockCode = trim((string) ($row[2] ?? ''));
        $stockName = trim((string) ($row[3] ?? ''));
        if ($stockCode === '' || $stockName === '') {
            return null;
        }

        return [
            'etf_code' => $etfCode,
            'etf_name' => $etfName,
            'operation_date' => $dateKey,
            'stock_code' => $stockCode,
            'stock_name' => $stockName,
            'action' => $action,
            'action_label' => $this->actionLabel($action),
            'change_shares' => $this->parseInteger($row[4] ?? null),
            'change_lots' => $this->parseDecimal($row[5] ?? null),
            'source_status' => trim((string) ($row[1] ?? '')),
            'source_payload' => [
                'row' => $row,
            ],
            'fetched_at' => now(),
        ];
    }

    private function actionFromTag(string $tag): ?string
    {
        return match ($tag) {
            '新增', '建倉' => 'new',
            '加碼' => 'add',
            '減碼' => 'reduce',
            '刪除', '清倉' => 'remove',
            default => null,
        };
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'new' => '新增',
            'add' => '加碼',
            'reduce' => '減碼',
            'remove' => '刪除',
            default => $action,
        };
    }

    private function parseYmdDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if (!preg_match('/^\d{8}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Ymd', $value, (string) config('app.timezone'));

        return $date instanceof CarbonImmutable ? $date->startOfDay() : null;
    }

    private function parseInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) str_replace([',', ' ', "\xc2\xa0"], '', (string) $value);
    }

    private function parseDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace([',', ' ', "\xc2\xa0"], '', (string) $value);

        return number_format((float) $normalized, 3, '.', '');
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept' => 'application/json,text/html,application/xhtml+xml',
            'Referer' => 'https://www.cmoney.tw/forum/',
        ])
            ->timeout(30)
            ->retry(2, 500);
    }
}
