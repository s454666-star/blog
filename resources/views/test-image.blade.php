<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>圖片預覽</title>
</head>
<body>
<h1>圖片預覽</h1>
@if (!empty($imageUrl))
    <img src="{{ url('/proxy-image') }}" alt="測試圖片">
@else
    <p>圖片載入失敗</p>
@endif
</body>
</html>
