<?php

$assumptions = [
    '來源為 2026/08/18 券商研究的媒體報導，2026／2027 原值為 8.83／16.83 元；本站四捨五入至一位小數作中性參考，未另加成長率。',
    '2026/08/17 公開彙整點名統一證券、2026 EPS 約 8.8 元與目標價 505 元，可交叉參考；尚未取得原報正文，不宣稱已完整核實統一原報，亦不把不同平台轉載算成獨立分析師。',
    '截至 2026/10/02，現有股數 147,329,590 股；9/29 公告待辦現增 4,000,000 股，預計增至 151,329,590 股。10/12 認股基準日、10/20–30 繳款，尚未完成；與 2/5 已完成的 3,000,000 股現增分開。',
    '只考慮本次 400 萬股現增、且全年淨利不變時，每股敏感度約減少 2.64%；這不是 2026 全年實際稀釋率。券商 EPS 是否已計入現增未知，因此不自動重複扣減。',
    '私募可轉債預計潛在 1,601,612 股（發行滿一年始可轉）；第三次公募可轉債面額 4.8 億、轉換價 528 元，另有約 909,091 潛在股。是否／何時轉換及反稀釋調整未定，不把潛在股數直接視為全年流通股數。',
    '官方 2026H1 基本／稀釋加權平均股數為 144.822／148.579 百萬股，基本／稀釋 EPS 3.25／3.16 元；期末股數不是 EPS 的精確分母。',
    '2028 未取得近期機構預估，另列有明確假設的本站產業模型；不沿用已過時的34.61元，也不以年末月產能等比例放大全年EPS。',
];
$references = [
    ['label' => '8/18 券商研究調降報導：原始 8.83／16.83 元', 'url' => 'https://www.mirrormedia.mg/external/amp/setn_1890926'],
    ['label' => '統一投顧公開研究目錄（原報正文未取得）', 'url' => 'https://www.pscnet.com.tw/pscnetInvestmentAdvisor/news/list.do'],
    ['label' => '9/29 現金增資公告', 'url' => 'https://www.moneydj.com/kmdj/news/newsviewer.aspx?a=e7a6d26f-667c-4b00-b38d-627a3ca6809b'],
    ['label' => '公司官方私募可轉債公告', 'url' => 'https://www.topoint.tw/tw/news/2026/%E9%87%8D%E5%A4%A7%E8%A8%8A%E6%81%AF/867'],
    ['label' => '第三次公募可轉債訂價公告', 'url' => 'https://www.moneydj.com/kmdj/news/newsviewer.aspx?a=ce1f55ea-db13-4332-9f04-ad26d2483b0b'],
    ['label' => '公司官方 2026H1 財報：基本與稀釋 EPS 分母', 'url' => 'https://www.topoint.tw/uploads/images/%E5%B0%96%E9%BB%9E115Q2%E8%B2%A1%E5%A0%B1.pdf'],
];
$years = [];
foreach ([2026 => 8.83, 2027 => 16.83] as $year => $reportedEps) {
    $years[$year] = [
        'value' => round($reportedEps, 1), 'reported_eps' => $reportedEps,
        'source_type' => 'site_neutral', 'source_date' => '2026-08-18',
        'date_type' => 'article_publication', 'assessed_at' => '2026-10-02',
        'source_label' => '券商研究／媒體交叉參考（本站中性基準）',
        'source_url' => 'https://www.mirrormedia.mg/external/amp/setn_1890926',
        'method' => $year.' 年原研究 EPS '.$reportedEps.' 元四捨五入至一位小數；不是 FactSet，也不是多機構共識。',
        'selection_note' => '本列採具經營假設調降說明的研究中性基準；FactSet 僅一個估計且股數口徑未明，另列比較，不混合平均。',
        'assumptions' => $assumptions, 'references' => $references,
        'uncertainty' => '屬公開研究的中性參考，不是已核實稀釋後的財測。ASP、稼動率、鎢粉價格、匯率與股數都可能改變結果；新研究或公司公告需重新審核。',
    ];
}

// Explicit industry scenario. Capacity is annual average available capacity, not year-end output.
$modelEps = static fn (float $capacity, float $utilization, float $asp, float $service, float $margin): float =>
    16.8 * (0.70 * (($capacity * $utilization) / (5750 * 0.90)) * $asp + 0.30 * $service) * $margin;
