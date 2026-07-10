@php
    $awsSections = [
        [
            'id' => 'markets',
            'kicker' => 'Market intelligence',
            'title' => '台股與投資觀測',
            'description' => '每日行情、籌碼、財報與期貨資料，集中在同一個分析入口。',
            'accent' => '#4ade80',
            'accentRgb' => '74, 222, 128',
            'links' => [
                ['mark' => 'ETF', 'title' => '主動式 ETF 操作日報', 'description' => '追蹤台灣主動式 ETF 每日持股異動、買賣操作與報告日期。', 'url' => url('/tw-stock/active-etf-operations')],
                ['mark' => '法人', 'title' => '台股法人進出', 'description' => '查看外資、投信與自營商買賣超，快速掌握法人資金流向。', 'url' => url('/tw-stock')],
                ['mark' => '漲幅', 'title' => '台股每日漲幅排行', 'description' => '依每日漲幅整理個股排行，並可進入個股 K 線頁面。', 'url' => url('/tw-stock/daily-price-rankings')],
                ['mark' => '營收', 'title' => '每月營收排行', 'description' => '比較上市櫃公司月營收、年增率與近期營運表現。', 'url' => url('/tw-stock/monthly-revenue-rankings')],
                ['mark' => 'Q1', 'title' => 'Q1 財報評分排名', 'description' => '用財務指標整理第一季財報分數與公司排名。', 'url' => url('/tw-stock/q1-financial-reports')],
                ['mark' => 'EPS', 'title' => '年度營收 EPS 比較', 'description' => '跨年度比較營收、EPS 與價格表現，觀察基本面趨勢。', 'url' => url('/tw-stock/annual-financial-comparison')],
                ['mark' => '除息', 'title' => '近 30 天除權息股票', 'description' => '整理近期除權息日期、股利資訊與重要時程。', 'url' => url('/tw-stock/upcoming-dividends')],
                ['mark' => 'TX', 'title' => '台指期 15K 差值 K 線', 'description' => '檢視台指期 15 分、60 分與日線，含差值、乖離與標記工具。', 'url' => url('/tw-stock/taiex-futures-kline')],
            ],
        ],
        [
            'id' => 'media',
            'kicker' => 'Content & media',
            'title' => '內容與影音管理',
            'description' => '文章、圖片、影片與重複檔案的瀏覽及整理工具。',
            'accent' => '#60a5fa',
            'accentRgb' => '96, 165, 250',
            'links' => [
                ['mark' => 'BLOG', 'title' => '文章資料庫', 'description' => '瀏覽已匯入的文章內容，支援保留與批次整理。', 'url' => url('/blog')],
                ['mark' => 'BT', 'title' => 'BT 文章索引', 'description' => '查看 BT 相關文章與下載來源，並可觸發指定項目重跑。', 'url' => url('/bt')],
                ['mark' => 'KEEP', 'title' => '保留文章', 'description' => '集中查看已標記保留、不隨一般清理移除的文章。', 'url' => url('/blog/show-preserved')],
                ['mark' => 'BTD', 'title' => 'BTDig 結果', 'description' => '檢視 BTDig 搜尋與圖片匯入結果，整理已複製項目。', 'url' => url('/btdig')],
                ['mark' => 'IMG', 'title' => '圖片藝廊', 'description' => '以藝廊方式瀏覽站內圖片，支援分批載入。', 'url' => url('/gallery')],
                ['mark' => 'LIST', 'title' => '影片列表', 'description' => '瀏覽影片清單、搜尋內容並管理影片資料。', 'url' => url('/videos')],
                ['mark' => 'LIB', 'title' => 'Amethyst 影片庫', 'description' => '以圖書館式介面管理影片、縮圖與內容集合。', 'url' => url('/videos-management')],
                ['mark' => 'PLAY', 'title' => '遠端影片播放器', 'description' => '開啟可搭配遠端控制的獨立影片播放介面。', 'url' => url('/video-player')],
                ['mark' => 'DUP', 'title' => '重複影片檢視', 'description' => '只顯示重複群組，協助比對並處理重複影片。', 'url' => url('/videos/duplicates')],
                ['mark' => 'SYNC', 'title' => '重跑資源三邊差異', 'description' => '比對重跑來源、資料庫與檔案狀態，找出同步差異。', 'url' => url('/videos/rerun-sync')],
                ['mark' => 'FACE', 'title' => '人臉作品分群', 'description' => '依人臉身分整理同一人物的影片作品與關聯資料。', 'url' => url('/face-identities')],
            ],
        ],
        [
            'id' => 'tools',
            'kicker' => 'Utilities',
            'title' => '資料與維運工具',
            'description' => '日常資料處理、爬蟲、對話統計與站務操作入口。',
            'accent' => '#fbbf24',
            'accentRgb' => '251, 191, 36',
            'links' => [
                ['mark' => 'OPS', 'title' => 'Blog 指令工具台', 'description' => '以圖形介面執行預設維運指令並查看執行輸出。', 'url' => url('/command-runner')],
                ['mark' => '85', 'title' => '85sugarbaby 會員看板', 'description' => '檢視爬蟲匯入的會員資料、照片與個人檔案。', 'url' => url('/crawler/85sugarbaby')],
                ['mark' => 'READ', 'title' => 'Dialogues 標記已讀', 'description' => '依條件批次處理對話資料的已讀狀態。', 'url' => url('/dialogues/mark-read')],
                ['mark' => 'TOK', 'title' => 'Dialogues Token 統計', 'description' => '查看對話同步、Token 數量與處理進度統計。', 'url' => url('/dialogues/token-stats')],
                ['mark' => 'LOCK', 'title' => '碎形加密儲存', 'description' => '提供檔案加密、分段儲存、解密與下載管理。', 'url' => url('/encrypt')],
                ['mark' => 'TXT', 'title' => '文字過濾與存儲', 'description' => '清理、過濾文字內容並將結果保存供後續使用。', 'url' => url('/extract')],
                ['mark' => 'IG', 'title' => 'IG 影片抓取工具', 'description' => '貼上 Instagram 網址取得影片，並提供偵錯日誌。', 'url' => url('/ig-grabber')],
                ['mark' => 'OCR', 'title' => 'OCR 辨識工作區', 'description' => '貼上或上傳圖片預覽，再送出文字辨識。', 'url' => url('/ocr')],
                ['mark' => 'UP', 'title' => '檔案上傳', 'description' => '將檔案上傳至站內設定的雲端儲存空間。', 'url' => url('/upload')],
                ['mark' => 'URL', 'title' => '影片下載工具', 'description' => '載入指定網址、保存工作階段並下載解析結果。', 'url' => url('/url-viewer')],
                ['mark' => 'TDL', 'title' => 'TDL 指令產生器', 'description' => '依輸入內容組合可直接使用的 Telegram Download 指令。', 'url' => url('/tdl')],
                ['mark' => 'SKU', 'title' => '商品上架程式', 'description' => '擷取商品網址內容，協助整理上架需要的資料。', 'url' => url('/product-import2')],
            ],
        ],
        [
            'id' => 'personal',
            'kicker' => 'Personal & lab',
            'title' => '個人頁面與實驗作品',
            'description' => '個人介紹、介面範例與小型互動作品。',
            'accent' => '#f472b6',
            'accentRgb' => '244, 114, 182',
            'links' => [
                ['mark' => 'ME', 'title' => '個人履歷', 'description' => '吳偉誠的個人介紹、技能與履歷展示頁。', 'url' => url('/my-page')],
                ['mark' => 'FOOD', 'title' => '早餐選單', 'description' => '早餐商品與選單介面的前端展示頁。', 'url' => url('/product')],
                ['mark' => 'GAME', 'title' => '貪吃蛇遊戲', 'description' => '可直接在瀏覽器遊玩的經典貪吃蛇小遊戲。', 'url' => url('/snake')],
            ],
        ],
    ];

    $workLinks = [
        ['mark' => 'AZ', 'title' => 'Polar BE 指定 Pipeline', 'description' => '直接開啟 Definition 80 的建置紀錄、狀態與手動執行入口。', 'url' => 'https://dev.azure.com/worldvisiontaiwan/polar-be/_build?definitionId=80', 'meta' => 'Azure DevOps · Pipeline 80'],
        ['mark' => 'CI', 'title' => 'Polar BE CI/CD', 'description' => '查看 polar-be 專案全部 Pipelines、近期部署與執行結果。', 'url' => 'https://dev.azure.com/worldvisiontaiwan/polar-be/_build', 'meta' => 'Azure DevOps · Pipelines'],
        ['mark' => '週報', 'title' => '每週週報', 'description' => '開啟 ITDD SharePoint 的 Weekly Meeting 資料夾與每週工作紀錄。', 'url' => 'https://worldvisiontaiwan-my.sharepoint.com/shared?id=%2Fsites%2FITDD%2FShared%20Documents%2FWeekly%20Meeting&listurl=https%3A%2F%2Fworldvisiontaiwan%2Esharepoint%2Ecom%2Fsites%2FITDD%2FShared%20Documents&sortField=Modified&isAscending=false&viewid=dd671f80%2De886%2D4cb1%2D88cd%2D947d58c1e446', 'meta' => 'SharePoint · Weekly Meeting'],
        ['mark' => 'MAIL', 'title' => '每週 Email Request', 'description' => '開啟 ITDD SharePoint 的 Email Requests 資料夾，整理每週申請。', 'url' => 'https://worldvisiontaiwan-my.sharepoint.com/shared?id=%2Fsites%2FITDD%2FShared%20Documents%2FEmail%20Requests&listurl=https%3A%2F%2Fworldvisiontaiwan%2Esharepoint%2Ecom%2Fsites%2FITDD%2FShared%20Documents&sortField=Modified&isAscending=false&viewid=dd671f80%2De886%2D4cb1%2D88cd%2D947d58c1e446', 'meta' => 'SharePoint · Email Requests'],
        ['mark' => 'JIRA', 'title' => 'Jira 任務清單', 'description' => '開啟 DEV Service Desk Queue 294，查看目前排隊與待處理任務。', 'url' => 'https://wvt.atlassian.net/jira/servicedesk/projects/DEV/queues/custom/294', 'meta' => 'Jira Service Management · Queue 294'],
        ['mark' => 'ME', 'title' => 'Jira 我的任務看板', 'description' => '開啟 WVT Board 47，直接篩選指派給我的開發任務。', 'url' => 'https://wvt.atlassian.net/jira/software/c/projects/WVT/boards/47?assignee=712020%3Ac9711b25-c5ea-4e71-a687-eb10608c9303', 'meta' => 'Jira Software · My board'],
    ];

    $environments = [
        [
            'id' => 'production',
            'name' => '正式區',
            'english' => 'Production',
            'description' => '正式流量與正式資料，操作前請再次確認環境。',
            'tone' => '#34d399',
            'toneRgb' => '52, 211, 153',
            'badge' => 'PROD',
            'links' => [
                ['mark' => 'FE', 'title' => '前台', 'description' => 'Polar 正式前台與官網服務入口。', 'url' => 'https://polar.worldvision.org.tw', 'meta' => 'polar.worldvision.org.tw', 'state' => 'PUBLIC'],
                ['mark' => 'ADMIN', 'title' => '後台', 'description' => '正式環境後台管理工具。', 'url' => 'https://admin-tools.worldvision.org.tw', 'meta' => 'admin-tools.worldvision.org.tw', 'state' => 'K8S INGRESS'],
                ['mark' => 'API', 'title' => 'API', 'description' => 'Polar BE 正式公開 API。', 'url' => 'https://polar-api.worldvision.org.tw', 'meta' => 'polar-api.worldvision.org.tw', 'state' => 'PUBLIC'],
                ['mark' => 'INT', 'title' => '內部 API', 'description' => '正式後台與內部整合使用的 API。', 'url' => 'https://polar-api-internal.worldvision.org.tw', 'meta' => 'polar-api-internal.worldvision.org.tw', 'state' => 'INTERNAL'],
            ],
        ],
        [
            'id' => 'staging',
            'name' => '測試區',
            'english' => 'Staging',
            'description' => '整合測試與驗收環境，前台需要 WVT Basic Auth。',
            'tone' => '#fbbf24',
            'toneRgb' => '251, 191, 36',
            'badge' => 'STG',
            'links' => [
                ['mark' => 'FE', 'title' => '前台', 'description' => 'Staging 前台與網站整合測試入口。', 'url' => 'https://staging-polar.worldvision.org.tw', 'meta' => 'staging-polar.worldvision.org.tw', 'state' => 'BASIC AUTH'],
                ['mark' => 'ADMIN', 'title' => '後台', 'description' => 'Staging 後台管理與驗收工具。', 'url' => 'https://staging-admin-tools.worldvision.org.tw', 'meta' => 'staging-admin-tools.worldvision.org.tw', 'state' => 'K8S INGRESS'],
                ['mark' => 'API', 'title' => 'API', 'description' => 'Polar BE Staging 對外 API。', 'url' => 'https://staging-polar-api.worldvision.org.tw', 'meta' => 'staging-polar-api.worldvision.org.tw', 'state' => 'TEST API'],
                ['mark' => 'INT', 'title' => '內部 API', 'description' => 'Staging 後台與內部整合 API。', 'url' => 'https://staging-polar-api-internal.worldvision.org.tw', 'meta' => 'staging-polar-api-internal.worldvision.org.tw', 'state' => 'INTERNAL'],
            ],
        ],
        [
            'id' => 'alpha',
            'name' => '開發測試區',
            'english' => 'Alpha',
            'description' => '功能開發與早期驗證環境，內容可能隨部署快速變動。',
            'tone' => '#c084fc',
            'toneRgb' => '192, 132, 252',
            'badge' => 'ALPHA',
            'links' => [
                ['mark' => 'FE', 'title' => '前台', 'description' => 'Alpha 前台功能開發與早期驗證入口。', 'url' => 'https://alpha-polar.worldvision.org.tw', 'meta' => 'alpha-polar.worldvision.org.tw', 'state' => 'BASIC AUTH'],
                ['mark' => 'ADMIN', 'title' => '後台', 'description' => 'Alpha 後台功能開發與測試工具。', 'url' => 'https://alpha-admin-tools.worldvision.org.tw', 'meta' => 'alpha-admin-tools.worldvision.org.tw', 'state' => 'K8S INGRESS'],
                ['mark' => 'API', 'title' => 'API', 'description' => 'Alpha CC Tools API 與開發整合入口。', 'url' => 'https://alpha-cc-tools-api.worldvision.org.tw', 'meta' => 'alpha-cc-tools-api.worldvision.org.tw', 'state' => 'TEST API'],
                ['mark' => 'INT', 'title' => '內部 API', 'description' => 'Alpha 後台與內部整合 API。', 'url' => 'https://alpha-polar-api-internal.worldvision.org.tw', 'meta' => 'alpha-polar-api-internal.worldvision.org.tw', 'state' => 'INTERNAL'],
            ],
        ],
    ];

    $awsCount = array_sum(array_map(fn (array $section): int => count($section['links']), $awsSections));
    $k8sCount = array_sum(array_map(fn (array $environment): int => count($environment['links']), $environments));
