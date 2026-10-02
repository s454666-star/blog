@foreach([2026, 2027, 2028] as $sourceYear)
    @php
        $annualSource = $metadata[$sourceYear] ?? [];
        $survey = null;
        if (($annualSource['source_type'] ?? '') === 'factset') {
            $survey = !empty($annualSource['survey_year']) && !empty($annualSource['survey_analyst_count'])
                ? ['year' => $annualSource['survey_year'], 'count' => $annualSource['survey_analyst_count']]
                : config('tw_stock_eps_article_surveys.'.($annualSource['news_id'] ?? 0));
        }
        $count = $annualSource['analyst_count'] ?? null;
        if ($count === null && ($survey['year'] ?? null) === $sourceYear) {
            $count = $survey['count'];
        }
    @endphp
    <div class="stock-meta" style="white-space:nowrap" data-source-year="{{ $sourceYear }}">
        {{ $sourceYear }}：
        @if($column === 'date')
            {{ $annualSource['source_date'] ?? '來源未提供日期' }}
            <small>{{ match ($annualSource['date_type'] ?? '') { 'article_publication' => '發布', 'calculation_date' => '推估', default => '資料' } }}</small>
        @elseif($count !== null)
            {{ $count }} 位
        @elseif(($annualSource['estimate_kind'] ?? '') === 'industry_model')
            本站模型
        @elseif(($annualSource['source_type'] ?? '') === 'site_neutral')
            研究中性參考
        @elseif($survey)
            未分列<span title="此新聞僅披露 {{ $survey['year'] }} 年的調查人數，不能當作 {{ $sourceYear }} 年人數">（{{ $survey['year'] }} 調查 {{ $survey['count'] }} 位）</span>
        @else
            來源未披露人數
        @endif
    </div>
@endforeach
