<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>圖片預覽</title>
</head>
<body>
<h1>圖片預覽</h1>
@isset($imageData)
    <img src="{{ $imageData }}" alt="測試圖片" style="max-width: 100%;">
@else
    <p>圖片載入失敗</p>
@endisset
</body>
</html>
