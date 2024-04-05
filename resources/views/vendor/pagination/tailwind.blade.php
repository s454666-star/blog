@if ($paginator->hasPages())
    <nav role="navigation" aria-label="{{ __('分頁導航') }}" class="pagination-nav">
        {{-- 上一頁 --}}
        <div class="pagination-prev">
            @if ($paginator->onFirstPage())
                <span class="pagination-btn disabled">{{ __('上一頁') }}</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="pagination-btn">{{ __('上一頁') }}</a>
            @endif
        </div>

        {{-- 分頁元素 --}}
        <div class="pagination-center">
            @foreach ($elements as $element)
                {{-- "..." 分隔 --}}
                @if (is_string($element))
                    <span class="pagination-btn disabled">{{ $element }}</span>
                @endif

                {{-- 分頁連結 --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="pagination-btn active">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="pagination-btn">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </div>

        {{-- 下一頁 --}}
        <div class="pagination-next">
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="pagination-btn">{{ __('下一頁') }}</a>
            @else
                <span class="pagination-btn disabled">{{ __('下一頁') }}</span>
            @endif
        </div>
    </nav>
@endif
