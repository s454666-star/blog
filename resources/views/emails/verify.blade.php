<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>驗證您的電子郵件地址</title>
</head>
<body>
<h1>歡迎來到星夜商城！</h1>
<p>請點擊以下連結驗證您的電子郵件地址：</p>
<a href="{{ url('/api/verify-email/' . $member->email_verification_token) }}">點此驗證電子郵件</a>
</body>
</html>
