<?php

namespace App\Services;

use App\Models\TwStockCompanyProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TwStockCompanyProfileService
{
    private const TWSE_COMPANY_PROFILE_URL = 'https://openapi.twse.com.tw/v1/opendata/t187ap03_L';

    private const TPEX_COMPANY_PROFILE_URL = 'https://www.tpex.org.tw/openapi/v1/mopsfin_t187ap03_O';

    private const INDUSTRY_CODE_NAMES = [
        '01' => '水泥工業',
        '02' => '食品工業',
        '03' => '塑膠工業',
        '04' => '紡織纖維',
        '05' => '電機機械',
        '06' => '電器電纜',
        '08' => '玻璃陶瓷',
        '09' => '造紙工業',
        '10' => '鋼鐵工業',
        '11' => '橡膠工業',
        '12' => '汽車工業',
        '14' => '建材營造業',
        '15' => '航運業',
        '16' => '觀光餐旅',
        '17' => '金融保險業',
        '18' => '貿易百貨業',
        '20' => '其他業',
        '21' => '化學工業',
        '22' => '生技醫療業',
        '23' => '油電燃氣業',
        '24' => '半導體業',
        '25' => '電腦及週邊設備業',
        '26' => '光電業',
        '27' => '通信網路業',
        '28' => '電子零組件業',
        '29' => '電子通路業',
        '30' => '資訊服務業',
        '31' => '其他電子業',
        '32' => '文化創意業',
        '33' => '農業科技業',
        '34' => '電子商務業',
        '35' => '綠能環保業',
        '36' => '數位雲端業',
        '37' => '運動休閒業',
        '38' => '居家生活業',
        '91' => '存託憑證',
    ];

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    private array $profilesByExchange = [];

    public function __construct(private readonly TwStockQ1ValuationService $valuationService)
    {
    }

    public function refresh(): int
    {
        $profiles = [];
        foreach (['TWSE', 'TPEx'] as $exchange) {
            $exchangeProfiles = $this->fetchOfficialProfiles($exchange);
            $this->profilesByExchange[$exchange] = $exchangeProfiles;
            $profiles = array_merge($profiles, array_values($exchangeProfiles));
        }

        $this->storeProfiles($profiles);

        return count($profiles);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function profile(string $exchange, string $stockCode): ?array
    {
        $exchange = $this->normalizeExchange($exchange);
        $stockCode = trim($stockCode);
        if ($exchange === null || $stockCode === '') {
            return null;
        }

        $profiles = $this->profilesForExchange($exchange);
        if (isset($profiles[$stockCode])) {
            return $profiles[$stockCode];
        }

        $profiles = $this->fetchOfficialProfiles($exchange);
        if ($profiles !== []) {
            $this->profilesByExchange[$exchange] = $profiles;
            $this->storeProfiles(array_values($profiles));
        }

        return $profiles[$stockCode] ?? null;
    }

    public function industry(string $exchange, string $stockCode): ?string
    {
        $profile = $this->profile($exchange, $stockCode);

        return $profile['industry'] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function profilesForExchange(string $exchange): array
    {
        if (array_key_exists($exchange, $this->profilesByExchange)) {
            return $this->profilesByExchange[$exchange];
        }

        $profiles = $this->loadStoredProfiles($exchange);
        if ($profiles === []) {
            $profiles = $this->fetchOfficialProfiles($exchange);
            $this->storeProfiles(array_values($profiles));
        }

        return $this->profilesByExchange[$exchange] = $profiles;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadStoredProfiles(string $exchange): array
    {
        if (!$this->hasProfileTable()) {
            return [];
        }

        try {
            $rows = TwStockCompanyProfile::query()
                ->where('exchange', $exchange)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $profiles = [];
        foreach ($rows as $row) {
            $profiles[(string) $row->stock_code] = [
                'exchange' => (string) $row->exchange,
                'stock_code' => (string) $row->stock_code,
                'stock_name' => (string) $row->stock_name,
                'industry' => $row->industry,
                'industry_code' => $row->industry_code,
                'valuation_group' => (string) $row->valuation_group,
                'valuation_group_pe' => (float) $row->valuation_group_pe,
                'source_date' => $row->source_date?->toDateString(),
                'source_payload' => is_array($row->source_payload) ? $row->source_payload : null,
            ];
        }

        return $profiles;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchOfficialProfiles(string $exchange): array
    {
        $url = match ($exchange) {
            'TWSE' => self::TWSE_COMPANY_PROFILE_URL,
            'TPEx' => self::TPEX_COMPANY_PROFILE_URL,
            default => null,
        };

        if ($url === null) {
            return [];
        }

        try {
            $rows = Http::timeout(30)->retry(2, 500)->get($url)->throw()->json();
        } catch (Throwable) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $profiles = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $stockCode = trim((string) ($row['公司代號'] ?? $row['SecuritiesCompanyCode'] ?? ''));
            if (!$this->isCommonStockCode($stockCode)) {
                continue;
            }

            $stockName = trim((string) ($row['公司簡稱'] ?? $row['CompanyAbbreviation'] ?? $row['CompanyName'] ?? ''));
            if ($stockName === '') {
                continue;
            }

            $industryRaw = $row['產業別'] ?? $row['SecuritiesIndustryCode'] ?? $row['Industry'] ?? null;
            $industryCode = $this->normalizeIndustryCode($industryRaw);
            $industry = $this->normalizeIndustry($industryCode ?? $industryRaw);
            $valuation = $this->valuationService->valuationForValues($stockCode, $stockName, $industry);
            $sourceDate = $this->parseRocDate((string) ($row['出表日期'] ?? $row['Date'] ?? $row['年月日'] ?? ''));

            $profiles[$stockCode] = [
                'exchange' => $exchange,
                'stock_code' => $stockCode,
                'stock_name' => $stockName,
                'industry' => $industry,
                'industry_code' => $industryCode,
                'valuation_group' => $valuation['valuation_group'],
                'valuation_group_pe' => $valuation['valuation_group_pe'],
                'source_date' => $sourceDate,
                'source_payload' => $row,
            ];
        }

        return $profiles;
    }

    /**
     * @param list<array<string, mixed>> $profiles
     */
    private function storeProfiles(array $profiles): void
    {
        if ($profiles === [] || !$this->hasProfileTable()) {
            return;
        }

        $now = now();
        $rows = array_map(function (array $profile) use ($now): array {
            return [
                'exchange' => $profile['exchange'],
                'stock_code' => $profile['stock_code'],
                'stock_name' => $profile['stock_name'],
                'industry' => $profile['industry'],
                'industry_code' => $profile['industry_code'],
                'valuation_group' => $profile['valuation_group'],
                'valuation_group_pe' => $profile['valuation_group_pe'],
                'source_date' => $profile['source_date'],
                'source_payload' => json_encode($profile['source_payload'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $profiles);

        foreach (array_chunk($rows, 500) as $chunk) {
            TwStockCompanyProfile::query()->upsert(
                $chunk,
                ['exchange', 'stock_code'],
                [
                    'stock_name',
                    'industry',
                    'industry_code',
                    'valuation_group',
                    'valuation_group_pe',
                    'source_date',
                    'source_payload',
                    'fetched_at',
                    'updated_at',
                ],
            );
        }
    }

    private function normalizeExchange(string $exchange): ?string
    {
        return match (strtoupper(trim($exchange))) {
            'TWSE', 'SII', '上市' => 'TWSE',
            'TPEX', 'OTC', '上櫃' => 'TPEx',
            default => null,
        };
    }

    private function normalizeIndustryCode(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        return str_pad($value, 2, '0', STR_PAD_LEFT);
    }

    private function normalizeIndustry(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return self::INDUSTRY_CODE_NAMES[$value]
            ?? self::INDUSTRY_CODE_NAMES[str_pad($value, 2, '0', STR_PAD_LEFT)]
            ?? $value;
    }

    private function parseRocDate(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) === 8) {
            try {
                return CarbonImmutable::create(
                    (int) substr($digits, 0, 4),
                    (int) substr($digits, 4, 2),
                    (int) substr($digits, 6, 2),
                )->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        if (strlen($digits) !== 7) {
            return null;
        }

        $year = (int) substr($digits, 0, 3) + 1911;
        $month = (int) substr($digits, 3, 2);
        $day = (int) substr($digits, 5, 2);

        try {
            return CarbonImmutable::create($year, $month, $day)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function isCommonStockCode(string $stockCode): bool
    {
        return (bool) preg_match('/^[1-9]\d{3}$/', $stockCode);
    }

    private function hasProfileTable(): bool
    {
        try {
            return Schema::hasTable('tw_stock_company_profiles');
        } catch (Throwable) {
            return false;
        }
    }
}
