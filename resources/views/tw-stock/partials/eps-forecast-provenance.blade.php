@if($source)
    <div class="stock-meta">
        @if($source['source_url'])<a href="{{ $source['source_url'] }}" target="_blank" rel="noopener">{{ $source['source_label'] }} ↗</a>@else 無可信來源 @endif
    </div>
    @if($source['source_date'])
        <div class="stock-meta">{{ match ($source['date_type'] ?? '') { 'article_publication' => '發布日', 'calculation_date' => '推估日', default => '資料日' } }} {{ $source['source_date'] }}</div>
    @endif
    @if(($source['source_type'] ?? '') === 'site_neutral')
        <div class="stock-meta" style="color:var(--gold)">{{ ($source['estimate_kind'] ?? '') === 'industry_model' ? '本站模型估算，非機構或 FactSet 預測' : '本站中性推估，非分析師共識' }}</div>
    @endif
    @if(!empty($source['method']))
        <details class="stock-meta" style="max-width:320px;white-space:normal;text-align:left">
            <summary>方法、股數口徑與不確定性</summary>
            <p>{{ $source['method'] }}</p>
            @foreach($source['assumptions'] ?? [] as $assumption)<p>{{ $assumption }}</p>@endforeach
            @if(!empty($source['sensitivity']))
                <p><strong>敏感度情境（非機率或信賴區間）</strong></p>
                @foreach($source['sensitivity'] as $scenario)
                    <p>{{ $scenario['label'] }}：EPS {{ number_format($scenario['eps'], 2) }} 元；{{ $scenario['assumption'] }}</p>
                @endforeach
            @endif
            @if(!empty($source['uncertainty']))<p>{{ $source['uncertainty'] }}</p>@endif
            @foreach($source['references'] ?? [] as $reference)
                <p><a href="{{ $reference['url'] }}" target="_blank" rel="noopener">{{ $reference['label'] }} ↗</a></p>
            @endforeach
        </details>
    @endif
    @if(!empty($source['assessed_at']))<div class="stock-meta">人工評估 {{ $source['assessed_at'] }}；來源日期未重設</div>@endif
    @if($source['analyst_count'] !== null)
        <div class="stock-meta">{{ $source['analyst_count'] }} 位分析師{{ $source['analyst_count'] === 1 ? '（小樣本）' : '' }}</div>
    @endif
    @if(!empty($source['alternate_source']))
        <details class="stock-meta"><summary>來源數值有差異</summary>
            <a href="{{ $source['alternate_source']['source_url'] }}" target="_blank" rel="noopener">另一來源 {{ $source['alternate_source']['source_date'] }}：{{ $source['alternate_source']['value'] ?? '缺值' }} ↗</a>
            <div>{{ $source['selection_note'] ?? '採日期較新者；同日優先原始資料。新聞僅有發布日。' }}</div>
        </details>
    @endif
    @if($source['status'] === 'stale')<div class="stock-meta" style="color:var(--gold)">過期：超過 90 天</div>@endif
    @if($source['status'] === 'missing')<div class="stock-meta">未取得此年度估值，不外推</div>@endif
@endif