@endphp
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#07111f">
    <meta name="description" content="AWS 前端頁面、Polar K8S 環境與日常工作系統的整合導覽站。">
    <title>工作與服務導覽站 · Star Portal</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #06101d;
            --bg-soft: #0a1828;
            --panel: rgba(12, 28, 46, .72);
            --panel-strong: rgba(14, 32, 52, .92);
            --line: rgba(159, 190, 218, .14);
            --text: #f5f8fc;
            --muted: #9db0c3;
            --muted-strong: #c6d2df;
            --cyan: #38bdf8;
            --cyan-rgb: 56, 189, 248;
            --radius-xl: 28px;
            --radius-lg: 20px;
            --shadow: 0 28px 80px rgba(0, 0, 0, .3);
        }

        * { box-sizing: border-box; }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 118px;
        }

        body {
            margin: 0;
            min-width: 320px;
            min-height: 100vh;
            overflow-x: hidden;
            color: var(--text);
            background:
                radial-gradient(circle at 12% 4%, rgba(14, 165, 233, .16), transparent 27rem),
                radial-gradient(circle at 91% 19%, rgba(168, 85, 247, .14), transparent 30rem),
                radial-gradient(circle at 55% 94%, rgba(16, 185, 129, .1), transparent 32rem),
                var(--bg);
            font-family: Inter, "Noto Sans TC", "Microsoft JhengHei", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        body::before {
            position: fixed;
            inset: 0;
            z-index: -3;
            content: "";
            pointer-events: none;
            opacity: .34;
            background-image:
                linear-gradient(rgba(255, 255, 255, .025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .025) 1px, transparent 1px);
            background-size: 64px 64px;
            mask-image: linear-gradient(to bottom, black, transparent 90%);
        }

        a { color: inherit; }

        button, input { font: inherit; }

        .skip-link {
            position: fixed;
            top: 12px;
            left: 12px;
            z-index: 100;
            padding: 10px 14px;
            border-radius: 12px;
            color: #03111b;
            background: #e0f2fe;
            transform: translateY(-160%);
            transition: transform .2s ease;
        }

        .skip-link:focus { transform: translateY(0); }

        .aurora {
            position: fixed;
            z-index: -2;
            width: 34rem;
            height: 34rem;
            border-radius: 50%;
            filter: blur(95px);
            opacity: .17;
            pointer-events: none;
            animation: drift 18s ease-in-out infinite alternate;
        }

        .aurora.one { top: -16rem; left: -9rem; background: #0ea5e9; }
        .aurora.two { top: 18rem; right: -19rem; background: #a855f7; animation-delay: -6s; }
        .aurora.three { bottom: -20rem; left: 34%; background: #10b981; animation-delay: -12s; }

        .shell {
            width: min(1440px, calc(100% - 40px));
            margin: 0 auto;
        }

        .hero {
            position: relative;
            padding: 72px 0 46px;
        }

        .hero::after {
            position: absolute;
            right: 3%;
            bottom: 14%;
            width: 210px;
            height: 210px;
            content: "";
            border: 1px solid rgba(var(--cyan-rgb), .18);
            border-radius: 50%;
            box-shadow:
                0 0 0 34px rgba(var(--cyan-rgb), .025),
                0 0 0 78px rgba(var(--cyan-rgb), .018);
            pointer-events: none;
            animation: breathe 7s ease-in-out infinite;
        }

        .topline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 58px;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            color: #dff6ff;
            font-weight: 800;
            letter-spacing: .16em;
            text-transform: uppercase;
        }

        .brand-mark {
            position: relative;
            display: grid;
            width: 38px;
            height: 38px;
            place-items: center;
            border: 1px solid rgba(var(--cyan-rgb), .42);
            border-radius: 13px;
            background: linear-gradient(145deg, rgba(var(--cyan-rgb), .2), rgba(129, 140, 248, .08));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .17), 0 12px 32px rgba(14, 165, 233, .15);
        }

        .brand-mark::before,
        .brand-mark::after {
            position: absolute;
            content: "";
            background: #7dd3fc;
            border-radius: 99px;
        }

        .brand-mark::before { width: 16px; height: 3px; transform: rotate(45deg); }
        .brand-mark::after { width: 3px; height: 16px; transform: rotate(45deg); }

        .clock {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 9px 13px;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--muted-strong);
            background: rgba(8, 20, 34, .62);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            backdrop-filter: blur(14px);
        }

        .clock-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #34d399;
            box-shadow: 0 0 14px #34d399;
            animation: pulse 2.2s ease-in-out infinite;
        }

        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(300px, .6fr);
            gap: 54px;
            align-items: end;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            color: #7dd3fc;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .18em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            width: 30px;
            height: 1px;
            content: "";
            background: linear-gradient(90deg, transparent, currentColor);
        }

        h1 {
            max-width: 840px;
            margin: 0;
            font-size: clamp(45px, 6vw, 88px);
            line-height: .98;
            letter-spacing: -.055em;
        }

        .gradient-text {
            color: transparent;
            background: linear-gradient(100deg, #f8fafc 8%, #a5e7ff 48%, #c4b5fd 92%);
            -webkit-background-clip: text;
            background-clip: text;
        }

        .hero-copy {
            max-width: 720px;
            margin: 24px 0 0;
            color: var(--muted-strong);
            font-size: clamp(16px, 1.55vw, 20px);
            line-height: 1.8;
        }

        .hero-note {
            display: flex;
            align-items: flex-start;
            gap: 13px;
            padding: 18px;
            border: 1px solid rgba(var(--cyan-rgb), .18);
            border-radius: var(--radius-lg);
            color: var(--muted-strong);
            background: linear-gradient(145deg, rgba(var(--cyan-rgb), .08), rgba(15, 23, 42, .34));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .04);
            font-size: 13px;
            line-height: 1.65;
        }

        .hero-note svg { flex: 0 0 auto; color: #7dd3fc; }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 40px;
        }

        .stat {
            padding: 17px 18px;
            border: 1px solid var(--line);
            border-radius: 17px;
            background: rgba(10, 24, 40, .62);
            backdrop-filter: blur(16px);
        }

        .stat strong {
            display: block;
            margin-bottom: 3px;
            font-size: 26px;
            line-height: 1;
            letter-spacing: -.04em;
        }

        .stat span {
            color: var(--muted);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .toolbar-wrap {
            position: sticky;
            top: 0;
            z-index: 30;
            padding: 14px 0;
            background: linear-gradient(to bottom, rgba(6, 16, 29, .94), rgba(6, 16, 29, .75), transparent);
            backdrop-filter: blur(18px);
        }

        .toolbar {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            padding: 11px;
            border: 1px solid var(--line);
            border-radius: 20px;
            background: rgba(9, 22, 38, .82);
            box-shadow: 0 18px 50px rgba(0, 0, 0, .22), inset 0 1px 0 rgba(255, 255, 255, .04);
        }

        .search-box {
            position: relative;
            flex: 1 1 340px;
            min-width: 220px;
            max-width: 520px;
        }

        .search-box svg {
            position: absolute;
            top: 50%;
            left: 14px;
            color: #7f96ad;
            transform: translateY(-50%);
            pointer-events: none;
        }

        #resource-search {
            width: 100%;
            min-height: 44px;
            padding: 0 48px 0 43px;
            border: 1px solid transparent;
            border-radius: 14px;
            outline: 0;
            color: var(--text);
            background: rgba(4, 13, 24, .72);
            transition: border-color .2s ease, box-shadow .2s ease;
        }

        #resource-search::placeholder { color: #71869b; }

        #resource-search:focus {
            border-color: rgba(var(--cyan-rgb), .48);
            box-shadow: 0 0 0 4px rgba(var(--cyan-rgb), .08);
        }

        .shortcut {
            position: absolute;
            top: 50%;
            right: 12px;
            padding: 3px 7px;
            border: 1px solid var(--line);
            border-radius: 7px;
            color: #7890a7;
            background: rgba(255, 255, 255, .025);
            font-size: 11px;
            transform: translateY(-50%);
        }

        .filters {
            display: flex;
            flex: 1 1 680px;
            flex-wrap: wrap;
            justify-content: flex-start;
            gap: 7px;
        }

        .filter-button {
            min-height: 40px;
            padding: 0 14px;
            border: 1px solid transparent;
            border-radius: 12px;
            color: var(--muted);
            background: transparent;
            cursor: pointer;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .03em;
            transition: color .2s ease, background .2s ease, border-color .2s ease;
        }

        .filter-button:hover { color: var(--text); background: rgba(255, 255, 255, .045); }

        .filter-button.active {
            border-color: rgba(var(--cyan-rgb), .22);
            color: #dff6ff;
            background: rgba(var(--cyan-rgb), .12);
        }

        .result-count {
            flex: 0 0 auto;
            min-width: 74px;
            color: #7f96ad;
            font-size: 11px;
            font-weight: 800;
            text-align: center;
            letter-spacing: .06em;
        }

        main { padding: 38px 0 92px; }

        .group-shell {
            position: relative;
            margin-top: 34px;
            padding: clamp(22px, 3vw, 36px);
            overflow: hidden;
            border: 1px solid rgba(var(--accent-rgb), .14);
            border-radius: var(--radius-xl);
            background:
                linear-gradient(140deg, rgba(var(--accent-rgb), .055), transparent 30%),
                rgba(9, 22, 38, .65);
            box-shadow: var(--shadow), inset 0 1px 0 rgba(255, 255, 255, .035);
            animation: rise .65s ease both;
            animation-delay: calc(var(--order, 0) * 55ms);
        }

        .group-shell::before {
            position: absolute;
            top: 0;
            left: 7%;
            width: 32%;
            height: 1px;
            content: "";
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
            opacity: .75;
        }

        .group-heading {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 28px;
            margin-bottom: 24px;
        }

        .group-kicker {
            margin-bottom: 8px;
            color: var(--accent);
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .18em;
            text-transform: uppercase;
        }

        .group-heading h2 {
            margin: 0;
            font-size: clamp(24px, 3vw, 34px);
            letter-spacing: -.035em;
        }

        .group-heading p {
            max-width: 470px;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
            text-align: right;
        }

        .card-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 13px;
        }

        .resource-card {
            --pointer-x: 50%;
            --pointer-y: 50%;
            position: relative;
            display: flex;
            min-height: 194px;
            padding: 18px;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            color: inherit;
            background:
                radial-gradient(circle at var(--pointer-x) var(--pointer-y), rgba(var(--accent-rgb), .13), transparent 42%),
                rgba(7, 18, 31, .76);
            text-decoration: none;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .035);
            transition: transform .24s ease, border-color .24s ease, box-shadow .24s ease, background .24s ease;
        }

        .resource-card::after {
            position: absolute;
            inset: auto 18px 0;
            height: 1px;
            content: "";
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
            opacity: 0;
            transition: opacity .24s ease;
        }

        .resource-card:hover,
        .resource-card:focus-visible {
            z-index: 2;
            border-color: rgba(var(--accent-rgb), .4);
            outline: none;
            box-shadow: 0 22px 48px rgba(0, 0, 0, .26), 0 0 0 1px rgba(var(--accent-rgb), .08);
            transform: translateY(-5px);
        }

        .resource-card:hover::after,
        .resource-card:focus-visible::after { opacity: .9; }

        .card-inner {
            display: flex;
            width: 100%;
            flex-direction: column;
        }

        .card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 25px;
        }

        .card-mark {
            display: grid;
            min-width: 43px;
            height: 43px;
            padding: 0 9px;
            place-items: center;
            border: 1px solid rgba(var(--accent-rgb), .26);
            border-radius: 13px;
            color: var(--accent);
            background: rgba(var(--accent-rgb), .1);
            font-size: 10px;
            font-weight: 950;
            letter-spacing: .04em;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .07);
        }

        .open-icon {
            color: #6f869d;
            transition: color .2s ease, transform .2s ease;
        }

        .resource-card:hover .open-icon {
            color: var(--accent);
            transform: translate(2px, -2px);
        }

        .resource-card h3 {
            margin: 0 0 9px;
            font-size: 17px;
            line-height: 1.35;
            letter-spacing: -.02em;
        }

        .resource-card p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.68;
        }

        .card-meta {
            display: flex;
            align-items: center;
            gap: 7px;
            min-width: 0;
            margin-top: auto;
            padding-top: 18px;
            color: #6f869d;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 10px;
        }

        .card-meta::before {
            flex: 0 0 auto;
            width: 5px;
            height: 5px;
            content: "";
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 10px rgba(var(--accent-rgb), .7);
        }

        .card-meta span {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .environment-shell {
            --accent: var(--tone);
            --accent-rgb: var(--tone-rgb);
        }

        .environment-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .environment-badge {
            padding: 5px 9px;
            border: 1px solid rgba(var(--tone-rgb), .26);
            border-radius: 999px;
            color: var(--tone);
            background: rgba(var(--tone-rgb), .09);
            font-size: 9px;
            font-weight: 900;
            letter-spacing: .12em;
        }

        .state-badge {
            max-width: 110px;
            padding: 5px 7px;
            overflow: hidden;
            border: 1px solid rgba(var(--accent-rgb), .2);
            border-radius: 999px;
            color: var(--accent);
            background: rgba(var(--accent-rgb), .07);
            font-size: 8px;
            font-weight: 900;
            letter-spacing: .08em;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .section-divider {
            display: flex;
            align-items: center;
            gap: 18px;
            margin: 78px 0 4px;
        }

        .section-divider::before,
        .section-divider::after {
            height: 1px;
            content: "";
            background: linear-gradient(90deg, transparent, var(--line));
        }

        .section-divider::before { width: 54px; }
        .section-divider::after { flex: 1; background: linear-gradient(90deg, var(--line), transparent); }

        .section-divider span {
            color: #7890a7;
            font-size: 10px;
            font-weight: 900;
            letter-spacing: .2em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .empty-state {
            display: none;
            margin-top: 40px;
            padding: 50px 24px;
            border: 1px dashed rgba(var(--cyan-rgb), .24);
            border-radius: var(--radius-xl);
            color: var(--muted);
            text-align: center;
            background: rgba(8, 20, 34, .5);
        }

        .empty-state strong {
            display: block;
            margin-bottom: 8px;
            color: var(--text);
            font-size: 18px;
        }

        footer {
            padding: 30px 0 50px;
            border-top: 1px solid var(--line);
            color: #72879c;
            font-size: 11px;
        }

        .footer-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .footer-row strong { color: #a8b8c8; }

        [hidden] { display: none !important; }

        @keyframes drift {
            to { transform: translate3d(70px, 45px, 0) scale(1.12); }
        }

        @keyframes breathe {
            50% { opacity: .45; transform: scale(1.04); }
        }

        @keyframes pulse {
            50% { opacity: .45; transform: scale(.76); }
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1120px) {
            .card-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .hero-grid { grid-template-columns: 1fr; gap: 32px; }
            .hero-note { max-width: 720px; }
            .hero::after { right: -5%; bottom: 30%; }
        }

        @media (max-width: 860px) {
            .shell { width: min(100% - 26px, 1440px); }
            .hero { padding-top: 34px; }
            .topline { margin-bottom: 44px; }
            .card-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .search-box { max-width: none; }
            .filters {
                order: 3;
                width: 100%;
                flex-wrap: nowrap;
                justify-content: flex-start;
                overflow-x: auto;
                scrollbar-width: thin;
            }
            .filter-button { flex: 1 0 auto; }
            .result-count { min-width: 58px; }
            .group-heading { align-items: flex-start; flex-direction: column; gap: 10px; }
            .group-heading p { max-width: none; text-align: left; }
        }

        @media (max-width: 570px) {
            .shell { width: min(100% - 18px, 1440px); }
            .topline { align-items: flex-start; }
            .brand { font-size: 12px; }
            .clock { font-size: 10px; }
            h1 { font-size: clamp(41px, 14vw, 62px); }
            .hero-copy { font-size: 15px; }
            .stats { gap: 7px; }
            .stat { padding: 14px 11px; }
            .stat strong { font-size: 22px; }
            .stat span { font-size: 9px; }
            .toolbar-wrap { padding-top: 9px; }
            .toolbar { border-radius: 17px; }
            .result-count { display: none; }
            .card-grid { grid-template-columns: 1fr; }
            .resource-card { min-height: 174px; }
            .group-shell { padding: 19px; border-radius: 22px; }
            .section-divider { margin-top: 54px; }
            .footer-row { align-items: flex-start; flex-direction: column; gap: 8px; }
            .hero::after { display: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after {
                scroll-behavior: auto !important;
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>
<body>
    <a class="skip-link" href="#main-content">跳到主要內容</a>
    <div class="aurora one" aria-hidden="true"></div>
    <div class="aurora two" aria-hidden="true"></div>
    <div class="aurora three" aria-hidden="true"></div>

    <header class="hero shell">
        <div class="topline">
            <div class="brand"><span class="brand-mark" aria-hidden="true"></span>Star Portal</div>
            <div class="clock" aria-label="台北時間">
                <span class="clock-dot" aria-hidden="true"></span>
                <span id="taipei-clock">TAIPEI --:--:--</span>
            </div>
        </div>

        <div class="hero-grid">
            <div>
                <div class="eyebrow">One page. Every destination.</div>
                <h1><span class="gradient-text">工作與服務</span><br>導覽站</h1>
                <p class="hero-copy">把 AWS 前端、Polar K8S 環境與每天會用到的工作系統集中在同一頁。選擇卡片後，目的頁面會在新視窗開啟。</p>
                <div class="stats" aria-label="連結統計">
                    <div class="stat"><strong>{{ $awsCount }}</strong><span>AWS pages</span></div>
                    <div class="stat"><strong>{{ count($workLinks) }}</strong><span>Work tools</span></div>
                    <div class="stat"><strong>{{ $k8sCount }}</strong><span>K8S routes</span></div>
                </div>
            </div>

            <div class="hero-note">
                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M12 8v4l2.5 1.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>AWS 頁面已依正式站可用狀態整理；K8S 入口則依目前 ingress 分為 Production、Staging 與 Alpha。內部 API 或後台可能需要公司網路、VPN、Basic Auth 或 Microsoft／Atlassian 登入。</span>
            </div>
        </div>
    </header>

    <div class="toolbar-wrap">
        <div class="shell toolbar" role="search">
            <label class="search-box">
                <span class="sr-only" hidden>搜尋入口</span>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="m21 21-4.35-4.35M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                <input id="resource-search" type="search" autocomplete="off" placeholder="搜尋頁面、工具或網址…" aria-label="搜尋頁面、工具或網址">
                <span class="shortcut" aria-hidden="true">/</span>
            </label>
            <div class="filters" aria-label="頁面區塊">
                <button class="filter-button active" type="button" data-filter="all">全部</button>
                @foreach ($awsSections as $section)
                    <button class="filter-button" type="button" data-filter="{{ $section['id'] }}">{{ $section['title'] }}</button>
                @endforeach
                <button class="filter-button" type="button" data-filter="workspace">日常工作系統</button>
                @foreach ($environments as $environment)
                    <button class="filter-button" type="button" data-filter="{{ $environment['id'] }}">
                        {{ $environment['name'] }}{{ $environment['english'] === 'Production' ? '' : ' ' . $environment['english'] }}
                    </button>
                @endforeach
            </div>
            <div class="result-count" id="result-count" aria-live="polite">{{ $awsCount + count($workLinks) + $k8sCount }} LINKS</div>
        </div>
    </div>

    <main id="main-content" class="shell">
        <div class="section-divider" data-divider="aws"><span>AWS production pages</span></div>

        @foreach ($awsSections as $sectionIndex => $section)
            <section
                id="{{ $section['id'] }}"
                class="group-shell"
                data-resource-group
                data-kind="aws"
                data-section="{{ $section['id'] }}"
                style="--accent: {{ $section['accent'] }}; --accent-rgb: {{ $section['accentRgb'] }}; --order: {{ $sectionIndex }};"
            >
                <div class="group-heading">
                    <div>
                        <div class="group-kicker">{{ $section['kicker'] }}</div>
                        <h2>{{ $section['title'] }}</h2>
                    </div>
                    <p>{{ $section['description'] }}</p>
                </div>

                <div class="card-grid">
                    @foreach ($section['links'] as $link)
                        <a
                            class="resource-card"
                            href="{{ $link['url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            data-resource-card
                            data-kind="aws"
                            data-section="{{ $section['id'] }}"
                            data-search="{{ $link['title'] }} {{ $link['description'] }} {{ $link['url'] }}"
                        >
                            <span class="card-inner">
                                <span class="card-top">
                                    <span class="card-mark">{{ $link['mark'] }}</span>
                                    <svg class="open-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                        <path d="M14 5h5v5M19 5l-8 8M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                                <h3>{{ $link['title'] }}</h3>
                                <p>{{ $link['description'] }}</p>
                                <span class="card-meta"><span>{{ parse_url($link['url'], PHP_URL_PATH) ?: '/' }}</span></span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="section-divider" data-divider="work"><span>Daily workspace</span></div>

        <section
            id="workspace"
            class="group-shell"
            data-resource-group
            data-kind="work"
            data-section="workspace"
            style="--accent: #22d3ee; --accent-rgb: 34, 211, 238; --order: 4;"
        >
            <div class="group-heading">
                <div>
                    <div class="group-kicker">Delivery & collaboration</div>
                    <h2>日常工作系統</h2>
                </div>
                <p>CI/CD、週報、Email Request 與 Jira 任務入口；使用公司帳號登入後即可存取。</p>
            </div>

            <div class="card-grid">
                @foreach ($workLinks as $link)
                    <a
                        class="resource-card"
                        href="{{ $link['url'] }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        data-resource-card
                        data-kind="work"
                        data-section="workspace"
                        data-search="{{ $link['title'] }} {{ $link['description'] }} {{ $link['meta'] }} {{ $link['url'] }}"
                    >
                        <span class="card-inner">
                            <span class="card-top">
                                <span class="card-mark">{{ $link['mark'] }}</span>
                                <svg class="open-icon" width="17" height="17" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M14 5h5v5M19 5l-8 8M18 13v5a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <h3>{{ $link['title'] }}</h3>
                            <p>{{ $link['description'] }}</p>
                            <span class="card-meta"><span>{{ $link['meta'] }}</span></span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="section-divider" data-divider="k8s"><span>Polar Kubernetes ingress</span></div>

        @foreach ($environments as $environmentIndex => $environment)
            <section
                id="{{ $environment['id'] }}"
                class="group-shell environment-shell"
                data-resource-group
                data-kind="k8s"
                data-section="{{ $environment['id'] }}"
                style="--tone: {{ $environment['tone'] }}; --tone-rgb: {{ $environment['toneRgb'] }}; --order: {{ $environmentIndex + 5 }};"
            >
                <div class="group-heading">
                    <div>
                        <div class="group-kicker">{{ $environment['english'] }} environment</div>
                        <div class="environment-title">
                            <h2>{{ $environment['name'] }}</h2>
                            <span class="environment-badge">{{ $environment['badge'] }}</span>
                        </div>
                    </div>
                    <p>{{ $environment['description'] }}</p>
                </div>

                <div class="card-grid">
                    @foreach ($environment['links'] as $link)
                        <a
                            class="resource-card"
                            href="{{ $link['url'] }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            data-resource-card
                            data-kind="k8s"
                            data-section="{{ $environment['id'] }}"
                            data-search="{{ $environment['name'] }} {{ $environment['english'] }} {{ $link['title'] }} {{ $link['description'] }} {{ $link['meta'] }} {{ $link['url'] }}"
                        >
                            <span class="card-inner">
                                <span class="card-top">
                                    <span class="card-mark">{{ $link['mark'] }}</span>
                                    <span class="state-badge">{{ $link['state'] }}</span>
                                </span>
                                <h3>{{ $link['title'] }}</h3>
                                <p>{{ $link['description'] }}</p>
                                <span class="card-meta"><span>{{ $link['meta'] }}</span></span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="empty-state" id="empty-state">
            <strong>找不到符合的入口</strong>
            換一個關鍵字，或切回「全部」查看完整導覽。
        </div>
    </main>

    <footer>
        <div class="shell footer-row">
            <span><strong>Star Portal</strong> · AWS pages were checked before publishing.</span>
            <span>所有連結皆使用新視窗開啟 · <span id="footer-year">{{ now()->year }}</span></span>
        </div>
    </footer>

    <script>
        (() => {
            const search = document.getElementById('resource-search');
            const cards = [...document.querySelectorAll('[data-resource-card]')];
            const groups = [...document.querySelectorAll('[data-resource-group]')];
            const dividers = [...document.querySelectorAll('[data-divider]')];
            const filters = [...document.querySelectorAll('[data-filter]')];
            const resultCount = document.getElementById('result-count');
            const emptyState = document.getElementById('empty-state');
            let activeFilter = 'all';

            const normalize = value => value.toLocaleLowerCase('zh-Hant').trim();

            const activateFilter = filter => {
                activeFilter = filter;
                filters.forEach(item => item.classList.toggle('active', item.dataset.filter === filter));
            };

            const applyFilters = () => {
                const query = normalize(search.value);
                let visibleCount = 0;

                cards.forEach(card => {
                    const matchesSection = activeFilter === 'all' || card.dataset.section === activeFilter;
                    const matchesQuery = !query || normalize(card.dataset.search || card.textContent).includes(query);
                    const visible = matchesSection && matchesQuery;
                    card.hidden = !visible;
                    if (visible) visibleCount += 1;
                });

                groups.forEach(group => {
                    const hasVisibleCard = [...group.querySelectorAll('[data-resource-card]')].some(card => !card.hidden);
                    group.hidden = !hasVisibleCard;
                });

                dividers.forEach(divider => {
                    const kind = divider.dataset.divider;
                    const hasVisibleGroup = groups.some(group => group.dataset.kind === kind && !group.hidden);
                    divider.hidden = !hasVisibleGroup;
                });

                resultCount.textContent = `${visibleCount} LINKS`;
                emptyState.style.display = visibleCount === 0 ? 'block' : 'none';
            };

            filters.forEach(button => {
                button.addEventListener('click', () => {
                    activateFilter(button.dataset.filter);
                    applyFilters();
                });
            });

            search.addEventListener('input', () => {
                if (normalize(search.value) !== '' && activeFilter !== 'all') {
                    activateFilter('all');
                }

                applyFilters();
            });

            document.addEventListener('keydown', event => {
                const tag = document.activeElement?.tagName?.toLowerCase();
                const isTyping = tag === 'input' || tag === 'textarea';

                if (event.key === '/' && !isTyping) {
                    event.preventDefault();
                    search.focus();
                }

                if (event.key === 'Escape' && document.activeElement === search) {
                    search.value = '';
                    search.blur();
                    applyFilters();
                }
            });

            cards.forEach(card => {
                card.addEventListener('pointermove', event => {
                    const rect = card.getBoundingClientRect();
                    card.style.setProperty('--pointer-x', `${event.clientX - rect.left}px`);
                    card.style.setProperty('--pointer-y', `${event.clientY - rect.top}px`);
                });
            });

            const clock = document.getElementById('taipei-clock');
            const formatter = new Intl.DateTimeFormat('zh-TW', {
                timeZone: 'Asia/Taipei',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: false,
            });

            const updateClock = () => {
                clock.textContent = `TAIPEI ${formatter.format(new Date())}`;
            };

            updateClock();
            window.setInterval(updateClock, 1000);
        })();
    </script>
</body>
</html>
