<?php

namespace App\Services;

use App\Models\TwStockQ1FinancialReport;
use App\Models\TwStockValuationGroup;
use Illuminate\Support\Facades\Schema;
use Throwable;

class TwStockQ1ValuationService
{
    public const MARKET_REFERENCE_PE = 25.0;

    private const GROUP_PE = [
        'IC設計' => 45.0,
        '記憶體/儲存' => 38.0,
        '半導體製造/設備/材料' => 32.0,
        'AI伺服器/電腦週邊' => 34.0,
        '電子零組件/PCB' => 30.0,
        '通信網路' => 24.0,
        '光電/面板' => 18.0,
        '生技醫療' => 30.0,
        '汽車/電動車' => 22.0,
        '綠能/電力' => 22.0,
        '食品/觀光/消費' => 18.0,
        '金融保險' => 12.0,
        '營建資產' => 13.0,
        '航運' => 12.0,
        '原物料/傳產' => 14.0,
        '其他業' => 16.0,
        '其他' => 20.0,
    ];

    /**
     * @var array<string, float>|null
     */
    private ?array $groupPeMap = null;

    private const STOCK_GROUPS = [
        'IC設計' => [
            '2363', '2379', '2454', '3034', '3035', '3227', '3443', '3529', '3545', '3661',
            '4919', '4966', '5274', '5471', '6104', '6202', '6415', '6462', '6531', '6533',
            '6643', '6695', '6719', '6756', '8016', '8261',
        ],
        '記憶體/儲存' => [
            '2344', '2408', '2451', '3260', '4967', '5289', '6239', '8271', '8299',
        ],
        '半導體製造/設備/材料' => [
            '1560', '1785', '2303', '2330', '3016', '3105', '3131', '3374', '3532', '3680',
            '5347', '6187', '6196', '6488', '6510', '6548', '6667', '6728', '6789', '6854',
            '8028', '8086',
        ],
        'AI伺服器/電腦週邊' => [
            '2301', '2324', '2356', '2357', '2376', '2377', '2382', '2395', '2404', '2421',
            '3017', '3081', '3231', '3324', '3706', '4938', '6669', '8210',
        ],
        '電子零組件/PCB' => [
            '1471', '1504', '1582', '2059', '2313', '2368', '2383', '2392', '2412', '2428',
            '2439', '2458', '2474', '2492', '3013', '3037', '3044', '3189', '3321', '3338',
            '3376', '3450', '3533', '3653', '3715', '4958', '5469', '6269', '6274', '6278',
            '6290', '8046', '8105',
        ],
        '汽車/電動車' => [
            '1319', '1522', '1536', '1568', '1587', '1591', '2201', '2204', '2206', '2227',
            '2231', '2233', '2236', '2239', '2241', '2247', '2250', '2497', '3019', '3665',
            '4763', '6288', '9951',
        ],
        '金融保險' => [
            '2801', '2809', '2812', '2820', '2834', '2836', '2838', '2845', '2849', '2850',
            '2851', '2852', '2855', '2867', '2880', '2881', '2882', '2883', '2884', '2885',
            '2886', '2887', '2888', '2889', '2890', '2891', '2892', '2897', '5876', '5880',
        ],
    ];

    private const KEYWORD_GROUPS = [
        'IC設計' => ['IC設計', '晶片', 'ASIC', '矽智財', 'IP', '聯發科', '瑞昱', '聯詠', '創意', '世芯', '力旺', '智原', '富鼎', '矽統', '威鋒'],
        '記憶體/儲存' => ['記憶體', 'DRAM', 'NAND', '快閃', 'SSD', '模組', '創見', '宜鼎', '南亞科', '華邦電', '威剛', '宇瞻', '群聯'],
        '半導體製造/設備/材料' => ['半導體', '晶圓', '封測', '設備', '材料', '矽晶圓', '台積電', '聯電', '世界', '光洋科', '中砂', '辛耘', '弘塑', '家登'],
        'AI伺服器/電腦週邊' => ['伺服器', '散熱', '電腦', '筆電', '主機板', '顯卡', '機殼', '電源', '緯創', '廣達', '緯穎', '技嘉', '微星', '奇鋐', '雙鴻', '川湖'],
        '電子零組件/PCB' => ['電子零組件', 'PCB', 'CCL', '載板', '連接器', '被動元件', '電源供應器', '台光電', '金像電', '臻鼎', '嘉澤'],
        '通信網路' => ['通信網路', '網通', '光通訊', '5G', '光纖', '交換器', '智邦', '中磊'],
        '光電/面板' => ['光電', '面板', 'LED', '鏡頭', '光學', '友達', '群創', '大立光'],
        '生技醫療' => ['生技', '醫療', '製藥', '藥', '醫材', '保瑞', '藥華藥'],
        '汽車/電動車' => ['汽車', '車用', '電動車', '車燈', 'AM', 'TPMS', '皇田'],
        '綠能/電力' => ['綠能', '太陽能', '風電', '電力', '重電', '儲能'],
        '食品/觀光/消費' => ['食品', '觀光', '餐飲', '百貨', '零售', '旅館', '航空', '安心', '漢來'],
        '金融保險' => ['金融', '銀行', '金控', '保險', '證券'],
        '營建資產' => ['建材營造', '營建', '建設', '資產', 'REIT'],
        '航運' => ['航運', '海運', '貨櫃', '散裝', '航空貨運'],
        '原物料/傳產' => ['水泥', '塑膠', '化工', '鋼鐵', '紡織', '造紙', '橡膠', '玻璃', '油電燃氣'],
    ];

