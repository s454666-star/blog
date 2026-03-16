<!-- resources/views/dashboard.blade.php -->
<!DOCTYPE html>
<html lang="zh-TW">
@if (session('message'))
    <div class="alert alert-success">
        {{ session('message') }}
    </div>
@endif
<head>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <title>登入成功</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        /* 在這裡添加自定義 CSS 樣式 */
    </style>
</head>
<body>
<div class="container mt-5">
    <h2 class="mb-4">登入成功！歡迎來到您的儀表板</h2>
    <p class="text-muted mb-0">Telegram 登入流程已停用，這個頁面目前只保留基本登入成功提示。</p>
</div>
</body>
</html>
