<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#faf2f7">
    <title>@yield('title', '美少女映像管') — 本機甜蜜映像庫</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='18' fill='%23f06292'/%3E%3Cpath d='M25 18L46 32L25 46Z' fill='%23fff'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="{{ asset('css/video-journal.css') }}?v={{ filemtime(public_path('css/video-journal.css')) }}">
    <script src="{{ asset('js/video-journal.js') }}?v={{ filemtime(public_path('js/video-journal.js')) }}" defer></script>
</head>
<body data-fancy-cursor="native">
    <a class="skip-link" href="#main">跳到主要內容</a>
    <div class="ambient" aria-hidden="true"></div>
    <header class="site-header shell">
        <a class="brand" href="{{ route('video-journal.index') }}"><span class="brand-icon">♡</span><span>美少女映像管<span class="brand-en">SOFT CRT ARCHIVE</span></span></a>
        <nav aria-label="主選單"><a class="nav-link" href="{{ route('video-journal.index') }}">我的映像庫 <span>↗</span></a><span class="local-pill"><i></i> 只在這台電腦裡發光</span></nav>
    </header>
    <main id="main" class="shell">@yield('content')</main>
    <footer class="site-footer shell"><span>美少女映像管 <b>LOCAL DREAM TUBE</b></span><span>把喜歡的畫面，收進粉紅螢光裡。 <span class="footer-star">✿</span></span></footer>
    <div id="toast" class="toast" role="status" @if(!session('status')) hidden @endif>{{ session('status') }}</div>
</body>
</html>
