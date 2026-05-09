@if ($paginator->hasPages())
    <nav class="tw-stock-pagination" role="navigation" aria-label="分頁導航">
        <div class="tw-stock-pagination__list">
            @if ($paginator->onFirstPage())
                <span class="tw-stock-pagination__item disabled" aria-disabled="true" aria-label="上一頁">&laquo;</span>
            @else
                <a class="tw-stock-pagination__item" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="上一頁">&laquo;</a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="tw-stock-pagination__item disabled" aria-disabled="true">{{ $element }}</span>
                @endif

                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="tw-stock-pagination__item active" aria-current="page">{{ $page }}</span>
                        @else
                            <a class="tw-stock-pagination__item" href="{{ $url }}" aria-label="第 {{ $page }} 頁">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a class="tw-stock-pagination__item" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="下一頁">&raquo;</a>
            @else
                <span class="tw-stock-pagination__item disabled" aria-disabled="true" aria-label="下一頁">&raquo;</span>
            @endif
        </div>
    </nav>
@endif
