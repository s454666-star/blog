{{-- Shared tw-stock page switcher. Order, labels and icons live in App\Support\TwStockPages. --}}
@php
    $sxCurrentPage = \App\Support\TwStockPages::currentKey();
@endphp
<nav class="{{ $navClass ?? 'nav-actions' }} sx-nav" aria-label="台股頁面">
    <a class="sx-nav-home {{ $sxCurrentPage === 'portal' ? 'active' : '' }}" href="{{ route('tw-stock.index') }}" title="台股資訊站首頁">
        {!! \App\Support\TwStockPages::iconSvg('portal', 16) !!}<span>首頁</span>
    </a>
    @foreach (\App\Support\TwStockPages::all() as $sxKey => $sxPage)
        <a class="{{ $sxCurrentPage === $sxKey ? 'active' : '' }}" href="{{ route($sxPage['route']) }}" data-sx-page="{{ $sxKey }}">
            {!! \App\Support\TwStockPages::iconSvg($sxKey, 16) !!}<span>{{ $sxPage['label'] }}</span>
        </a>
    @endforeach
</nav>
@if ($sxCurrentPage !== null)
    <template id="sx-page-badge">{!! \App\Support\TwStockPages::badgeSvg($sxCurrentPage, 52) !!}</template>
@endif
