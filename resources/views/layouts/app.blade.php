<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
    @yield('head', '')  <!-- 提供預設值 -->
</head>
<body>
<div id="app">
    @yield('content')
</div>

@yield('scripts', '')  <!-- 提供預設值 -->
</body>
</html>
