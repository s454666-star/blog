{{-- Opt-in presentation only. Never include on the futures chart or its home alias. --}}
@php
    // The stock subdomain forwards application pages; static files live on the primary origin.
    $stockExperienceAssetRoot = request()->getHost() === 'stock.mystar.monster'
        ? 'https://mystar.monster/'
        : rtrim(asset(''), '/') . '/';
@endphp
<link rel="stylesheet" href="{{ $stockExperienceAssetRoot }}css/tw-stock-experience.css?v=20260913-1">
<script src="{{ $stockExperienceAssetRoot }}js/tw-stock-experience.js?v=20260913-1" defer></script>
