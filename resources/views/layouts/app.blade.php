<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    @yield('head')  <!-- 這裡加入 @yield 來引入子視圖的 head 部分 -->
</head>
<body>
<div id="app">
    @yield('content')
</div>

@yield('scripts')  <!-- 建議把 scripts 也移到這裡來確保載入順序正確 -->
</body>
</html>
