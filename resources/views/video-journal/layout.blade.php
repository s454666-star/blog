<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f6f3ed">
    <title>@yield('title', '映記') — FRAME / 影片生活誌</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='18' fill='%23e97050'/%3E%3Cpath d='M25 18L46 32L25 46Z' fill='%23fff'/%3E%3C/svg%3E">
    <link rel="stylesheet" href="{{ asset('css/video-journal.css') }}?v={{ filemtime(public_path('css/video-journal.css')) }}">
    <script src="{{ asset('js/video-journal.js') }}?v={{ filemtime(public_path('js/video-journal.js')) }}" defer></script>
</head>
<body data-fancy-cursor="native">
    <a class="skip-link" href="#main">跳到主要內容</a>
    <div class="ambient" aria-hidden="true"></div>
    <header class="site-header shell">
        <a class="brand" href="{{ route('video-journal.index') }}"><span class="brand-icon">▸</span><span>映記<span class="brand-en">FRAME JOURNAL</span></span></a>
        <nav aria-label="主選單"><a class="nav-link" href="{{ route('video-journal.index') }}">我的影片誌 <span>↗</span></a><span class="local-pill"><i></i> 私人的映像空間</span></nav>
    </header>
    <main id="main" class="shell">@yield('content')</main>
    <footer class="site-footer shell"><span>FRAME JOURNAL <b>映記</b></span><span>把值得回看的瞬間，好好收藏。 <span class="footer-star">✳</span></span></footer>
    <div id="toast" class="toast" role="status" @if(!session('status')) hidden @endif>{{ session('status') }}</div>
</body>
</html>