    private const INDUSTRY_GROUPS = [
        '水泥工業' => '原物料/傳產',
        '食品工業' => '食品/觀光/消費',
        '塑膠工業' => '原物料/傳產',
        '紡織纖維' => '原物料/傳產',
        '電機機械' => '原物料/傳產',
        '電器電纜' => '綠能/電力',
        '玻璃陶瓷' => '原物料/傳產',
        '造紙工業' => '原物料/傳產',
        '鋼鐵工業' => '原物料/傳產',
        '橡膠工業' => '原物料/傳產',
        '汽車工業' => '汽車/電動車',
        '建材營造業' => '營建資產',
        '航運業' => '航運',
        '觀光餐旅' => '食品/觀光/消費',
        '金融保險業' => '金融保險',
        '貿易百貨業' => '食品/觀光/消費',
        '其他業' => '其他業',
        '化學工業' => '原物料/傳產',
        '生技醫療業' => '生技醫療',
        '油電燃氣業' => '綠能/電力',
        '半導體業' => '半導體製造/設備/材料',
        '電腦及週邊設備業' => 'AI伺服器/電腦週邊',
        '光電業' => '光電/面板',
        '通信網路業' => '通信網路',
        '電子零組件業' => '電子零組件/PCB',
        '電子通路業' => '電子零組件/PCB',
        '資訊服務業' => 'AI伺服器/電腦週邊',
        '其他電子業' => 'AI伺服器/電腦週邊',
        '文化創意業' => '食品/觀光/消費',
        '農業科技業' => '食品/觀光/消費',
        '電子商務業' => '食品/觀光/消費',
        '綠能環保業' => '綠能/電力',
        '數位雲端業' => 'AI伺服器/電腦週邊',
        '運動休閒業' => '食品/觀光/消費',
        '居家生活業' => '食品/觀光/消費',
        '存託憑證' => '其他業',
    ];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function applyToPayload(array $row): array
    {
        $valuation = $this->valuationForValues(
            (string) ($row['stock_code'] ?? ''),
            (string) ($row['stock_name'] ?? ''),
            $row['industry'] ?? null,
        );

        $row['valuation_group'] = $valuation['valuation_group'];
        $row['valuation_group_pe'] = $valuation['valuation_group_pe'];

        if (isset($row['source_payload']) && is_array($row['source_payload'])) {
            $row['source_payload']['valuation_group'] = $valuation['valuation_group'];
            $row['source_payload']['valuation_group_pe'] = $valuation['valuation_group_pe'];
            $row['source_payload']['valuation_reference'] = 'TWSE/TPEx 2026-05-08 official PE data: all median 21.84, trimmed<=80 average 25.01; group PE is keyed from official industry and stock-specific subgroups.';
        }

        return $row;
    }

    /**
     * @return array{valuation_group: string, valuation_group_pe: float}
     */
    public function valuationForModel(TwStockQ1FinancialReport $row): array
    {
        return $this->valuationForValues(
            (string) $row->stock_code,
            (string) $row->stock_name,
            $row->industry,
        );
    }

    /**
     * @return array{valuation_group: string, valuation_group_pe: float}
     */
    public function valuationForValues(string $stockCode, string $stockName, mixed $industry): array
    {
        $group = $this->resolveGroup($stockCode, $stockName, is_string($industry) ? $industry : null);

        return [
            'valuation_group' => $group,
            'valuation_group_pe' => $this->groupPe($group),
        ];
    }

    private function groupPe(string $group): float
    {
        $map = $this->groupPeMap();

        return $map[$group] ?? $map['其他'] ?? 20.0;
    }

    /**
     * @return array<string, float>
     */
    private function groupPeMap(): array
    {
        if ($this->groupPeMap !== null) {
            return $this->groupPeMap;
        }

        $this->groupPeMap = self::GROUP_PE;

        try {
            if (!Schema::hasTable('tw_stock_valuation_groups')) {
                return $this->groupPeMap;
            }

            $rows = TwStockValuationGroup::query()
                ->get(['group_name', 'average_pe']);
        } catch (Throwable) {
            return $this->groupPeMap;
        }

        foreach ($rows as $row) {
            if ((float) $row->average_pe > 0) {
                $this->groupPeMap[(string) $row->group_name] = (float) $row->average_pe;
            }
        }

        return $this->groupPeMap;
    }

    private function resolveGroup(string $stockCode, string $stockName, ?string $industry): string
    {
        foreach (self::STOCK_GROUPS as $group => $stockCodes) {
            if (in_array($stockCode, $stockCodes, true)) {
                return $group;
            }
        }

        if ($industry !== null && isset(self::INDUSTRY_GROUPS[$industry])) {
            return self::INDUSTRY_GROUPS[$industry];
        }

        $haystack = $industry . ' ' . $stockName;
        foreach (self::KEYWORD_GROUPS as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($haystack, $keyword)) {
                    return $group;
                }
            }
        }

        return '其他';
    }
}
