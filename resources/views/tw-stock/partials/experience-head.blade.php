{{-- Opt-in presentation only. Never include on the futures chart. --}}
@php
    // The stock subdomain forwards application pages; static files live on the primary origin.
    $stockExperienceAssetRoot = request()->getHost() === 'stock.mystar.monster'
        ? 'https://mystar.monster/'
        : rtrim(asset(''), '/') . '/';
    $stockExperiencePage = \App\Support\TwStockPages::currentKey();
    $stockExperienceVersion = '20261012-1';
@endphp
@if ($stockExperiencePage !== null)
    {{-- Inline icon: the stock subdomain does not serve /page-favicons, so avoid an extra request entirely. --}}
    <link rel="icon" type="image/svg+xml" href="{{ \App\Support\TwStockPages::faviconDataUri($stockExperiencePage) }}">
    @php($stockExperiencePageMeta = \App\Support\TwStockPages::find($stockExperiencePage))
    <meta name="tw-stock-page" content="{{ $stockExperiencePage }}" data-scene="{{ request()->routeIs('tw-stock.daily-prices.show') ? 'trace' : ($stockExperiencePageMeta['scene'] ?? '') }}" data-from="{{ $stockExperiencePageMeta['from'] }}" data-to="{{ $stockExperiencePageMeta['to'] }}">
@endif
{{-- Apply the saved light/dark choice before first paint so pages never flash the wrong theme. --}}
<script>
    (() => {
        const match = document.cookie.match(/(?:^|;\s*)tw_stock_theme=(light|dark)/);
        document.documentElement.dataset.sxTheme = match ? match[1] : 'light';
        document.documentElement.dataset.sxPage = @json($stockExperiencePage ?? '');
        document.documentElement.dataset.sxRoute = @json(request()->route()?->getName() ?? '');

        // Lightweight-charts palette for the active theme; tracked charts re-skin on toggle.
        const palette = () => document.documentElement.dataset.sxTheme === 'dark'
            ? { background: '#0f1a2e', text: '#c3d2ea', grid: 'rgba(143, 163, 196, 0.12)', border: '#22345a' }
            : { background: '#ffffff', text: '#29425f', grid: 'rgba(15, 39, 71, 0.06)', border: '#dbe8f8' };
        const optionsFor = () => {
            const p = palette();
            return {
                layout: { background: { color: p.background }, textColor: p.text },
                grid: { vertLines: { color: p.grid }, horzLines: { color: p.grid } },
                rightPriceScale: { borderColor: p.border },
                timeScale: { borderColor: p.border },
            };
        };
        const charts = new Set();
        window.twStockChartTheme = optionsFor;
        window.twStockTrackChart = chart => { charts.add(chart); return chart; };
        window.addEventListener('sx-themechange', () => {
            charts.forEach(chart => {
                try { chart.applyOptions(optionsFor()); } catch { charts.delete(chart); }
            });
        });
    })();
</script>
<meta name="theme-color" content="#0080ff">
{{-- Generated per-page dark overrides load first so the shared theme wins any tie. --}}
<link rel="stylesheet" href="{{ $stockExperienceAssetRoot }}css/tw-stock-dark-pages.css?v={{ $stockExperienceVersion }}">
<link rel="stylesheet" href="{{ $stockExperienceAssetRoot }}css/tw-stock-experience.css?v={{ $stockExperienceVersion }}">
<script src="{{ $stockExperienceAssetRoot }}js/tw-stock-experience.js?v={{ $stockExperienceVersion }}" defer></script>
