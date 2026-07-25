@if ($paginator->hasPages())
    <nav class="crm-pagination" role="navigation" aria-label="分頁導航">
        <div class="crm-pagination-prev">
            @if ($paginator->onFirstPage())
                <span class="crm-pagination-link crm-pagination-disabled" aria-disabled="true">上一頁</span>
            @else
                <a class="crm-pagination-link" href="{{ $paginator->previousPageUrl() }}" rel="prev">上一頁</a>
            @endif
        </div>

        <div class="crm-pagination-pages">
            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="crm-pagination-dots">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page === $paginator->currentPage())
                            <span class="crm-pagination-page active" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="crm-pagination-page" href="{{ $url }}">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        <div class="crm-pagination-next">
            @if ($paginator->hasMorePages())
                <a class="crm-pagination-link" href="{{ $paginator->nextPageUrl() }}" rel="next">下一頁</a>
            @else
                <span class="crm-pagination-link crm-pagination-disabled" aria-disabled="true">下一頁</span>
            @endif
        </div>
    </nav>
@endif
