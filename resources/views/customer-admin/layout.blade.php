<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'CRM 後台')｜Star CRM</title>
    <style>
        :root { --bg:#070916; --panel:rgba(17,22,45,.78); --line:rgba(255,255,255,.09); --text:#f5f7ff; --muted:#96a0be; --purple:#7c5cff; --cyan:#36d9ef; --pink:#ff5fc8; --green:#4ce0a1; }
        * { box-sizing:border-box } html { min-height:100%; background:var(--bg) } body { margin:0; min-height:100vh; color:var(--text); font-family:Inter,"Noto Sans TC","Microsoft JhengHei",sans-serif; background:radial-gradient(circle at 15% 10%,rgba(124,92,255,.2),transparent 28%),radial-gradient(circle at 88% 20%,rgba(54,217,239,.15),transparent 25%),linear-gradient(145deg,#060713,#0b1025 55%,#090919); overflow-x:hidden }
        body:before,body:after { content:""; position:fixed; border-radius:50%; filter:blur(2px); pointer-events:none; z-index:0; animation:float 10s ease-in-out infinite }
        body:before { width:330px;height:330px;background:radial-gradient(circle,rgba(255,95,200,.12),transparent 70%);left:-100px;bottom:-60px }
        body:after { width:260px;height:260px;background:radial-gradient(circle,rgba(54,217,239,.12),transparent 70%);right:-40px;top:35%;animation-delay:-4s }
        @keyframes float { 50% { transform:translate3d(25px,-22px,0) scale(1.07) } }
        a { color:inherit;text-decoration:none } button,input,select,textarea { font:inherit } .app { position:relative;z-index:1;display:grid;grid-template-columns:250px 1fr;min-height:100vh }
        .sidebar { position:sticky;top:0;height:100vh;padding:28px 18px;background:rgba(8,10,25,.78);backdrop-filter:blur(22px);border-right:1px solid var(--line) }
        .brand { display:flex;align-items:center;gap:12px;padding:0 10px 28px;font-weight:800;font-size:20px;letter-spacing:.4px }
        .brand-mark { width:40px;height:40px;display:grid;place-items:center;border-radius:13px;background:linear-gradient(135deg,var(--purple),var(--cyan));box-shadow:0 0 28px rgba(124,92,255,.45) }
        .nav-label { padding:14px 12px 8px;color:#697493;font-size:11px;font-weight:800;letter-spacing:1.7px }
        .nav-link { display:flex;align-items:center;gap:12px;margin:4px 0;padding:12px 14px;border-radius:13px;color:#aab2cb;transition:.2s }
        .nav-link:hover,.nav-link.active { color:white;background:linear-gradient(90deg,rgba(124,92,255,.24),rgba(54,217,239,.08));box-shadow:inset 3px 0 var(--purple) }
        .nav-icon { width:23px;color:var(--cyan);text-align:center }.logout { position:absolute;bottom:24px;left:18px;right:18px }
        .logout button { width:100%;border:1px solid var(--line);background:rgba(255,255,255,.04);color:#b7bfd5;padding:11px;border-radius:12px;cursor:pointer }
        .content { min-width:0;padding:34px 40px 70px }.topbar { display:flex;justify-content:space-between;align-items:center;margin-bottom:28px;gap:20px }
        .eyebrow { color:var(--cyan);font-size:12px;font-weight:800;letter-spacing:2px;text-transform:uppercase }.page-title { margin:6px 0 0;font-size:30px;letter-spacing:-.5px }
        .top-actions { display:flex;gap:10px;align-items:center }.btn { display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:12px;padding:11px 16px;color:white;font-weight:750;cursor:pointer;transition:.2s }
        .btn:hover { transform:translateY(-2px);filter:brightness(1.1) }.btn-primary { background:linear-gradient(135deg,var(--purple),#6254e9);box-shadow:0 8px 28px rgba(124,92,255,.28) }.btn-secondary { background:rgba(255,255,255,.07);border:1px solid var(--line) }.btn-danger { background:rgba(255,75,115,.13);color:#ff8ca8;border:1px solid rgba(255,75,115,.18) }.btn-sm { padding:7px 10px;font-size:13px;border-radius:9px }
        .panel { background:linear-gradient(145deg,rgba(21,27,54,.86),rgba(13,17,36,.8));border:1px solid var(--line);border-radius:20px;box-shadow:0 25px 80px rgba(0,0,0,.22);backdrop-filter:blur(18px) }
        .flash { padding:13px 16px;margin-bottom:18px;border-radius:12px;background:rgba(76,224,161,.1);border:1px solid rgba(76,224,161,.25);color:#8cf1c2 }
        .errors { padding:13px 18px;margin-bottom:18px;border-radius:12px;background:rgba(255,75,115,.09);border:1px solid rgba(255,75,115,.23);color:#ff9bb3 }.errors ul{margin:4px 0;padding-left:20px}
        .table-tools { display:flex;justify-content:space-between;gap:14px;padding:18px;border-bottom:1px solid var(--line) }.search { position:relative;max-width:420px;flex:1 }.search input { padding-left:42px }.search span { position:absolute;left:15px;top:12px;color:var(--muted) }
        input,select,textarea { width:100%;color:var(--text);background:rgba(4,7,18,.55);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:11px 13px;outline:none;transition:.2s }
        input:focus,select:focus,textarea:focus { border-color:var(--cyan);box-shadow:0 0 0 3px rgba(54,217,239,.1) } select option { background:#10152c } textarea { min-height:110px;resize:vertical }
        table { width:100%;border-collapse:collapse } th { padding:13px 16px;text-align:left;color:#7783a5;font-size:12px;letter-spacing:.5px;border-bottom:1px solid var(--line) } td { padding:15px 16px;border-bottom:1px solid rgba(255,255,255,.055);color:#d7dcef;vertical-align:middle } tbody tr { transition:.18s } tbody tr:hover { background:rgba(124,92,255,.07) }.table-wrap{overflow-x:auto}.empty{text-align:center;padding:70px 20px;color:var(--muted)}
        .badge { display:inline-flex;padding:5px 9px;border-radius:999px;background:rgba(54,217,239,.1);color:#72e8f7;border:1px solid rgba(54,217,239,.16);font-size:12px;white-space:nowrap }.actions { display:flex;gap:7px;justify-content:flex-end }
        .pagination { padding:16px 18px;display:flex;justify-content:center }.pagination nav{width:100%}.pagination svg{width:18px}.pagination .flex{display:flex}.pagination a,.pagination span{color:#b8c1dd}
        .form-panel { padding:24px }.form-grid { display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:19px }.field.wide { grid-column:1/-1 }.field label { display:flex;align-items:center;gap:6px;margin-bottom:8px;font-size:13px;font-weight:750;color:#cbd2e8 }.required { color:#ff79c9 }.hint { color:var(--muted);font-size:11px;font-weight:400 }.form-footer { display:flex;justify-content:flex-end;gap:10px;padding-top:24px;margin-top:23px;border-top:1px solid var(--line) }
        .image-drop { position:relative;min-height:190px;border:1.5px dashed rgba(54,217,239,.38);border-radius:16px;background:rgba(54,217,239,.035);display:grid;place-items:center;text-align:center;padding:20px;cursor:pointer;transition:.2s;overflow:hidden }.image-drop:hover,.image-drop.dragging,.image-drop:focus { border-color:var(--cyan);background:rgba(54,217,239,.08);box-shadow:0 0 30px rgba(54,217,239,.08) }.image-drop img { max-height:180px;max-width:100%;border-radius:12px;object-fit:contain }.upload-icon { font-size:32px;color:var(--cyan);margin-bottom:8px }.upload-copy strong{display:block;margin-bottom:5px}.upload-copy small{color:var(--muted);line-height:1.6}.remove-check{margin-top:10px;display:flex;gap:8px;align-items:center}.remove-check input{width:auto}
        .customer-lookup { grid-column:1/-1;padding:18px;border-radius:16px;background:linear-gradient(110deg,rgba(54,217,239,.07),rgba(124,92,255,.08));border:1px solid rgba(54,217,239,.17) }.customer-lookup-head{display:grid;grid-template-columns:minmax(220px,420px) 1fr;gap:20px;align-items:end}.customer-info{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:15px}.customer-info-item{padding:10px 12px;border-radius:10px;background:rgba(4,7,18,.35);min-width:0}.customer-info-item small{display:block;color:#727e9f;margin-bottom:4px}.customer-info-item span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#dfe5f7}.customer-info-empty{grid-column:1/-1;color:var(--muted);font-size:13px;padding:8px 0}
        .stat-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px }.stat { padding:21px;position:relative;overflow:hidden }.stat:after { content:"";position:absolute;width:100px;height:100px;border-radius:50%;right:-35px;top:-45px;background:var(--glow);filter:blur(12px) }.stat-icon { color:var(--accent);font-size:22px }.stat-label { color:var(--muted);font-size:13px;margin:17px 0 7px }.stat-value { font-size:28px;font-weight:850 }.tone-cyan{--accent:#36d9ef;--glow:rgba(54,217,239,.24)}.tone-violet{--accent:#9e83ff;--glow:rgba(124,92,255,.26)}.tone-amber{--accent:#ffc260;--glow:rgba(255,194,96,.22)}.tone-emerald{--accent:#4ce0a1;--glow:rgba(76,224,161,.22)}
        .welcome { padding:25px;margin-bottom:24px;background:linear-gradient(110deg,rgba(124,92,255,.18),rgba(54,217,239,.07));position:relative;overflow:hidden }.welcome h2{margin:0 0 8px}.welcome p{margin:0;color:#aeb7d1}.section-head{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid var(--line)}.section-head h3{margin:0;font-size:16px}
        .order-items { margin-top:22px;padding:20px;border-radius:16px;background:rgba(4,7,18,.32);border:1px solid var(--line) }.order-items h3 { margin:0 }.item-row { display:grid;grid-template-columns:2.3fr .65fr .9fr .9fr auto;gap:10px;align-items:end;padding:14px 0;border-bottom:1px solid rgba(255,255,255,.06) }.item-row label{display:block;color:var(--muted);font-size:11px;margin-bottom:6px}.line-total{height:43px;display:flex;align-items:center;font-weight:800;color:var(--cyan)}.total-bar{display:flex;justify-content:flex-end;gap:28px;padding-top:18px;color:var(--muted)}.total-bar strong{color:white;font-size:21px}
        .thumb { width:44px;height:44px;border-radius:10px;object-fit:cover;vertical-align:middle;margin-right:8px;border:1px solid var(--line) }
        @media(max-width:1000px){.app{grid-template-columns:82px 1fr}.sidebar{padding:24px 12px}.brand span,.nav-link span,.nav-label,.logout span{display:none}.brand{justify-content:center;padding-left:0;padding-right:0}.nav-link{justify-content:center}.logout{left:12px;right:12px}.content{padding:28px 24px}.stat-grid{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:700px){.app{display:block}.sidebar{position:fixed;z-index:20;bottom:0;top:auto;width:100%;height:70px;display:flex;padding:8px;background:rgba(8,10,25,.95);border:0;border-top:1px solid var(--line)}.brand,.nav-label,.logout{display:none}.sidebar nav{display:flex;width:100%;justify-content:space-around}.nav-link{margin:0;padding:10px 12px}.content{padding:22px 14px 95px}.topbar{align-items:flex-start}.page-title{font-size:24px}.top-actions{flex-wrap:wrap;justify-content:flex-end}.stat-grid{grid-template-columns:1fr 1fr;gap:10px}.stat{padding:16px}.stat-value{font-size:22px}.form-grid{grid-template-columns:1fr}.field.wide{grid-column:auto}.customer-lookup{grid-column:auto}.customer-lookup-head{grid-template-columns:1fr}.customer-info{grid-template-columns:1fr 1fr}.item-row{grid-template-columns:1fr 1fr}.item-row>div:first-child{grid-column:1/-1}.table-tools{flex-direction:column}.form-panel{padding:17px}}
    </style>
    @stack('head')
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <a class="brand" href="{{ route('customer-admin.dashboard') }}"><b class="brand-mark">S</b><span>STAR CRM</span></a>
        <div class="nav-label">工作空間</div>
        <nav>
            <a class="nav-link {{ request()->routeIs('customer-admin.dashboard') ? 'active' : '' }}" href="{{ route('customer-admin.dashboard') }}"><b class="nav-icon">⌂</b><span>總覽</span></a>
            @foreach(['contacts'=>['◇','接洽人'],'products'=>['◆','商品'],'orders'=>['▣','訂單'],'addresses'=>['⌖','地址']] as $key=>$nav)
                <a class="nav-link {{ request()->route('module') === $key ? 'active' : '' }}" href="{{ route('customer-admin.module.index', $key) }}"><b class="nav-icon">{{ $nav[0] }}</b><span>{{ $nav[1] }}</span></a>
            @endforeach
        </nav>
        <form class="logout" method="post" action="{{ route('customer-admin.logout') }}">@csrf<button type="submit">↪ <span>安全登出</span></button></form>
    </aside>
    <main class="content">
        <header class="topbar">
            <div><div class="eyebrow">Customer relationship management</div><h1 class="page-title">@yield('title', '營運總覽')</h1></div>
            <div class="top-actions">
                <a class="btn btn-secondary" href="{{ route('customer-admin.export') }}">⇩ 匯出 XLSX</a>
                @yield('top-action')
            </div>
        </header>
        @if(session('success'))<div class="flash">✓ {{ session('success') }}</div>@endif
        @if($errors->any())<div class="errors"><strong>請確認以下欄位：</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @yield('content')
    </main>
</div>
@stack('scripts')
</body>
</html>