$central = $modelEps(8000, 0.85, 1.03, 1.15, 1.02);
$years[2028] = [
    'value' => round($central, 0), 'unrounded_value' => $central,
    'source_type' => 'site_neutral', 'estimate_kind' => 'industry_model',
    'source_date' => '2026-10-02', 'date_type' => 'calculation_date', 'assessed_at' => '2026-10-02',
    'source_label' => '本站模型估算',
    'source_url' => 'https://www.topoint.tw/uploads/images/Topoint_2026Q2_C%28%E4%B8%8A%E5%82%B3%E7%89%88%29%281%29.pdf',
    'method' => '2028E = 16.8 × {0.70 × [(8000×0.85)/(5750×0.90)] ×1.03 +0.30×1.15} ×1.02 = 22.1466 元，取整數 22.0 元。2027 EPS 錨為本站研究參考16.8；以下前瞻參數皆為本站假設，並非公司財測或機構預估。',
    'selection_note' => '採經人工核對的本站產業模型；新機構來源僅列比較與待審，不默默覆蓋本模型。',
    'assumptions' => [
        '2027平均可用月產能5750萬支：假設2026年底4500萬線性爬至2027年底7000萬；2028平均8000萬：7000萬線性爬至9000萬。這不是把年末產能當成全年產量。',
        '利用率2027=90%、2028=85%，為新產能爬坡留餘地。2027鑽針／服務營收權重70%／30%，參考2026Q2實際68%／32%。',
        '鑽針ASP含產品組合改善+3%、鑽孔服務營收+15%；未假設大幅漲價。模型營收增29.24%，歸母淨利率相對改善2%（例如20%→20.4%，不是增加2個百分點），固定股數口徑EPS增31.82%。',
        '公司2025高階鍍膜比重48%、2026原目標超55%，及2025Q4鑽針利用率93%僅支持方向，不是2028保證。產業AI高層數、HDI需求不直接等於公司EPS。',
        '22.0沿用2027估值股數口徑，不宣稱完全稀釋EPS；原券商2027分母未知，不再次機械式扣現增及CB。現增、CB與加權股數待完整研究及後續公告校準。',
        '純分母敏感度以未取整22.1466計：若2027錨已含現增、未含兩筆CB，×151.329590/153.840293≈21.79元；若全部新股皆未含，×147.329590/153.840293≈21.21元。未計轉股利息回加，非正式稀釋EPS。',
    ],
    'sensitivity' => [
        ['label' => '下行情境', 'eps' => round($modelEps(7500, 0.75, 0.98, 1.05, 0.95), 0),
            'assumption' => '共同2027錨；2028平均產能7500萬、利用率75%、ASP／組合−2%、服務+5%、淨利率相對−5%，16.93取整為17。'],
        ['label' => '中性情境', 'eps' => round($central, 0), 'assumption' => '平均產能8000萬、利用率85%、ASP／組合+3%、服務+15%、淨利率相對+2%。'],
        ['label' => '上行情境', 'eps' => round($modelEps(8250, 0.93, 1.08, 1.25, 1.08), 0),
            'assumption' => '共同2027錨；2028平均產能8250萬、利用率93%、ASP／組合+8%、服務+25%、淨利率相對+8%，27.14取整為27。'],
    ],
    'uncertainty' => '情境不是機率或信賴區間；平均產能差異反映開出早晚，未改公司年底目標。更嚴重衰退仍可能低於17；ASP、稼動率、鎢粉價格、匯率、淨利率與稀釋股數均可能改變結果。',
    'references' => [
        ['label' => '公司2026Q2簡報：產能目標、Q2毛利41.8%及淨利16.5%', 'url' => 'https://www.topoint.tw/uploads/images/Topoint_2026Q2_C%28%E4%B8%8A%E5%82%B3%E7%89%88%29%281%29.pdf'],
        ['label' => '公司Q2產品組合：鑽針68%、服務32%', 'url' => 'https://www.topoint.tw/tw/news/2026/%E8%B2%A1%E5%8B%99%E8%A8%8A%E6%81%AF/892'],
        ['label' => '公司2025Q4簡報：鍍膜組合與利用率', 'url' => 'https://www.topoint.tw/uploads/images/Topoint_2025Q4%28%E4%B8%AD%E6%96%87%29%28final0306%29.pdf'],
        ['label' => '中央社法說交叉報導', 'url' => 'https://www.cna.com.tw/news/afe/202608140276.aspx'],
        ['label' => '9/29下半年暫不再漲價報導', 'url' => 'https://gfemobile.cnyes.com/news/id/6617938'],
        ['label' => 'TPCA產業需求方向，未提供尖點EPS', 'url' => 'https://www.tpca.org.tw/web/news_detail.php?menu_no=163&mod_no=11&news_no=596'],
        ...$references,
    ],
];

return ['stocks' => ['8021' => [
    'stock_name' => '尖點', 'selection_policy' => 'reviewed_reference',
    'reviewed_at' => '2026-10-02', 'reviewed_financial_period' => '2026-06-30',
    'review_url' => 'https://www.topoint.tw/tw/finance/',
    'years' => $years,
]]];
