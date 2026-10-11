@if ($paginator->hasPages())
    @php
        $sxChevronLeft = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg>';
        $sxChevronRight = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
        $sxPageTemplate = $paginator->url(987654321);
    @endphp
    <nav class="tw-stock-pagination" role="navigation" aria-label="分頁導航">
        <div class="tw-stock-pagination__list">
            @if ($paginator->onFirstPage())
                <span class="tw-stock-pagination__item step disabled" aria-disabled="true" aria-label="上一頁">{!! $sxChevronLeft !!}<span>上一頁</span></span>
            @else
                <a class="tw-stock-pagination__item step" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="上一頁">{!! $sxChevronLeft !!}<span>上一頁</span></a>
            @endif

            @foreach ($elements as $element)
                @if (is_string($element))
                    <span class="tw-stock-pagination__item ellipsis" aria-hidden="true">···</span>
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
                <a class="tw-stock-pagination__item step" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="下一頁"><span>下一頁</span>{!! $sxChevronRight !!}</a>
            @else
                <span class="tw-stock-pagination__item step disabled" aria-disabled="true" aria-label="下一頁"><span>下一頁</span>{!! $sxChevronRight !!}</span>
            @endif
        </div>

        <form class="tw-stock-pagination__jump" data-sx-page-jump data-url-template="{{ $sxPageTemplate }}" data-last-page="{{ $paginator->lastPage() }}">
            <label for="sx-page-jump-{{ $paginator->getPageName() }}">第</label>
            <input id="sx-page-jump-{{ $paginator->getPageName() }}" type="number" inputmode="numeric" min="1" max="{{ $paginator->lastPage() }}" value="{{ $paginator->currentPage() }}" aria-label="輸入頁碼">
            <span>/ {{ $paginator->lastPage() }} 頁</span>
            <button class="sx-plain" type="submit">前往</button>
        </form>
    </nav>
@endif
