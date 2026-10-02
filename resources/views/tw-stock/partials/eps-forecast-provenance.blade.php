@if($source)
    <div class="stock-meta">
        @if($source['source_url'])<a href="{{ $source['source_url'] }}" target="_blank" rel="noopener">{{ $source['source_label'] }} ↗</a>@else 無可信來源 @endif
    </div>
    @if($source['source_date'])
        <div class="stock-meta">{{ ($source['date_type'] ?? '') === 'article_publication' ? '發布日' : '資料日' }} {{ $source['source_date'] }}</div>
    @endif
    @if($source['analyst_count'] !== null)
        <div class="stock-meta">{{ $source['analyst_count'] }} 位分析師{{ $source['analyst_count'] === 1 ? '（小樣本）' : '' }}</div>
    @endif
    @if(!empty($source['alternate_source']))
        <details class="stock-meta"><summary>來源數值有差異</summary>
            <a href="{{ $source['alternate_source']['source_url'] }}" target="_blank" rel="noopener">另一來源 {{ $source['alternate_source']['source_date'] }}：{{ $source['alternate_source']['value'] ?? '缺值' }} ↗</a>
            <div>採日期較新者；同日優先原始資料。新聞僅有發布日。</div>
        </details>
    @endif
    @if($source['status'] === 'stale')<div class="stock-meta" style="color:var(--gold)">過期：超過 90 天</div>@endif
    @if($source['status'] === 'missing')<div class="stock-meta">未取得此年度估值，不外推</div>@endif
@endif
