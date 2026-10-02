<?php

$assumptions = [
    '來源為 2026/08/18 券商研究的媒體報導，2026／2027 原值為 8.83／16.83 元；本站四捨五入至一位小數作中性參考，未另加成長率。',
    '2026/08/17 公開彙整點名統一證券、2026 EPS 約 8.8 元與目標價 505 元，可交叉參考；尚未取得原報正文，不宣稱已完整核實統一原報，亦不把不同平台轉載算成獨立分析師。',
    '截至 2026/10/02，現有股數 147,329,590 股；9/29 公告待辦現增 4,000,000 股，預計增至 151,329,590 股。10/12 認股基準日、10/20–30 繳款，尚未完成；與 2/5 已完成的 3,000,000 股現增分開。',
    '只考慮本次 400 萬股現增、且全年淨利不變時，每股敏感度約減少 2.64%；這不是 2026 全年實際稀釋率。券商 EPS 是否已計入現增未知，因此不自動重複扣減。',
    '私募可轉債預計潛在 1,601,612 股（發行滿一年始可轉）；第三次公募可轉債面額 4.8 億、轉換價 528 元，另有約 909,091 潛在股。是否／何時轉換及反稀釋調整未定，不把潛在股數直接視為全年流通股數。',
    '官方 2026H1 基本／稀釋加權平均股數為 144.822／148.579 百萬股，基本／稀釋 EPS 3.25／3.16 元；期末股數不是 EPS 的精確分母。',
    '2028 尚無近期可核實研究值，保留缺值；不沿用已過時的 34.61 元，也不以年末月產能等比例放大全年 EPS。',
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

return ['stocks' => ['8021' => [
    'stock_name' => '尖點', 'selection_policy' => 'reviewed_reference',
    'reviewed_at' => '2026-10-02', 'reviewed_financial_period' => '2026-06-30',
    'review_url' => 'https://www.topoint.tw/tw/finance/',
    'years' => $years,
]]];
