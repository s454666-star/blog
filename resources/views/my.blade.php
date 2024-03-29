<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>吳偉誠的履歷</title>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            background-color: #e4f1fe; /* Light blue background */
        }

        .container {
            background-color: #fff;
            width: 80%;
            margin: 20px auto;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .header {
            background-color: #34ace0; /* Blue header */
            color: #fff;
            text-align: center;
            padding: 20px 0;
        }

        .header img {
            border-radius: 50%;
            width: 120px;
            height: 120px;
            border: 4px solid #fff; /* White border for image */
        }

        .section {
            padding: 20px;
        }

        .section:nth-child(even) {
            background-color: #f7f1e3; /* Light yellow for alternate sections */
        }

        .section-title {
            color: #706fd3; /* Purple titles */
            margin-bottom: 15px;
            border-bottom: 2px solid #ff793f; /* Orange underline for titles */
            display: inline-block;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <img src="{{ asset('storage/img/my.jpg') }}" alt="Profile Picture">
        <h1>吳偉誠</h1>
        <p>後端工程師</p>
    </div>

    <div class="section">
        <h2 class="section-title">學歷</h2>
        <p>輔仁大學 資訊工程學系</p>
    </div>

    <div class="section">
        <h2 class="section-title">工作經歷</h2>

        <!-- PHP工程師 at 亞東資訊科技有限公司 -->
        <h3>PHP工程師 - 亞東資訊科技有限公司</h3>
        <p>後端工程師 - 台北市內湖區<br>2020/9~2023/12<br>3年4個月</p>
        <p>串接第三方金流:串接支付寶和微信支付等等</p>

        <p> 使用語言:laravel,mysql,redis</p>

        <p> 建立公司項目服務器:使用過aws,阿里雲,gpc,騰訊雲等等雲端服務,開ecs和slb..等等雲端服務</p>

        <p> 版本控制:git</p>

        <p> CL/CD部署工具:jenkies,會寫佈署的shell</p>

        <p> 公司網站買域名,設定域名DNS,使用CDN網站加速</p>

        <p> 公司另一個金融項目是用docker開發,寫過dockerfile,並在dockerfile加上elk服務方便log查詢</p>

        <p> 公司內部工作:和前端串接api,確認問題,寫爬蟲抓取網站上資料供內部使用</p>

        <!-- 後端工程師 at 健和興端子股份有限公司 -->
        <h3>後端工程師 - 健和興端子股份有限公司</h3>
        <p>MIS程式設計師 - 彰化縣線西鄉<br>2020/3~2020/9<br>7個月</p>
        <p>負責商品資料串接，利用單元測試和整合測試確保程式品質，後台bug修改維護。</p>

        <!-- PHP工程師 at 新魂科技 -->
        <h3>PHP工程師 - 新魂科技</h3>
        <p>軟體工程師 - 台北市內湖區<br>2019/10~2020/2<br>5個月</p>
        <p>參與開發平台的權限模組，開發獨立爬蟲系統，技術棧: PHP, HTML, JavaScript, jQuery, CSS, Vue.js, MySQL, Redis,
            Docker, PHPUnit。</p>

        <!-- 系統工程師 at 喜士多科技 -->
        <h3>系統工程師 - 喜士多科技</h3>
        <p>軟體工程師 - 台北市中山區<br>2013/5~2019/10<br>6年6個月</p>
        <p>參與大型ERP專案前後台開發，使用技術: PHP, HTML, JavaScript, jQuery, CSS, MySQL, MSSQL, Ajax。</p>
    </div>
</div>
</body>
</html>
