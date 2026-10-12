<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Star 台股資訊站</title>
    <meta name="description" content="台股財報排名、年度比較、每日漲幅、法人資金、除權息、月營收、EPS 成長、主動 ETF 與指數 K 線的入口。">
    <style>
        .portal {
            position: relative;
            z-index: 1;
            width: min(1240px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 28px 0 72px;
        }

        .portal-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .portal-brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: var(--sx-ink);
            font-size: 17px;
            font-weight: 800;
            letter-spacing: .02em;
            text-decoration: none;
        }

        .portal-brand svg {
            width: 40px;
            height: 40px;
            filter: drop-shadow(0 6px 14px rgba(0, 128, 255, .35));
        }

        .portal-brand small {
            display: block;
            color: var(--sx-muted);
            font-size: 11px;
            font-weight: 650;
            letter-spacing: .14em;
        }

        .portal-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .portal-clock {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border: 1px solid var(--sx-line);
            border-radius: 999px;
            background: rgba(255, 255, 255, .72);
            backdrop-filter: blur(10px);
            color: var(--sx-ink);
            font-size: 13px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            box-shadow: var(--sx-shadow-sm);
        }

        .portal-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 10px;
            border-radius: 999px;
            background: #eef2f8;
            color: #5b6b82;
            font-size: 12px;
        }

        .portal-status::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .portal-status.open {
            background: #e6f6ee;
            color: #0f9960;
        }

        .portal-status.open::before {
            animation: portal-pulse 1.6s ease-in-out infinite;
        }

        @keyframes portal-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(15, 153, 96, .5); }
            50% { box-shadow: 0 0 0 6px rgba(15, 153, 96, 0); }
        }

        .portal-hero {
            position: relative;
            margin: 44px 0 34px;
            padding: clamp(28px, 5vw, 56px);
            overflow: hidden;
            border-radius: 32px;
            color: #fff;
            background:
                radial-gradient(circle at 88% 12%, rgba(255, 255, 255, .28), transparent 34%),
                radial-gradient(circle at 8% 110%, rgba(0, 220, 255, .45), transparent 42%),
                linear-gradient(125deg, #0058d6 0%, #0080ff 42%, #2894ff 72%, #5ab4ff 100%);
            box-shadow: 0 30px 80px rgba(0, 92, 214, .32), inset 0 1px rgba(255, 255, 255, .35);
        }

        .portal-hero::after {
            content: "";
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .08) 1px, transparent 1px);
            background-size: 36px 36px;
            mask-image: linear-gradient(100deg, transparent 30%, #000 75%);
            pointer-events: none;
        }

        .portal-hero svg.portal-chart {
            position: absolute;
            right: -2%;
            bottom: -6%;
            width: min(58%, 640px);
            height: auto;
            opacity: .9;
            pointer-events: none;
        }

        .portal-chart path.line {
            stroke-dasharray: 1400;
            stroke-dashoffset: 1400;
            animation: portal-draw 2.6s cubic-bezier(.3, .7, .2, 1) .3s forwards;
        }

        .portal-chart circle {
            opacity: 0;
            animation: portal-pop .5s ease-out 2.6s forwards;
        }

        @keyframes portal-draw { to { stroke-dashoffset: 0; } }
        @keyframes portal-pop { from { opacity: 0; transform: scale(.2); } to { opacity: 1; transform: none; } }

        .portal-hero-copy {
            position: relative;
            z-index: 1;
            max-width: 640px;
        }

        .portal-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border: 1px solid rgba(255, 255, 255, .35);
            border-radius: 999px;
            background: rgba(255, 255, 255, .14);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .12em;
        }

        .portal-hero h1 {
            margin: 18px 0 14px;
            font-size: clamp(34px, 5.4vw, 64px);
            font-weight: 850;
            line-height: 1.08;
            letter-spacing: -.035em;
            text-wrap: balance;
        }

        .portal-hero h1 em {
            font-style: normal;
            background: linear-gradient(90deg, #fff, #c8f0ff 60%, #fff);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .portal-hero p {
            margin: 0;
            max-width: 52ch;
            color: rgba(255, 255, 255, .88);
            font-size: clamp(14px, 1.4vw, 17px);
            line-height: 1.75;
        }

        .portal-search {
            position: relative;
            display: flex;
            align-items: center;
            max-width: 520px;
            margin-top: 28px;
        }

        .portal-search svg {
            position: absolute;
            left: 18px;
            color: #4d7fc4;
            pointer-events: none;
        }

        .portal-search input {
            width: 100%;
            height: 56px;
            padding: 0 92px 0 50px;
            border: 0;
            border-radius: 18px;
            background: #fff;
            color: var(--sx-ink);
            font: 600 16px/1 inherit;
            box-shadow: 0 16px 40px rgba(0, 50, 140, .25);
            outline: none;
        }

        .portal-search input:focus-visible {
            box-shadow: 0 0 0 4px rgba(255, 255, 255, .55), 0 16px 40px rgba(0, 50, 140, .25);
        }

        .portal-search kbd {
            position: absolute;
            right: 16px;
            padding: 4px 8px;
            border: 1px solid #d3e3f8;
            border-radius: 7px;
            background: #f3f8ff;
            color: #4d6a92;
            font: 700 11px/1.2 "Segoe UI", sans-serif;
        }

        .portal-group {
            margin-top: 34px;
        }

        .portal-group h2 {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 16px;
            color: var(--sx-ink);
            font-size: 15px;
            font-weight: 800;
            letter-spacing: .08em;
        }

        .portal-group h2::after {
            content: "";
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, var(--sx-line-strong), transparent);
        }

        .portal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }

        .portal-card {
            --from: #0080ff;
            --to: #2894ff;
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-height: 188px;
            padding: 22px;
            overflow: hidden;
            border: 1px solid var(--sx-line);
            border-radius: 22px;
            background: rgba(255, 255, 255, .82);
            backdrop-filter: blur(14px);
            color: var(--sx-ink);
            text-decoration: none;
            box-shadow: var(--sx-shadow-sm);
            opacity: 0;
            transform: translateY(14px);
            animation: portal-rise .6s cubic-bezier(.2, .8, .2, 1) forwards;
            animation-delay: calc(var(--i, 0) * 55ms + 150ms);
            transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
        }

        @keyframes portal-rise { to { opacity: 1; transform: none; } }

        .portal-card::before {
            content: "";
            position: absolute;
            inset: -1px;
            background: radial-gradient(420px circle at var(--mx, 80%) var(--my, 0%), color-mix(in srgb, var(--from) 16%, transparent), transparent 45%);
            opacity: 0;
            transition: opacity .25s ease;
            pointer-events: none;
        }

        .portal-card::after {
            content: "";
            position: absolute;
            left: 22px;
            right: 22px;
            bottom: 0;
            height: 3px;
            border-radius: 3px 3px 0 0;
            background: linear-gradient(90deg, var(--from), var(--to));
            transform: scaleX(.18);
            transform-origin: left;
            transition: transform .35s ease;
        }

        .portal-card:hover,
        .portal-card:focus-visible {
            border-color: color-mix(in srgb, var(--from) 45%, #fff);
            box-shadow: 0 22px 50px color-mix(in srgb, var(--from) 24%, transparent);
            transform: translateY(-4px);
            outline: none;
        }

        .portal-card:hover::before,
        .portal-card:focus-visible::before { opacity: 1; }
        .portal-card:hover::after,
        .portal-card:focus-visible::after { transform: scaleX(1); }

        .portal-card-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .portal-card-head > svg {
            width: 54px;
            height: 54px;
            filter: drop-shadow(0 8px 16px color-mix(in srgb, var(--from) 35%, transparent));
            transition: transform .3s ease;
        }

        .portal-card:hover .portal-card-head > svg { transform: rotate(-6deg) scale(1.06); }

        .portal-arrow {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #f0f6ff;
            color: #3a6db5;
            transition: background .25s ease, color .25s ease, transform .25s ease;
        }

        .portal-card:hover .portal-arrow {
            background: linear-gradient(135deg, var(--from), var(--to));
            color: #fff;
            transform: translateX(3px);
        }

        .portal-card h3 {
            margin: 0;
            font-size: 19px;
            font-weight: 800;
            letter-spacing: -.01em;
        }

        .portal-card p {
            margin: 0;
            color: var(--sx-muted);
            font-size: 13.5px;
            line-height: 1.7;
        }

        .portal-card-path {
            margin-top: auto;
            color: #7b8ea8;
            font: 600 11px/1 ui-monospace, "Cascadia Code", Consolas, monospace;
        }

        .portal-empty {
            display: none;
            margin-top: 30px;
            padding: 28px;
            border: 1px dashed var(--sx-line-strong);
            border-radius: 18px;
            color: var(--sx-muted);
            text-align: center;
        }

        .portal-foot {
            margin-top: 48px;
            color: var(--sx-muted);
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 640px) {
            .portal { padding-top: 18px; }
            .portal-hero { margin-top: 24px; border-radius: 24px; }
            .portal-hero svg.portal-chart { width: 90%; opacity: .35; }
            .portal-grid { grid-template-columns: minmax(0, 1fr); }
            .portal-card { min-height: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .portal-card { opacity: 1; transform: none; animation: none; }
            .portal-chart path.line { stroke-dashoffset: 0; animation: none; }
            .portal-chart circle { opacity: 1; animation: none; }
        }
    </style>
    @include('tw-stock.partials.experience-head')
</head>
<body class="tw-stock-experience sx-portal">
@php
    use App\Support\TwStockPages;

    $groups = [
        '基本面研究' => ['financial-reports', 'annual-comparison', 'monthly-revenues', 'eps-growth-rankings', 'upcoming-dividends'],
        '籌碼與盤面' => ['daily-prices', 'institutional-flows', 'active-etf-operations'],
        '指數與期貨' => ['taiex-index-kline', 'taiex-futures-kline'],
    ];
    $cardIndex = 0;
@endphp
<main class="portal" id="sx-main">
    <header class="portal-bar">
        <a class="portal-brand" href="{{ route('tw-stock.index') }}">
            {!! TwStockPages::badgeSvg('portal', 40) !!}
            <span>Star 台股資訊站<small>TAIWAN STOCK WORKSPACE</small></span>
        </a>
        <div class="portal-actions">
            <div class="portal-clock" aria-live="off">
                <span data-portal-clock>--:--:--</span>
                <span class="portal-status" data-portal-status>台股</span>
            </div>
        </div>
    </header>

    <section class="portal-hero" aria-labelledby="portal-title">
        <svg class="portal-chart" viewBox="0 0 640 300" fill="none" aria-hidden="true">
            <defs>
                <linearGradient id="portal-area" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stop-color="#fff" stop-opacity=".35"/>
                    <stop offset="1" stop-color="#fff" stop-opacity="0"/>
                </linearGradient>
            </defs>
            <path d="M0 250 L60 228 L110 240 L170 190 L230 205 L290 150 L350 168 L410 112 L470 128 L530 70 L590 84 L640 40 V300 H0Z" fill="url(#portal-area)"/>
            <path class="line" d="M0 250 L60 228 L110 240 L170 190 L230 205 L290 150 L350 168 L410 112 L470 128 L530 70 L590 84 L640 40" stroke="#fff" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
            <g fill="#fff">
                @foreach ([[170, 190], [290, 150], [410, 112], [530, 70]] as [$cx, $cy])
                    <circle cx="{{ $cx }}" cy="{{ $cy }}" r="6" style="transform-origin: {{ $cx }}px {{ $cy }}px"/>
                @endforeach
            </g>
            <g opacity=".5">
                @foreach ([40, 100, 160, 220, 280, 340, 400, 460, 520, 580] as $i => $x)
                    <rect x="{{ $x }}" y="{{ 300 - (24 + ($i * 37) % 70) }}" width="22" height="{{ 24 + ($i * 37) % 70 }}" rx="4" fill="#fff" fill-opacity=".22"/>
                @endforeach
            </g>
        </svg>
        <div class="portal-hero-copy">
            <span class="portal-eyebrow">TW STOCK · {{ now()->format('Y') }}</span>
            <h1 id="portal-title">看懂台股的<br><em>每一個訊號</em></h1>
            <p>財報評分、營收動能、法人籌碼、除權息與指數走勢集中在同一個工作台。選一個看板開始，或直接搜尋。</p>
            <label class="portal-search">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <span class="sx-visually-hidden">搜尋看板</span>
                <input type="search" placeholder="搜尋看板：財報、營收、ETF…" autocomplete="off" data-portal-search>
                <kbd>/</kbd>
            </label>
        </div>
    </section>

    @foreach ($groups as $groupLabel => $keys)
        <section class="portal-group" data-portal-group>
            <h2>{{ $groupLabel }}</h2>
            <div class="portal-grid">
                @foreach ($keys as $key)
                    @php($page = $pages[$key])
                    <a class="portal-card" href="{{ route($page['route']) }}" style="--from: {{ $page['from'] }}; --to: {{ $page['to'] }}; --i: {{ $cardIndex++ }}"
                       data-portal-card data-search="{{ $page['label'] }} {{ $page['title'] }} {{ $page['description'] }} {{ $key }}">
                        <span class="portal-card-head">
                            {!! TwStockPages::badgeSvg($key, 54) !!}
                            <span class="portal-arrow" aria-hidden="true">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                            </span>
                        </span>
                        <h3>{{ $page['title'] }}</h3>
                        <p>{{ $page['description'] }}</p>
                        <span class="portal-card-path">{{ parse_url(route($page['route']), PHP_URL_PATH) }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    <div class="portal-empty" data-portal-empty>找不到符合的看板，換個關鍵字試試。</div>

    <footer class="portal-foot">資料來源：公開資訊觀測站、證交所、櫃買中心與各公開 API，僅供研究參考。</footer>
</main>
<script>
    (() => {
        const clock = document.querySelector('[data-portal-clock]');
        const status = document.querySelector('[data-portal-status]');
        const formatter = new Intl.DateTimeFormat('zh-TW', {
            timeZone: 'Asia/Taipei', hour12: false, weekday: 'short',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
        });
        const tick = () => {
            const parts = Object.fromEntries(formatter.formatToParts(new Date()).map(part => [part.type, part.value]));
            clock.textContent = `${parts.weekday} ${parts.hour}:${parts.minute}:${parts.second}`;
            const minutes = Number(parts.hour) * 60 + Number(parts.minute);
            const weekday = !['週六', '週日'].includes(parts.weekday);
            const open = weekday && minutes >= 9 * 60 && minutes <= 13 * 60 + 30;
            status.classList.toggle('open', open);
            status.textContent = !weekday ? '休市' : (open ? '盤中' : (minutes < 9 * 60 ? '盤前' : '已收盤'));
        };
        tick();
        setInterval(tick, 1000);

        const input = document.querySelector('[data-portal-search]');
        const cards = Array.from(document.querySelectorAll('[data-portal-card]'));
        const groups = Array.from(document.querySelectorAll('[data-portal-group]'));
        const empty = document.querySelector('[data-portal-empty]');
        input.addEventListener('input', () => {
            const query = input.value.trim().toLowerCase();
            cards.forEach(card => { card.hidden = query !== '' && !card.dataset.search.toLowerCase().includes(query); });
            groups.forEach(group => { group.hidden = !group.querySelector('[data-portal-card]:not([hidden])'); });
            empty.style.display = cards.some(card => !card.hidden) ? 'none' : 'block';
        });
        input.addEventListener('keydown', event => {
            if (event.key === 'Enter') {
                const first = cards.find(card => !card.hidden);
                if (first) location.href = first.href;
            }
        });
        document.addEventListener('keydown', event => {
            if (event.key === '/' && document.activeElement !== input && !event.ctrlKey && !event.metaKey) {
                event.preventDefault();
                input.focus();
            }
        });
        cards.forEach(card => card.addEventListener('pointermove', event => {
            const rect = card.getBoundingClientRect();
            card.style.setProperty('--mx', `${event.clientX - rect.left}px`);
            card.style.setProperty('--my', `${event.clientY - rect.top}px`);
        }));
    })();
</script>
</body>
</html>
