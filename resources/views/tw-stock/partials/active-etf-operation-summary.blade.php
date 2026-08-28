<section class="summary-grid" aria-label="摘要">
    <article class="summary-card">
        <div class="summary-label">報告數</div>
        <div class="summary-value">{{ number_format($summary['report_count']) }}</div>
        <div class="summary-note">ETF / 日期組合</div>
    </article>
    <article class="summary-card">
        <div class="summary-label">ETF 數</div>
        <div class="summary-value">{{ number_format($summary['etf_count']) }}</div>
        <div class="summary-note">區間內有報告</div>
    </article>
    <article class="summary-card">
        <div class="summary-label">操作筆數</div>
        <div class="summary-value">{{ number_format($summary['item_count']) }}</div>
        <div class="summary-note">符合目前篩選</div>
    </article>
    <article class="summary-card">
        <div class="summary-label">新增</div>
        <div class="summary-value value-new">{{ number_format($summary['new_count']) }}</div>
        <div class="summary-note">新建倉標的</div>
    </article>
    <article class="summary-card">
        <div class="summary-label">加碼</div>
        <div class="summary-value value-add">{{ number_format($summary['add_count']) }}</div>
        <div class="summary-note">持股張數增加</div>
    </article>
    <article class="summary-card">
        <div class="summary-label">減碼</div>
        <div class="summary-value value-reduce">{{ number_format($summary['reduce_count']) }}</div>
        <div class="summary-note">持股張數降低</div>
    </article>
    <article class="summary-card">
        <div class="summary-label">刪除</div>
        <div class="summary-value value-remove">{{ number_format($summary['remove_count']) }}</div>
        <div class="summary-note">清出成分股</div>
    </article>
    <article class="summary-card">
        <div class="summary-label">無異動</div>
        <div class="summary-value">{{ number_format($summary['no_change_count']) }}</div>
        <div class="summary-note">無標籤變動報告</div>
    </article>
</section>
