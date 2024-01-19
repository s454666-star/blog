<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>早餐選單</title>
    <style>
        /* 現有樣式保持不變 */
        /* ... 其他先前定義的 CSS ... */

        .info-nav {
            background-color: #FFC0CB; /* 淡粉紅色背景 */
            padding: 5px 0;
            text-align: center;
        }
        .info-nav a {
            color: #333;
            margin: 0 15px;
            text-decoration: none;
            font-weight: bold;
        }
        .info-nav a:hover {
            text-decoration: underline;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgb(0,0,0);
            background-color: rgba(0,0,0,0.4);
            padding-top: 60px;
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }

        .close:hover,
        .close:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
    </style>
</head>
<body>

<div class="header">
    <img src="{{ asset('storage/img/morning call logo.jpg') }}" alt="Morning Call Logo" class="logo">
    <!-- 其他導航內容 -->
</div>

<div class="info-nav">
    <a href="javascript:void(0);" id="storeInfo">店家資訊</a>
    <a href="javascript:void(0);">運費規則</a>
    <a href="javascript:void(0);">會員中心</a>
</div>

<div id="storeModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <p><strong>Morning Call 早餐店 (某某店)</strong></p>
        <p>地址: 新北市某某區某某路101號</p>
        <p>聯絡電話: 02-89673368</p>
        <p>營業時間: 06:00-15:30</p>
    </div>
</div>

<div class="main-content">
    <!-- 主要內容，比如產品列表 -->
</div>

<script>
    var modal = document.getElementById('storeModal');
    var btn = document.getElementById('storeInfo');
    var span = document.getElementsByClassName('close')[0];

    btn.onclick = function() {
        modal.style.display = "block";
    }

    span.onclick = function() {
        modal.style.display = "none";
    }

    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = "none";
        }
    }
</script>

</body>
</html>
