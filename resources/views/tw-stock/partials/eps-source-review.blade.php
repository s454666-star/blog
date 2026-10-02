<div style="margin-top:12px;font-size:0.8rem">
    <strong>每週來源查核</strong>：{{ $review['checked_at'] }}（與預估來源日分開）
    <p>{{ match ($review['status']) { 'needs_review' => '發現新證據待人工核對；預估未自動更新。', 'partial' => '部分來源查核失敗；保留原預估與原日期，不能宣稱已完整更新。', default => '未取得足以更新預估的新核實證據；保留原預估與原日期。' } }}</p>
    <details><summary>查核紀錄與待審來源</summary>
        @foreach($review['checks'] as $kind => $check)
            <p><a href="{{ $check['url'] }}" target="_blank" rel="noopener">{{ match ($kind) { 'news' => '研究／增資／可轉債公告搜尋', 'financials' => '最新季度財報', default => '公司財務資訊' } }} ↗</a>：{{ $check['status'] === 'ok' ? '已查核' : '查核失敗' }}</p>
        @endforeach
        @foreach($review['candidates'] as $candidate)
            <p><a href="{{ $candidate['url'] }}" target="_blank" rel="noopener">{{ $candidate['title'] }} ↗</a> {{ $candidate['date'] ?? '日期待核對' }}（{{ $candidate['date_type'] === 'financial_period' ? '財報期間，非發布日' : '候選來源，尚未採入預估' }}）</p>
        @endforeach
        <p>更新前須核對每機構最新版本、全年股數與 CB 假設；不平均轉載或將年末產能直接換算 EPS。</p>
    </details>
</div>
