@if ($paginator->hasPages())
    <nav class="pager" role="navigation" aria-label="分頁導航">
        <div class="pager-links">
            @if ($paginator->onFirstPage())
                <span class="pager-btn pager-step is-disabled" aria-disabled="true">‹ 上一頁</span>
            @else
                <a class="pager-btn pager-step" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ 上一頁</a>
            @endif

            <div class="pager-pages">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="pager-gap" aria-hidden="true">…</span>
                    @endif

                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="pager-btn pager-num is-current" aria-current="page">{{ $page }}</span>
                            @else
                                <a class="pager-btn pager-num" href="{{ $url }}" aria-label="第 {{ $page }} 頁">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </div>

            @if ($paginator->hasMorePages())
                <a class="pager-btn pager-step" href="{{ $paginator->nextPageUrl() }}" rel="next">下一頁 ›</a>
            @else
                <span class="pager-btn pager-step is-disabled" aria-disabled="true">下一頁 ›</span>
            @endif
        </div>

        <form class="pager-jump" method="get" action="{{ url()->current() }}">
            <input type="hidden" name="per_page" value="{{ $paginator->perPage() }}">
            <span>第</span>
            <input type="number" name="page" min="1" max="{{ $paginator->lastPage() }}" value="{{ $paginator->currentPage() }}" aria-label="跳到頁數">
            <span>/ {{ $paginator->lastPage() }} 頁</span>
            <button type="submit">前往</button>
        </form>
    </nav>
@endif
