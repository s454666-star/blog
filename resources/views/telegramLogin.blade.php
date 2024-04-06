<!-- resources/views/telegramLogin.blade.php -->
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>Telegram 登入</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f0f2f5;
        }
        form {
            background: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 320px;
        }
        .message, .error {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            color: #fff;
            text-align: center;
        }
        .message {
            background-color: #28a745;
        }
        .error {
            background-color: #dc3545;
        }
        .input-group, .form-group {
            margin-bottom: 20px;
        }
        .input-group input, .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ced4da;
            border-radius: 4px;
            box-sizing: border-box;
        }
        .input-group .area-code {
            width: 70px;
            background-color: #e9ecef;
            border: 1px solid #ced4da;
            color: #495057;
            text-align: center;
            border-radius: 4px 0 0 4px;
        }
        .input-group .phone-number {
            flex: 1;
            border-left: none;
            border-radius: 0 4px 4px 0;
        }
        button {
            width: 100%;
            padding: 10px;
            background-color: #007bff;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }
        button:hover {
            background-color: #0056b3;
        }
        button:active {
            background-color: #004085;
        }
    </style>
</head>
<body>
<form method="POST" action="{{ route('telegram.auth') }}">
    @csrf
    @if (session('message'))
        <div class="message">{{ session('message') }}</div>
    @endif
    @if (session('error'))
        <div class="error">{{ session('error') }}</div>
    @endif
    <div class="input-group">
        <span class="area-code">+886</span>
        <input type="text" id="phone" name="phone" class="phone-number" placeholder="電話號碼" required>
    </div>
    <div class="form-group">
        <input type="text" id="code" name="code" placeholder="驗證碼">
    </div>
    <div class="form-group">
        <input type="password" id="password" name="password" placeholder="二步驟驗證密碼（如果需要）">
    </div>
    <button type="submit">提交</button>
</form>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        if (localStorage.getItem('phone')) {
            document.getElementById('phone').value = localStorage.getItem('phone');
        }
        document.getElementById('phone').addEventListener('input', function () {
            localStorage.setItem('phone', this.value);
        });
    });
</script>
</body>
</html>
