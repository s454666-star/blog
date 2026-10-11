{{-- Opt-in presentation only. Never include on the futures chart. --}}
@php
    // The stock subdomain forwards application pages; static files live on the primary origin.
    $stockExperienceAssetRoot = request()->getHost() === 'stock.mystar.monster'
        ? 'https://mystar.monster/'
        : rtrim(asset(''), '/') . '/';
    $stockExperiencePage = \App\Support\TwStockPages::currentKey();
    $stockExperienceVersion = '20261011-1';
@endphp
@if ($stockExperiencePage !== null)
    {{-- Inline icon: the stock subdomain does not serve /page-favicons, so avoid an extra request entirely. --}}
    <link rel="icon" type="image/svg+xml" href="{{ \App\Support\TwStockPages::faviconDataUri($stockExperiencePage) }}">
    @php($stockExperiencePageMeta = \App\Support\TwStockPages::find($stockExperiencePage))
    <meta name="tw-stock-page" content="{{ $stockExperiencePage }}" data-scene="{{ request()->routeIs('tw-stock.daily-prices.show') ? 'trace' : ($stockExperiencePageMeta['scene'] ?? '') }}" data-from="{{ $stockExperiencePageMeta['from'] }}" data-to="{{ $stockExperiencePageMeta['to'] }}">
@endif
<meta name="theme-color" content="#0080ff">
<link rel="stylesheet" href="{{ $stockExperienceAssetRoot }}css/tw-stock-experience.css?v={{ $stockExperienceVersion }}">
<script src="{{ $stockExperienceAssetRoot }}js/tw-stock-experience.js?v={{ $stockExperienceVersion }}" defer></script>
