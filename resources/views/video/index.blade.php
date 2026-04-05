<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>影片列表</title>

    <!-- ===== 依賴 ===== -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">

    <!-- ===== 樣式 (依功能分區) ===== -->
    <style>

        :root {
            --theme-base: #F1E1FF;
            --theme-base-strong: #e2c6ff;
            --theme-surface: #fcf7ff;
            --theme-card: rgba(255, 252, 255, 0.9);
            --theme-card-strong: rgba(255, 247, 255, 0.98);
            --theme-border: rgba(163, 110, 214, 0.22);
            --theme-border-strong: rgba(163, 110, 214, 0.42);
            --theme-accent: #a55cf6;
            --theme-accent-2: #d07bff;
            --theme-accent-soft: rgba(165, 92, 246, 0.14);
            --theme-accent-strong: #7f3bd0;
            --theme-text: #4e3b63;
            --theme-text-soft: #72598d;
            --theme-success: #59a47b;
            --theme-danger: #d05f91;
            --theme-shadow: 0 22px 48px rgba(124, 76, 168, 0.14);
            --theme-shadow-strong: 0 24px 55px rgba(124, 76, 168, 0.2);
            --video-width: 70%;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--theme-text);
            font-family: "Aptos", "Segoe UI Variable Text", "Microsoft JhengHei", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, .96), rgba(255, 255, 255, 0) 32%),
                radial-gradient(circle at 85% 12%, rgba(223, 186, 255, .45), rgba(223, 186, 255, 0) 26%),
                linear-gradient(180deg, #fff9ff 0%, var(--theme-base) 46%, #f8ebff 100%);
            background-attachment: fixed;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(255, 255, 255, .18) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .18) 1px, transparent 1px);
            background-size: 22px 22px;
            opacity: .35;
            mix-blend-mode: soft-light;
            z-index: -1;
        }

        /* === 影片列 ================================================= */
        .video-row {
            display: flex;
            margin-bottom: 20px;
            border: 1px solid var(--theme-border);
            padding: 14px;
            border-radius: 24px;
            cursor: pointer;
            user-select: none;
            transition: background-color .3s, border-color .3s, transform .22s ease, box-shadow .22s ease;
            position: relative;
            box-sizing: border-box;
            background:
                linear-gradient(135deg, rgba(255, 255, 255, .9), rgba(250, 239, 255, .82)),
                var(--theme-card);
            box-shadow: var(--theme-shadow);
            backdrop-filter: blur(10px);
        }

        .video-row.selected {
            border-color: rgba(165, 92, 246, .55);
            background: linear-gradient(135deg, rgba(255, 255, 255, .96), rgba(243, 229, 255, .9));
            box-shadow: 0 22px 48px rgba(150, 84, 204, .2)
        }

        .video-row.focused {
            border-color: rgba(112, 197, 152, .72);
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, .92), rgba(255, 255, 255, 0) 34%),
                linear-gradient(135deg, rgba(255, 255, 255, .98), rgba(233, 255, 245, .92));
            box-shadow: 0 28px 58px rgba(89, 164, 123, .18)
        }

        .video-row:hover {
            transform: translateY(-2px);
            box-shadow: var(--theme-shadow-strong);
        }

        .video-headline {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 10px;
        }

        .video-meta-chips {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
            min-width: 110px;
        }

        .video-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 68px;
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(165, 92, 246, .2);
            background: rgba(255, 255, 255, .82);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .9);
            color: var(--theme-text-soft);
            font-family: "Bahnschrift", "Segoe UI Variable Display", sans-serif;
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .04em;
        }

        /* === 影片標題動畫 =========================================== */
        .video-title {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 6px;
            overflow: hidden;
            position: relative;
            background: linear-gradient(90deg, var(--theme-accent-strong) 0%, var(--theme-accent-2) 48%, var(--theme-accent) 100%);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            color: transparent;
            animation: shine 3s linear infinite, slideIn .6s cubic-bezier(.25, .8, .25, 1) forwards;
            opacity: 0;
            transform: translateY(-10px);
        }

        @keyframes shine {
            to {
                background-position: -200% center;
            }
        }

        @keyframes slideIn {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .video-path {
            font-size: .85em;
            color: var(--theme-text-soft);
            font-weight: 400;
        }

        /* 讓路徑字體稍微小一點、變灰色，不跟著流光 */
        .video-path {
            font-size: .85em;
            color: var(--theme-text-soft);
            font-weight: 400;
        }

        /* === 小節標題美化：漸層滑動底線 + 微動畫 ===================== */
        .screenshot-images h5,
        .face-screenshot-images h5 {
            position: relative;
            margin-bottom: 8px;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: 1px;
            color: var(--theme-text);
        }

        .screenshot-images h5::after,
        .face-screenshot-images h5::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -2px;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, var(--theme-accent) 0%, var(--theme-accent-2) 100%);
            border-radius: 2px;

            /* 底線左右流動動畫 */
            background-size: 200% auto;
            animation: slideBar 3s linear infinite;
        }

        @keyframes slideBar {
            0% {
                background-position: 0 0;
            }
            100% {
                background-position: -200% 0;
            }
        }

        /* === 上傳提示美化：藍色字 + 左側雲端上傳圖示 =============== */
        .upload-instructions {
            font-size: .9rem;
            font-weight: 600;
            color: var(--theme-accent-strong);
            letter-spacing: .5px;
            position: relative;
            padding-left: 26px; /* 預留圖示空間 */
            width: 100%;
            text-align: center;
            margin-top: 12px;
        }

        /* 加入 SVG 圖示（純 CSS，不需額外檔案） */
        .upload-instructions::before {
            content: '';
            position: absolute;
            left: 3px;
            top: 50%;
            width: 18px;
            height: 18px;
            transform: translateY(-50%);
            background: url('data:image/svg+xml;utf8,\
<svg xmlns="http://www.w3.org/2000/svg" viewBox=\"0 0 24 24\" fill=\"%23a55cf6\">\
<path d=\"M12 16v-6m0 0l-3 3m3-3l3 3m6 1v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4m16-4h-3.586a1 1 0 01-.707-.293l-3.414-3.414a2 2 0 00-2.828 0L6.707 11.707a1 1 0 01-.707.293H4\"/>\
            </svg>') center/18px 18px no-repeat;
            opacity: .85;
        }

        .face-upload-area.dragover {
            background: rgba(255, 255, 255, .96);
            border-color: var(--theme-accent-strong);
            box-shadow: 0 0 0 4px rgba(165, 92, 246, .12);
        }

        .face-paste-target {
            width: 100px;
            height: 56px;
            margin: 5px;
            border: 2px dashed rgba(165, 92, 246, .42);
            border-radius: 14px;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, .95), rgba(255, 255, 255, 0) 45%),
                linear-gradient(135deg, rgba(255, 255, 255, .98), rgba(244, 233, 255, .95) 48%, rgba(251, 245, 255, .98));
            color: var(--theme-text-soft);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 6px;
            cursor: text;
            outline: none;
            overflow: hidden;
            position: relative;
            flex: 0 0 auto;
            box-shadow: 0 14px 28px rgba(124, 76, 168, .16), inset 0 1px 0 rgba(255, 255, 255, .88);
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease, background-color .2s ease;
        }

        .face-paste-target::before {
            content: '';
            position: absolute;
            inset: -30%;
            background: linear-gradient(115deg, rgba(255, 255, 255, 0) 28%, rgba(255, 255, 255, .55) 50%, rgba(255, 255, 255, 0) 72%);
            transform: translateX(-65%) rotate(14deg);
            animation: facePasteSweep 4.6s ease-in-out infinite;
            pointer-events: none;
        }

        .face-paste-target::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .75);
            pointer-events: none;
        }

        @keyframes facePasteSweep {
            0%,
            100% {
                transform: translateX(-68%) rotate(14deg);
                opacity: .3;
            }
            50% {
                transform: translateX(68%) rotate(14deg);
                opacity: .85;
            }
        }

        .face-paste-hint {
            font-size: .78rem;
            line-height: 1.35;
            color: var(--theme-text-soft);
            padding: 0 4px;
            position: relative;
            z-index: 1;
            text-shadow: 0 1px 0 rgba(255, 255, 255, .7);
        }

        .face-paste-preview {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: none;
            border-radius: 10px;
            position: relative;
            z-index: 1;
        }

        .face-paste-target.has-preview .face-paste-preview {
            display: block;
        }

        .face-paste-target.has-preview::before {
            animation: none;
            opacity: 0;
        }

        .face-paste-target.has-preview .face-paste-hint {
            display: none;
        }

        .face-paste-target:hover,
        .face-paste-target:focus {
            border-color: var(--theme-accent);
            box-shadow: 0 14px 28px rgba(124, 76, 168, .2), 0 0 0 4px rgba(165, 92, 246, .14);
            transform: translateY(-1px);
        }

        /* === 主面人臉縮圖：常態柔光 + Hover 漸層光環 =================== */
        .master-face-img {
            position: relative;
            border-radius: 6px;
            overflow: hidden;
        }

        /* 1. 常態：薄白框（用內凹 box‑shadow） */
        .master-face-img::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, .35) inset;
            transition: opacity .4s;
            pointer-events: none;
            z-index: 1; /* 避免被下方 :before 蓋掉 */
        }

        /* 2. Hover：漸層流動光環（放在 :before，避免蓋到白框） */
        .master-face-img::before {
            content: '';
            position: absolute;
            inset: -2px; /* 稍微蓋出邊界，光環更顯眼 */
            border-radius: inherit;
            background: linear-gradient(135deg, var(--theme-accent-2) 0%, var(--theme-accent) 50%, var(--theme-accent-2) 100%);
            background-size: 300% 300%;
            opacity: 0;
            transition: opacity .4s;
            pointer-events: none;
            z-index: 0;
        }

        /* 啟動畫面：滑入才點亮並流動 */
        .master-face-img:hover::before {
            opacity: 1;
            animation: borderFlow 3s linear infinite;
        }

        /* 已聚焦（.focused）的永遠保持亮光 */
        .master-face-img.focused::before {
            opacity: 1;
            animation: borderFlow 3s linear infinite;
        }

        /* 流動關鍵影格 */
        @keyframes borderFlow {
            0% {
                background-position: 0% 50%;
            }
            100% {
                background-position: 200% 50%;
            }
        }

        /* === 影片與截圖容器 === */
        .video-container {
            width: var(--video-width);
            padding-right: 10px;
            box-sizing: border-box;
            min-width: 0;
        }

        .images-container {
            width: calc(100% - var(--video-width));
            padding-left: 10px;
            overflow: hidden;
            box-sizing: border-box;
            min-width: 0;
        }

        .screenshot, .face-screenshot {
            width: 100px;
            height: 56px;
            object-fit: cover;
            margin: 5px;
            transition: transform .3s, border .3s, box-shadow .3s;
            border-radius: 16px;
            border: 1px solid rgba(165, 92, 246, .14);
            box-shadow: 0 12px 24px rgba(124, 76, 168, .12);
            background: rgba(255, 255, 255, .85);
        }

        .face-screenshot.master {
            border: 3px solid var(--theme-success);
            box-shadow: 0 18px 34px rgba(89, 164, 123, .22)
        }

        /* === 新增：截圖清單捲軸 === */
        .screenshot-images .d-flex,
        .face-screenshot-images .d-flex {
            max-height: 250px;
            overflow-y: auto;
            overflow-x: hidden
        }

        /* === 放大圖片 (hover) === */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            inset: 0;
            overflow: hidden;
            background: rgba(0, 0, 0, .8);
            justify-content: center;
            align-items: center;
            pointer-events: none
        }

        .image-modal img {
            max-width: 90%;
            max-height: 90%;
            border: 5px solid #fff;
            border-radius: 5px;
            pointer-events: none
        }

        .image-modal.active {
            display: flex
        }

        /* === 底部控制列 === */
        .controls {
            position: fixed;
            bottom: 0;
            left: 30%;
            right: 0;
            background: rgba(255, 250, 255, .94);
            padding: 20px 30px;
            border-top: 1px solid var(--theme-border);
            box-shadow: 0 -10px 24px rgba(124, 76, 168, .12);
            backdrop-filter: blur(12px);
            z-index: 1000;
            display: flex;
            align-items: center;
            flex-wrap: wrap
        }

        .controls .control-group {
            margin-right: 30px;
            display: flex;
            align-items: center;
            margin-bottom: 10px;
            flex-grow: 1
        }

        .controls label {
            margin-right: 10px;
            font-weight: 700;
            white-space: nowrap;
            color: var(--theme-text-soft)
        }

        #play-mode {
            width: 50px;
            height: 10px
        }

        /* === 上傳框 === */
        .upload-area {
            border: 2px dashed rgba(165, 92, 246, .4);
            border-radius: 18px;
            padding: 30px;
            text-align: center;
            color: var(--theme-text-soft);
            transition: .3s;
            margin-bottom: 20px;
            background: rgba(255, 255, 255, .74)
        }

        .upload-area.dragover {
            background: rgba(255, 255, 255, .96);
            border-color: var(--theme-accent-strong);
            color: var(--theme-accent-strong)
        }

        /* === 主面人臉側欄 === */
        .master-faces {
            position: fixed;
            top: 0;
            left: 0;
            width: 30%;
            height: 100%;
            overflow-y: auto;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, .96), rgba(249, 239, 255, .92)),
                var(--theme-card-strong);
            border-right: 1px solid var(--theme-border);
            padding: 18px 14px 90px;
            box-sizing: border-box;
            z-index: 100;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: 14px 0 36px rgba(124, 76, 168, .1)
        }

        .master-faces h5 {
            text-align: center;
            width: 100%;
            margin-bottom: 12px;
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--theme-accent-strong);
            letter-spacing: .08em;
        }

        /* 固定一列 4 張，每張都佔 1 格 */
        .master-face-images {
            display: grid;
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap: 10px;
            width: 100%
        }

        /* 讓圖片縮放塞進格子、不扭曲（不裁切） */
        .master-face-img {
            display: block;
            width: 100%;
            height: auto;
            aspect-ratio: 1/1;
            object-fit: contain;
            background: rgba(255, 255, 255, .92);
            cursor: pointer;
            border: 2px solid transparent;
            border-radius: 16px;
            transition: border-color .3s, box-shadow .3s, transform .3s;
            box-shadow: 0 12px 26px rgba(124, 76, 168, .12)
        }

        .master-face-img:hover {
            border-color: var(--theme-accent);
            transform: scale(1.05)
        }

        .master-face-img.focused {
            border-color: var(--theme-success);
            box-shadow: 0 0 0 4px rgba(89, 164, 123, .16), 0 0 15px rgba(89, 164, 123, .38);
            transform: scale(1.1)
        }

        .master-faces-status {
            width: 100%;
            margin-bottom: 14px;
            padding: 10px 12px;
            border-radius: 16px;
            border: 1px dashed rgba(165, 92, 246, .22);
            background: rgba(255, 255, 255, .74);
            color: var(--theme-text-soft);
            font-size: .88rem;
            text-align: center;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .78);
        }

        .master-faces-status.is-hidden {
            display: none;
        }

        /* === 主要內容區 === */
        .container {
            margin-left: 30%;
            padding-top: 24px;
            padding-bottom: 104px
        }

        /* === 快訊訊息 === */
        .message-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000
        }

        .message {
            padding: 12px 18px;
            border-radius: 16px;
            margin-bottom: 10px;
            color: #fff;
            opacity: .9;
            animation: fadeOut 1s forwards;
            box-shadow: 0 18px 38px rgba(86, 58, 114, .18)
        }

        .message.success {
            background: linear-gradient(135deg, #6dc18f, #4d986f)
        }

        .message.error {
            background: linear-gradient(135deg, #e07aa2, #c04c7c)
        }

        @keyframes fadeOut {
            0% {
                opacity: .9
            }
            100% {
                opacity: 0
            }
        }

        /* === 刪除與設為主面按鈕 === */
        .delete-icon, .set-master-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(208, 95, 145, .9);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            text-align: center;
            line-height: 18px;
            cursor: pointer;
            display: none;
            font-size: 14px;
            padding: 0;
            box-shadow: 0 10px 18px rgba(192, 76, 124, .22)
        }

        .set-master-btn {
            right: 30px;
            background: rgba(89, 164, 123, .9)
        }

        .screenshot-container, .face-screenshot-container {
            position: relative;
            display: inline-block
        }

        .screenshot-container:hover .delete-icon, .face-screenshot-container:hover .delete-icon, .face-screenshot-container:hover .set-master-btn {
            display: block
        }

        /* === 全螢幕模式切換 === */
        .fullscreen-mode .controls, .fullscreen-mode .master-faces, .fullscreen-mode .container {
            display: none
        }

        .fullscreen-controls {
            position: fixed;
            inset: 0;
            z-index: 2000;
            display: none
        }

        .fullscreen-controls.show {
            display: block
        }

        .fullscreen-controls .prev-video-btn, .fullscreen-controls .next-video-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, .5);
            border: none;
            color: #fff;
            padding: 20px;
            font-size: 24px;
            border-radius: 50%;
            cursor: pointer;
            opacity: 0;
            transition: opacity .3s
        }

        .fullscreen-controls .prev-video-btn {
            left: 20px
        }

        .fullscreen-controls .next-video-btn {
            right: 20px
        }

        .fullscreen-controls .prev-video-btn.show, .fullscreen-controls .next-video-btn.show {
            opacity: 1
        }

        /* === RWD 調整 === */
        @media (max-width: 768px) {
            .video-container, .images-container {
                width: 100%;
                padding: 0
            }

            .controls {
                left: 0;
                flex-direction: column;
                align-items: flex-start
            }

            .controls .control-group {
                margin-right: 0;
                margin-bottom: 10px
            }

            .master-faces {
                width: 100%;
                height: auto;
                position: relative;
                border-right: none;
                border-bottom: 1px solid var(--theme-border)
            }

            .container {
                margin-left: 0
            }

            .master-face-images {
                grid-template-columns:repeat(4, 1fr)
            }
        }

        /* === Bootstrap Container 寬度上限調整 (大螢幕) === */
        @media (min-width: 1200px) {
            .container, .container-lg, .container-md, .container-sm, .container-xl {
                max-width: 1750px
            }

            /* 側欄展開時：避免在 FHD / 系統縮放情境下內容超出視窗右側 */
            .container.expanded {
                max-width: min(1750px, 70%)
            }
        }

        /* === 主面人臉側欄開關動畫 =========================== */
        .master-faces {
            transition: transform .45s ease-in-out; /* 滑動動畫 */
        }

        .master-faces.collapsed {
            transform: translateX(-100%); /* 完全藏到左側 */
        }

        /* 內容區跟隨位移 — 直接用 margin-left 讓內容補齊全幅 */
        .container {
            transition: margin-left .45s ease-in-out;
        }

        .container.expanded { /* 側欄展開時維持舊 30% 邊距 */
            margin-left: 30%;
        }

        /* 開關鈕本身：只控制 X，Y 由 JS 寫入 inline-style */
        #toggle-master-faces {
            position: fixed;
            left: 0;
            transform: translateX(-50%);
            z-index: 1100;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            border: none;
            background: linear-gradient(135deg, var(--theme-accent), var(--theme-accent-strong));
            color: #fff;
            font-size: 20px;
            line-height: 38px;
            text-align: center;
            cursor: pointer;
            box-shadow: 0 14px 28px rgba(124, 76, 168, .28);
            transition: transform .45s ease-in-out, opacity .3s;
        }

        /* 展開狀態（JS 會套 .inside）— 直接把鈕推到側欄內右緣 */
        #toggle-master-faces.inside {
            transform: translateX(calc(260px - 50%)) rotate(180deg);
            opacity: .6;
        }

        #toggle-master-faces.hide { /* 展開時縮進側欄 */
            opacity: .6;
            transform: translate(calc(-50% + 260px), 0) rotate(180deg);
        }

        .controls {
            position: fixed;
            bottom: 0;
            transition: left .45s ease-in-out; /* 新增動畫 */
        }

        .controls.expanded {
            left: 30%;
        }

        /* 側欄展開維持舊位置 */

        /* ---------- 讓預設寬度變 100%，只有 .expanded 時才 30% ---------- */
        .container {
            margin-left: 0 !important;
        }

        /* 收合時占滿 */
        .controls {
            left: 0 !important;
        }

        /* 收合時貼左 */

        .container.expanded {
            margin-left: 30% !important;
        }

        .controls.expanded {
            left: 30% !important;
        }

        .video-wrapper video {
            width: 100%;
            display: block;
            border-radius: 22px;
            border: 1px solid rgba(165, 92, 246, .18);
            box-shadow: 0 18px 34px rgba(124, 76, 168, .16);
            background: rgba(255, 255, 255, .72);
        }

        .face-upload-area {
            position: relative;
            border: 2px dashed rgba(165, 92, 246, .3);
            border-radius: 20px;
            padding: 12px;
            min-height: 132px;
            background: linear-gradient(135deg, rgba(255, 255, 255, .92), rgba(247, 237, 255, .86));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .9);
            transition: border-color .2s ease, box-shadow .2s ease, opacity .2s ease;
        }

        .face-upload-area.is-uploading,
        .face-upload-area.is-saving-master {
            opacity: .78;
            border-color: rgba(127, 59, 208, .55);
            box-shadow: 0 0 0 4px rgba(165, 92, 246, .1);
        }

        .controls .btn,
        .controls .form-control,
        .controls input[type="range"] {
            border-radius: 14px;
        }

        .controls .form-control {
            border: 1px solid rgba(165, 92, 246, .18);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .84);
        }

        .btn-warning {
            color: #5e3a66;
            background: linear-gradient(135deg, #ffe6ff, #f5d8ff);
            border-color: rgba(165, 92, 246, .2);
            box-shadow: 0 12px 22px rgba(124, 76, 168, .14);
        }

        .btn-warning:hover {
            color: #4b2f52;
            background: linear-gradient(135deg, #fff1ff, #eed2ff);
        }
    </style>
</head>
<body>
<!-- === 主面人臉開關按鈕 === -->
<button id="toggle-master-faces" title="展開 / 收合主面人臉">☰</button>
<!-- ===== 主面人臉側欄 ===== -->
<div class="master-faces">
    <h5>主面人臉</h5>
    <div id="master-faces-status" class="master-faces-status">主面人臉載入中...</div>
    <div class="master-face-images"></div>
</div>

<!-- ===== 內容區 ===== -->
<div class="container mt-4">
    <div id="message-container" class="message-container"></div>

    <div id="videos-list">
        @include('video.partials.video_rows',['videos'=>$videos])
    </div>

    <div id="load-more" class="text-center my-4" style="display:none">
        <p>正在載入更多影片...</p>
    </div>
</div>

<!-- ===== 全螢幕控制按鈕 ===== -->
<div id="fullscreen-controls" class="fullscreen-controls">
    <button id="prev-video-btn" class="prev-video-btn">❮</button>
    <button id="next-video-btn" class="next-video-btn">❯</button>
</div>

<!-- ===== 底部控制列 ===== -->
<div class="controls">
    <form id="controls-form" class="d-flex flex-wrap w-100" method="GET">
        <input type="hidden" id="focus-id" name="focus_id" value="{{ $focusId }}">
        <div class="control-group">
            <label for="video-size">影片大小:</label>
            <input id="video-size" type="range" name="video_size" min="10" max="50"
                   value="{{ request('video_size',25) }}">
        </div>
        <div class="control-group">
            <label for="image-size">截圖大小:</label>
            <input id="image-size" type="range" name="image_size" min="100" max="300"
                   value="{{ request('image_size',200) }}">
        </div>
        <div class="control-group">
            <label for="video-type">影片類別:</label>
            <select id="video-type" name="video_type" class="form-control">
                @for($i=1;$i<=4;$i++)
                    <option value="{{ $i }}" {{ request('video_type','1')==$i? 'selected':'' }}>{{ $i }}</option>
                @endfor
            </select>
        </div>
        <div class="control-group">
            <label for="play-mode">播放模式:</label>
            <input id="play-mode" type="range" name="play_mode" min="0" max="1" value="{{ request('play_mode','0') }}"
                   step="1">
            <span id="play-mode-label"></span>
        </div>
        {{-- 排序依據 --}}
        <div class="control-group">
            <label for="sort-by">排序方式：</label>
            <select id="sort-by" name="sort_by" class="form-control">
                <option value="duration" {{ $sortBy==='duration' ? 'selected':'' }}>依時長</option>
                <option value="id" {{ $sortBy==='id'       ? 'selected':'' }}>依先後</option>
            </select>
        </div>
        {{-- 排序方向 --}}
        <div class="control-group">
            <label for="sort-dir">排序方向：</label>
            <select id="sort-dir" name="sort_dir" class="form-control">
                <option value="asc" {{ $sortDir==='asc'  ? 'selected':'' }}>由小到大</option>
                <option value="desc" {{ $sortDir==='desc' ? 'selected':'' }}>由大到小</option>
            </select>
        </div>
        <div class="control-group">
            <label for="missing-only">未選主面:</label>
            <input id="missing-only"
                   type="range"
                   name="missing_only"
                   min="0" max="1" step="1"
                   value="{{ $missingOnly ? 1 : 0 }}"
                   style="width:50px;height:10px">
            <span id="missing-only-label"></span>
        </div>
        <div class="control-group">
            <button id="delete-focused-btn" class="btn btn-warning" type="button">刪除聚焦的影片</button>
        </div>
    </form>
</div>

<!-- ===== Blade 模板 (影片列 / 截圖 / 人臉截圖) ===== -->
<template id="video-row-template">
    <div class="video-row" data-id="{{ '{id}' }}" data-duration="{{ '{duration}' }}">
        <div class="video-container">
            <div class="video-wrapper">
                <div class="video-headline">
                    <div class="video-title">
                        @{{video_name}}
                        <span class="video-path">(@{{video_path}})</span>
                    </div>
                    <div class="video-meta-chips">
                        <span class="video-chip">#@{{id}}</span>
                        <span class="video-chip">@{{duration}}s</span>
                    </div>
                </div>
                <video width="100%" controls preload="none" playsinline>
                    <source src="{{ rtrim(config('app.video_base_url'), '/') }}/{{ '{video_path}' }}" type="video/mp4">
                    您的瀏覽器不支援影片播放。
                </video>
                <button class="fullscreen-btn">⤢</button>
            </div>
        </div>

        <div class="images-container">
            <div class="screenshot-images mb-2">
                <h5>影片截圖</h5>
                <div class="d-flex flex-wrap">
                    {{ '{screenshot_images}' }}
                </div>
            </div>

            <div class="face-screenshot-images">
                <h5>人臉截圖</h5>
                <div class="d-flex flex-wrap face-upload-area" data-video-id="{{ '{id}' }}">
                    {{ '{face_screenshot_images}' }}
                    <div class="face-paste-target" contenteditable="true" tabindex="0">
                        <img class="face-paste-preview" alt="貼上預覽">
                        <span class="face-paste-hint">點一下後貼上，Enter 上傳</span>
                    </div>
                    <div class="upload-instructions">
                        拖曳圖片到此處上傳
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<template id="screenshot-template">
    <div class="screenshot-container">
        <img
            src="{{ rtrim(config('app.video_base_url'), '/') }}/{{ '{screenshot_path}' }}"
            class="screenshot hover-zoom"
            alt="截圖"
            data-id="{{ '{screenshot_id}' }}"
            data-type="screenshot"
            loading="lazy"
            decoding="async"
            fetchpriority="low"
        >
        <button class="delete-icon" data-id="{{ '{screenshot_id}' }}" data-type="screenshot">&times;</button>
    </div>
</template>


<template id="face-screenshot-template">
    <div class="face-screenshot-container">
        <img
            src="{{ rtrim(config('app.video_base_url'), '/') }}/{{ '{face_image_path}' }}"
            class="face-screenshot hover-zoom {{ '{is_master_class}' }}"
            alt="人臉截圖"
            data-id="{{ '{face_id}' }}"
            data-video-id="{{ '{video_id}' }}"
            data-type="face-screenshot"
            loading="lazy"
            decoding="async"
            fetchpriority="low"
        >
        <button class="set-master-btn" data-id="{{ '{face_id}' }}" data-video-id="{{ '{video_id}' }}">★</button>
        <button class="delete-icon" data-id="{{ '{face_id}' }}" data-type="face-screenshot">&times;</button>
    </div>
</template>

<!-- ===== 放大圖片容器 ===== -->
<div id="image-modal" class="image-modal"><img src="" alt="放大圖片"></div>

<!-- ===== JS 依賴 ===== -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>

<!-- ===== 主腳本 ===== -->
<script>
    /* --------------------------------------------------
     * 全域變數
     * -------------------------------------------------- */
    const baseVideoUrl = '{{ rtrim(config('app.video_base_url'), '/') }}';
    let missingOnly = {{ $missingOnly ? 'true' : 'false' }};
    let latestId = {{ $latestId ?? 'null' }};
    let sortBy = '{{ $sortBy }}';
    let sortDir = '{{ $sortDir }}';
    let lastPage = {{ $lastPage ?? 1 }};
    let loadedPages = [{{ $videos->currentPage() }}];
    let nextPage = {{ $nextPage ?? 'null' }};
    let prevPage = {{ $prevPage ?? 'null' }};
    let loading = false;

    let videoList = [];
    let currentVideoIndex = 0;
    let playMode = {{ request('play_mode') ? '1' : '0' }};
    let currentFSVideo = null;

    let videoSize = {{ request('video_size',25) }};
    let imageSize = {{ request('image_size',200) }};
    let videoType = '{{ request('video_type','1') }}';

    let initialFocusId = {{ $focusId ?? 'null' }};
    let masterFacesPage = 0;
    let masterFacesLastPage = 1;
    let masterFacesLoading = false;
    let masterFacesLoadedCount = 0;
    const pendingFaceUploads = new Set();
    const pendingMasterUpdates = new Set();

    $('#video-type, #sort-by, #sort-dir').on('change', function () {
        setTimeout(() => $('#controls-form').trigger('submit'), 0);
    });

    /* --- 只顯示未選主面切換 --- */
    $('#missing-only')
        .on('input', function () {                // 拖動時即時顯示文字
            missingOnly = $(this).val() === '1';
            updateMissingOnlyLabel();
        })
        .on('change', function () {               // 放開滑鼠 → 重新整理
            missingOnly = $(this).val() === '1';
            $('#controls-form').submit();
        });
    /* 第一次進頁面就寫一次文字 */
    updateMissingOnlyLabel();

    /* --------------------------------------------------
     * 快訊訊息
     * -------------------------------------------------- */
    function showMessage(type, text) {
        const $mc = $('#message-container');
        const $msg = $('<div class="message"></div>')
            .addClass(type === 'success' ? 'success' : 'error')
            .text(text);
        $mc.append($msg);
        setTimeout(() => {
            $msg.fadeOut(500, () => {
                $msg.remove();
            });
        }, 1000);
    }

    function getCurrentFocusedVideoId() {
        return Number($('.video-row.focused').data('id') || $('#focus-id').val() || 0) || null;
    }

    function normalizeMediaPath(path) {
        return String(path || '').replace(/^\/+/, '');
    }

    function updateMasterFacesStatus(text, hidden = false) {
        const $status = $('#master-faces-status');
        $status.text(text || '');
        $status.toggleClass('is-hidden', !!hidden);
    }

    /* --------------------------------------------------
     * 分頁載入 / 排序
     * -------------------------------------------------- */
    function recalcPages() {
        const min = Math.min.apply(null, loadedPages);
        const max = Math.max.apply(null, loadedPages);
        prevPage = min > 1 ? (min - 1) : null;
        nextPage = max < lastPage ? (max + 1) : null;
    }

    /* --- 只顯示未選主面滑動開關 --- */
    function updateMissingOnlyLabel() {
        $('#missing-only-label').text(missingOnly ? '開' : '關');
    }

    function compareWithTiebreaker(primaryA, secondaryA, primaryB, secondaryB) {
        if (primaryA !== primaryB) {
            return sortDir === 'asc' ? (primaryA - primaryB) : (primaryB - primaryA);
        }

        return sortDir === 'asc' ? (secondaryA - secondaryB) : (secondaryB - secondaryA);
    }

    function getRowSortParts(el) {
        const $el = $(el);
        const id = parseInt($el.data('id'), 10) || 0;
        const duration = parseFloat($el.data('duration')) || 0;

        return sortBy === 'duration'
            ? {primary: duration, secondary: id}
            : {primary: id, secondary: id};
    }

    function compareVideoRows(a, b) {
        const left = getRowSortParts(a);
        const right = getRowSortParts(b);

        return compareWithTiebreaker(left.primary, left.secondary, right.primary, right.secondary);
    }

    function loadMoreVideos(dir = 'down', target = null) {
        if (loading) return;

        // 沒指定 target 時，判斷是否還有上下頁
        if (!target) {
            if (dir === 'down' && !nextPage) return;
            if (dir === 'up' && !prevPage) return;
        }

        loading = true;
        $('#load-more').show();

        const data = {
            video_type: videoType,
            missing_only: missingOnly ? 1 : 0,
            sort_by: sortBy,
            sort_dir: sortDir,
            page: target ?? (dir === 'down' ? nextPage : prevPage),
            focus_id: $('#focus-id').val()
        };

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data,
            success(res) {
                if (res && res.success && res.data.trim()) {
                    const $temp = $('<div>').html(res.data);
                    dir === 'down'
                        ? $('#videos-list').append($temp.children())
                        : $('#videos-list').prepend($temp.children());

                    if (!loadedPages.includes(res.current_page))
                        loadedPages.push(res.current_page);

                    lastPage = res.last_page || lastPage;
                    rebuildAndSort();
                } else {
                    if (!target) dir === 'down' ? nextPage = null : prevPage = null;
                    $('#load-more').html('<p>沒有更多資料了。</p>');
                }
                loading = false;
                $('#load-more').hide();
            },
            error() {
                showMessage('error', '載入失敗，請稍後再試。');
                loading = false;
                $('#load-more').hide();
            }
        });
    }

    function loadPageAndFocus(videoId, page) {
        if (!page) {
            showMessage('error', '找不到該影片所在的頁面。');
            return;
        }

        loading = true;
        $('#load-more').show();

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data: {
                page,
                video_type: videoType,
                missing_only: missingOnly ? 1 : 0,
                sort_by: sortBy,
                sort_dir: sortDir,
                focus_id: $('#focus-id').val()
            },
            success(res) {
                if (res && res.success && res.data.trim()) {
                    const $temp = $('<div>').html(res.data);
                    $('#videos-list').append($temp.children());

                    if (!loadedPages.includes(res.current_page))
                        loadedPages.push(res.current_page);

                    lastPage = res.last_page || lastPage;
                    rebuildAndSort();

                    const $target = $('.video-row[data-id="' + videoId + '"]');
                    if ($target.length) {
                        $('.video-row').removeClass('focused');
                        $target.addClass('focused');
                        focusMasterFace(videoId);
                        $target[0].scrollIntoView({behavior: 'smooth', block: 'center'});
                    }
                } else {
                    showMessage('error', '無法載入該頁資料。');
                }
                loading = false;
                $('#load-more').hide();
            },
            error() {
                showMessage('error', '載入失敗，請稍後再試。');
                loading = false;
                $('#load-more').hide();
            }
        });
    }

    function rebuildAndSort() {
        const currentId = $('.video-row.focused').data('id') || null;

        const rows = $('.video-row').get().sort(compareVideoRows);

        $('#videos-list').empty().append(rows);
        buildVideoList();
        applySizes();
        applyMediaPerfOptimizations();
        recalcPages();

        if (currentId) {
            const $t = $('.video-row[data-id="' + currentId + '"]');
            if ($t.length) {
                $('.video-row').removeClass('focused');
                $t.addClass('focused');
                focusMasterFace(currentId);
            }
            $('#focus-id').val(currentId);
        }
        watchFocusedRow();

        // ★★★ 這一行是關鍵：右側重排後，左側也依同樣的 sortBy/sortDir 重新排
        resortMasterFacesByCurrentSort();
    }

    /* --------------------------------------------------
     * 影片列表 / 尺寸
     * -------------------------------------------------- */
    function buildVideoList() {
        videoList = [];
        $('.video-row').each(function () {
            videoList.push({
                id: $(this).data('id'),
                video: $(this).find('video')[0],
                row: $(this)
            });
        });
    }

    function applySizes() {
        $('.video-container').css('width', videoSize + '%');
        $('.images-container').css('width', (100 - videoSize) + '%');
        $('.screenshot,.face-screenshot,.face-paste-target').css({
            width: imageSize + 'px',
            height: (imageSize * 0.56) + 'px'
        });
    }

    /* --------------------------------------------------
     * 主面人臉同步
     * -------------------------------------------------- */
    function focusMasterFace(id) {
        $('.master-face-img').removeClass('focused');
        const $t = $(`.master-face-img[data-video-id="${id}"]`).addClass('focused');
        if (!$t.length) return;
        const c = document.querySelector('.master-faces');
        if (!c) return;
        c.scrollTo({top: $t[0].offsetTop - c.clientHeight / 2 + $t[0].clientHeight / 2, behavior: 'smooth'});
    }

    function releaseRowMediaSources($row) {
        const states = [];

        $row.find('video').each(function () {
            const video = this;
            const sourceStates = Array.from(video.querySelectorAll('source')).map(source => ({
                element: source,
                src: source.getAttribute('src'),
                type: source.getAttribute('type'),
            }));

            states.push({
                element: video,
                src: video.getAttribute('src'),
                poster: video.getAttribute('poster'),
                preload: video.getAttribute('preload'),
                sourceStates,
            });

            try {
                video.pause();
            } catch (err) {
                console.warn('pause video failed before delete', err);
            }

            video.removeAttribute('src');
            video.removeAttribute('poster');
            sourceStates.forEach(({element}) => element.removeAttribute('src'));
            video.load();
        });

        return function restore() {
            states.forEach(({element, src, poster, preload, sourceStates}) => {
                if (src) {
                    element.setAttribute('src', src);
                } else {
                    element.removeAttribute('src');
                }

                if (poster) {
                    element.setAttribute('poster', poster);
                } else {
                    element.removeAttribute('poster');
                }

                if (preload) {
                    element.setAttribute('preload', preload);
                } else {
                    element.removeAttribute('preload');
                }

                sourceStates.forEach(({element: sourceEl, src: sourceSrc, type}) => {
                    if (sourceSrc) {
                        sourceEl.setAttribute('src', sourceSrc);
                    } else {
                        sourceEl.removeAttribute('src');
                    }

                    if (type) {
                        sourceEl.setAttribute('type', type);
                    } else {
                        sourceEl.removeAttribute('type');
                    }
                });

                element.load();
            });
        };
    }

    /* --------------------------------------------------
     * 全螢幕播放
     * -------------------------------------------------- */
    function enterFullScreen(video) {
        /* ------- 全螢幕時一律循環 ------- */
        video.loop = true;                 // JS 屬性
        video.setAttribute('loop', '');    // HTML 屬性，兼容所有瀏覽器

        try {
            if (video.requestFullscreen) {
                video.requestFullscreen().then(() => {
                    $('body').addClass('fullscreen-mode');
                    video.play();          // 重新播放，確保 loop 生效
                });
            } else if (video.webkitRequestFullscreen) {
                video.webkitRequestFullscreen();
                $('body').addClass('fullscreen-mode');
                video.play();
            } else if (video.msRequestFullscreen) {
                video.msRequestFullscreen();
                $('body').addClass('fullscreen-mode');
                video.play();
            } else {
                $('body').addClass('fullscreen-mode');
                video.play();
            }
        } catch (err) {
            console.error(err);
        }
    }

    function exitFullScreen() {
        if (document.fullscreenElement) document.exitFullscreen();
        $('body').removeClass('fullscreen-mode');
    }

    function onVideoEnded(e) {
        const v = e.target;
        if (v.loop) {
            v.play();
            return;
        }
        if (playMode === '1') {
            if (currentVideoIndex < videoList.length - 1) playAt(currentVideoIndex + 1);
            else showMessage('error', '已經是最後一部影片');
        }
    }

    function playAt(idx) {
        if (idx < 0 || idx >= videoList.length) {
            showMessage('error', '索引超出範圍');
            return;
        }
        currentVideoIndex = idx;
        const {video, row} = videoList[idx];
        $('html,body').animate({scrollTop: row.offset().top - 100}, 500);
        const isFS = document.fullscreenElement === video;
        if (isFS) {
            video.currentTime = 0;
            video.play();
            video.loop = playMode === '0';
        } else {
            video.currentTime = 0;
            video.play();
            enterFullScreen(video);
        }
    }

    /* --------------------------------------------------
     * DOM Ready
     * -------------------------------------------------- */
    $(function () {
        /* --- 初始顯示文字 --- */
        $('#play-mode-label').text(playMode === '0' ? '循環' : '自動');

        /* --- 顯示效能優化（避免影片預載、補 poster、主面人臉自動判斷橫豎） --- */
        applyMediaPerfOptimizations();

        /* --- Range 調整 --- */
        $('#video-size').on('input', e => {
            videoSize = e.target.value;
            applySizes();
        });
        $('#image-size').on('input', e => {
            imageSize = e.target.value;
            applySizes();
        });
        $('#play-mode').on('input', e => {
            playMode = e.target.value;
            $('#play-mode-label').text(playMode === '0' ? '循環' : '自動');
        });
        $('#video-type').change(() => $('#controls-form').submit());

        /* --- 聚焦影片刪除 --- */
        $('#delete-focused-btn').click(() => {
            const $f = $('.video-row.focused');
            if (!$f.length) {
                showMessage('error', '沒有聚焦的影片');
                return;
            }
            if (!confirm('確定要刪除聚焦的影片嗎？此操作無法撤銷。')) return;
            const restoreMedia = releaseRowMediaSources($f);
            $.post("{{ route('video.deleteSelected') }}", {ids: [$f.data('id')], _token: '{{ csrf_token() }}'}, res => {
                if (res?.success) {
                    const deletedId = $f.data('id');
                    $f.remove();
                    $(`.master-face-img[data-video-id="${deletedId}"]`).remove();
                    masterFacesLoadedCount = $('.master-face-img').length;
                    updateMasterFacesStatus(
                        masterFacesPage < masterFacesLastPage
                            ? `主面人臉已載入 ${masterFacesLoadedCount} 張，背景續載中...`
                            : `主面人臉已載入完成，共 ${masterFacesLoadedCount} 張。`,
                        masterFacesLoadedCount > 0 && masterFacesPage >= masterFacesLastPage
                    );
                    showMessage('success', res.message);
                    rebuildAndSort();
                    const $next = $('.video-row').first();
                    if ($next.length) {
                        const nextId = $next.data('id');
                        $next.addClass('focused');
                        $('#focus-id').val(nextId);
                        focusMasterFace(nextId);
                    } else {
                        $('#focus-id').val('');
                        $('.master-face-img').removeClass('focused');
                    }
                } else {
                    restoreMedia();
                    showMessage('error', res.message);
                }
            }).fail(xhr => {
                restoreMedia();
                const message = xhr?.responseJSON?.message || '刪除失敗，請稍後再試。';
                showMessage('error', message);
            });
        });

        /* --- 影片列點擊 --- */
        $(document).on('click', '.video-row', function () {
            $('.video-row').removeClass('focused');
            $(this).addClass('focused');
            const id = $(this).data('id');

            $('#focus-id').val(id);                 // ★ 新增：送出表單時帶上
            focusMasterFace(id);
            this.scrollIntoView({behavior: 'smooth', block: 'center'});
        });

        /* --- Hover 放大截圖 --- */
        const $modal = $('#image-modal'), $modalImg = $modal.find('img');
        $(document).on('mouseenter', '.hover-zoom', function () {
            $modalImg.attr('src', $(this).attr('src'));
            $modal.addClass('active');
        }).on('mouseleave', '.hover-zoom', function () {
            $modal.removeClass('active');
            $modalImg.attr('src', '');
        });

        /* --- 全螢幕按鈕 --- */
        $(document).on('click', '.fullscreen-btn', function (e) {
            e.stopPropagation();
            enterFullScreen($(this).siblings('video')[0]);
        });

        /* --- 影片結束事件 --- */
        $(document).on('ended', 'video', onVideoEnded);

        /* --- 捲動載入更多 --- */
        $(window).scroll(() => {
            if ($(window).scrollTop() <= 100) loadMoreVideos('up');
            if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100) loadMoreVideos('down');
        });

        /* --- 全螢幕變動 --- */
        document.addEventListener('fullscreenchange', () => {
            const fs = document.fullscreenElement;

            if (fs && $(fs).is('video')) {             // 進入全螢幕
                currentFSVideo = fs;
                fs.addEventListener('ended', onVideoEnded);

                /* ------- 全螢幕一定循環 ------- */
                fs.loop = true;
                fs.setAttribute('loop', '');

            } else if (currentFSVideo) {               // 離開全螢幕
                /* ------- 恢復 playMode (0=循環、1=自動下一部) ------- */
                const shouldLoop = (playMode === '0');
                currentFSVideo.loop = shouldLoop;
                if (shouldLoop) {
                    currentFSVideo.setAttribute('loop', '');
                } else {
                    currentFSVideo.removeAttribute('loop');
                }

                currentFSVideo = null;
            }
        });

        /* --- 滑鼠範圍左右鈕 & 觸控 --- */
        let ctrlTimeout, ctrlVisible = false, prevVisible = false, nextVisible = false;

        function showFSControls() {
            $('#fullscreen-controls').addClass('show');
            ctrlVisible = true;
        }

        function hideFSControls() {
            $('#fullscreen-controls').removeClass('show');
            ctrlVisible = false;
        }

        function onVideoMouseMove(e) {
            const v = e.currentTarget, rect = v.getBoundingClientRect(), x = e.clientX - rect.left, edge = 50;
            if (x < edge) {
                !prevVisible && ($('.prev-video-btn').addClass('show'), prevVisible = true);
            } else {
                prevVisible && ($('.prev-video-btn').removeClass('show'), prevVisible = false);
            }
            if (x > rect.width - edge) {
                !nextVisible && ($('.next-video-btn').addClass('show'), nextVisible = true);
            } else {
                nextVisible && ($('.next-video-btn').removeClass('show'), nextVisible = false);
            }
            if (!ctrlVisible) showFSControls();
            clearTimeout(ctrlTimeout);
            ctrlTimeout = setTimeout(() => {
                hideFSControls();
                $('.prev-video-btn,.next-video-btn').removeClass('show');
                prevVisible = nextVisible = false;
            }, 3000);
        }

        function onTouchStart(e) {
            this._tx = e.changedTouches[0].clientX;
            this._ty = e.changedTouches[0].clientY;
        }

        function onTouchEnd(e) {
            const dx = e.changedTouches[0].clientX - this._tx, dy = e.changedTouches[0].clientY - this._ty;
            if (Math.abs(dx) > Math.abs(dy)) {
                dx > 50 ? playAt(Math.min(videoList.length - 1, currentVideoIndex + 1))
                    : dx < -50 ? playAt(Math.max(0, currentVideoIndex - 1)) : 0;
            } else {
                dy > 50 ? toggleLoop() : dy < -50 ? playRandom() : 0;
            }
        }

        function toggleLoop() {
            if (currentFSVideo) {
                currentFSVideo.loop = !currentFSVideo.loop;
                showMessage('success', currentFSVideo.loop ? '單部循環已開啟' : '單部循環已關閉');
            }
        }

        function playRandom() {
            let r = Math.floor(Math.random() * videoList.length);
            if (r === currentVideoIndex) r = (r + 1) % videoList.length;
            playAt(r);
        }

        $(document).on('mousemove', 'video', function (e) {
            if (document.fullscreenElement === this) onVideoMouseMove(e);
        });
        $(document).on('touchstart', 'video', function (e) {
            if (document.fullscreenElement === this) onTouchStart.call(this, e);
        }, {passive: true});
        $(document).on('touchend', 'video', function (e) {
            if (document.fullscreenElement === this) onTouchEnd.call(this, e);
        }, {passive: true});

        $('#prev-video-btn').click(() => currentVideoIndex > 0 ? playAt(currentVideoIndex - 1) : showMessage('error', '已經是第一部影片'));
        $('#next-video-btn').click(() => currentVideoIndex < videoList.length - 1 ? playAt(currentVideoIndex + 1) : showMessage('error', '已經是最後一部影片'));

        /* --- 拖拉上傳人臉截圖 --- */
        function normalizeFaceUploadFiles(files) {
            return Array.from(files || [])
                .filter(file => file && /^image\//.test(file.type || ''))
                .map((file, index) => {
                    const ext = (file.type || 'image/png').split('/')[1] || 'png';
                    const hasName = file.name && /\.[a-z0-9]+$/i.test(file.name);
                    return hasName ? file : new File([file], `face-upload-${Date.now()}-${index}.${ext}`, {
                        type: file.type || `image/${ext}`
                    });
                });
        }

        function clearFacePastePreview($target) {
            const previewUrl = $target.data('previewUrl');
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            $target.removeData('pendingFaceFile').removeData('previewUrl').removeClass('has-preview');
            $target.find('.face-paste-preview').attr('src', '');
        }

        function setFacePastePreview($target, file) {
            const normalizedFiles = normalizeFaceUploadFiles([file]);
            if (!normalizedFiles.length) {
                showMessage('error', '請貼上圖片檔案。');
                return false;
            }

            clearFacePastePreview($target);

            const previewFile = normalizedFiles[0];
            const previewUrl = URL.createObjectURL(previewFile);

            $target.data('pendingFaceFile', previewFile);
            $target.data('previewUrl', previewUrl);
            $target.addClass('has-preview');
            $target.find('.face-paste-preview').attr('src', previewUrl);

            return true;
        }

        function appendUploadedFaces(vid, faces) {
            const tpl = $('#face-screenshot-template').html();
            const $area = $('.face-upload-area[data-video-id="' + vid + '"]');
            const $pasteTarget = $area.find('.face-paste-target').first();

            faces.forEach(f => {
                const html = tpl.replace('{is_master_class}', f.is_master ? 'master' : '')
                    .replace(/{face_image_path}/g, f.face_image_path)
                    .replace(/{face_id}/g, f.id)
                    .replace(/{video_id}/g, vid);

                if ($pasteTarget.length) {
                    $pasteTarget.before(html);
                } else {
                    $area.prepend(html);
                }
            });

            applySizes();
        }

        function uploadFaceImages(vid, files, options = {}) {
            const normalizedFiles = normalizeFaceUploadFiles(files);
            if (!normalizedFiles.length) {
                showMessage('error', '請貼上或選擇圖片檔案。');
                return;
            }

            const requestKey = String(vid);
            if (pendingFaceUploads.has(requestKey)) {
                showMessage('error', '這部影片的人臉截圖正在上傳，請稍候。');
                return;
            }

            const fd = new FormData();
            normalizedFiles.forEach(file => fd.append('face_images[]', file));
            fd.append('video_id', vid);

            const $area = $('.face-upload-area[data-video-id="' + vid + '"]');
            pendingFaceUploads.add(requestKey);
            $area.addClass('is-uploading');

            $.ajax({
                url: "{{ route('video.uploadFaceScreenshot') }}",
                method: 'POST', data: fd, contentType: false, processData: false,
                headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}'},
                success(res) {
                    if (res && res.success) {
                        appendUploadedFaces(vid, res.data || []);
                        if (typeof options.onSuccess === 'function') {
                            options.onSuccess();
                        }
                        showMessage('success', '人臉截圖上傳成功。');
                    } else {
                        showMessage('error', res.message);
                    }
                },
                error(xhr) {
                    const message = xhr?.responseJSON?.message || '上傳失敗，請稍後再試。';
                    showMessage('error', message);
                },
                complete() {
                    pendingFaceUploads.delete(requestKey);
                    $area.removeClass('is-uploading');
                }
            });
        }

        $(document).on('dragover', '.face-upload-area', function (e) {
            e.preventDefault();
            $(this).addClass('dragover');
        })
            .on('dragleave', '.face-upload-area', function () {
                $(this).removeClass('dragover');
            })
            .on('drop', '.face-upload-area', function (e) {
                e.preventDefault();
                $(this).removeClass('dragover');
                uploadFaceImages($(this).data('video-id'), e.originalEvent.dataTransfer.files);
            });

        /* --- 刪除截圖 --- */
        $(document).on('click', '.face-paste-target', function () {
            $(this).trigger('focus');
        });

        $(document).on('keydown', '.face-paste-target', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const $target = $(this);
                const pendingFile = $target.data('pendingFaceFile');

                if (!pendingFile) {
                    showMessage('error', '請先貼上圖片預覽。');
                    return;
                }

                uploadFaceImages($target.closest('.face-upload-area').data('video-id'), [pendingFile], {
                    onSuccess() {
                        clearFacePastePreview($target);
                    }
                });
                return;
            }

            if (!e.ctrlKey && !e.metaKey && !['Tab', 'Shift', 'Control', 'Meta', 'Alt'].includes(e.key)) {
                e.preventDefault();
            }
        });

        $(document).on('paste', '.face-paste-target', function (e) {
            e.preventDefault();
            const files = Array.from(e.originalEvent.clipboardData?.items || [])
                .filter(item => item.kind === 'file' && /^image\//.test(item.type || ''))
                .map(item => item.getAsFile())
                .filter(Boolean);

            if (!files.length) {
                showMessage('error', '剪貼簿裡沒有可預覽的圖片。');
                return;
            }

            setFacePastePreview($(this), files[0]);
        });

        $(document).on('click', '.delete-icon', function (e) {
            e.stopPropagation();
            const id = $(this).data('id'), type = $(this).data('type');
            $.post("{{ route('video.deleteScreenshot') }}", {id, type, _token: '{{ csrf_token() }}'}, res => {
                if (res && res.success) {
                    $(this).closest(type === 'screenshot' ? '.screenshot-container' : '.face-screenshot-container').remove();
                    applySizes();
                    showMessage('success', '圖片刪除成功。');
                } else showMessage('error', res.message);
            }).fail(() => showMessage('error', '刪除失敗，請稍後再試。'));
        });

        /* --- 設為主面人臉 --- */
        function setMaster(faceId, vid) {
            const requestKey = String(vid);
            if (pendingMasterUpdates.has(requestKey)) {
                return;
            }

            const $area = $('.face-upload-area[data-video-id="' + vid + '"]');
            pendingMasterUpdates.add(requestKey);
            $area.addClass('is-saving-master');

            $.post("{{ route('video.setMasterFace') }}", {face_id: faceId, _token: '{{ csrf_token() }}'}, res => {
                if (res && res.success) {
                    $(`.face-screenshot[data-video-id="${vid}"]`).removeClass('master');
                    $(`.face-screenshot[data-id="${faceId}"]`).addClass('master');
                    updateMasterFace(res.data);
                    showMessage('success', '主面人臉已更新。');
                } else showMessage('error', res.message);
            }).fail(xhr => {
                const message = xhr?.responseJSON?.message || '更新失敗，請稍後再試。';
                showMessage('error', message);
            }).always(() => {
                pendingMasterUpdates.delete(requestKey);
                $area.removeClass('is-saving-master');
            });
        }

        $(document).on('click', '.face-screenshot', function (e) {
            e.stopPropagation();
            const $img = $(this);
            const vid = $img.data('video-id');
            const $row = $img.closest('.video-row');

            $('.video-row').removeClass('focused');
            $row.addClass('focused');
            $('#focus-id').val(vid);
            focusMasterFace(vid);

            if (!$img.hasClass('master')) {
                setMaster($img.data('id'), vid);
            }
        });
        $(document).on('click', '.set-master-btn', function (e) {
            e.stopPropagation();
            setMaster($(this).data('id'), $(this).data('video-id'));
        });

        /* ------------------ 左欄主面人臉 → 聚焦影片 ------------------ */
        $(document).off('click', '.master-face-img');     // 先解除舊綁定，避免重複
        $(document).on('click', '.master-face-img', function () {
            const vid = $(this).data('video-id');

            /* 1. 試著找目前頁是否已有影片 */
            const $row = $('.video-row[data-id="' + vid + '"]');
            if ($row.length) {
                $('.video-row').removeClass('focused');
                $row.addClass('focused');
                focusMasterFace(vid);
                $row[0].scrollIntoView({behavior: 'smooth', block: 'center'});
                return;
            }

            /* 2. 不在目前頁 → 先查頁碼，再載入並聚焦 */
            $.get("{{ route('video.findPage') }}", {
                video_id: vid,
                video_type: videoType,
                missing_only: missingOnly ? 1 : 0,   // ⭐ 加上缺主面篩選
                sort_by: sortBy,                 // ⭐ 加上排序依據
                sort_dir: sortDir                 // ⭐ 加上排序方向
            }, res => {
                if (res?.success && res.page) {
                    loadPageAndFocus(vid, res.page);
                } else {
                    showMessage('error', '找不到該影片所在的頁面。');
                }
            }).fail(() => showMessage('error', '查詢失敗，請稍後再試。'));
        });

        /* --- 監聽排列拖曳 --- */
        $("#videos-list").sortable({
            placeholder: "ui-state-highlight", delay: 150, cancel: "video, .fullscreen-btn, img, button"
        }).disableSelection();

        /* --- 初始建構 --- */
        buildVideoList();
        applySizes();
        focusInitial();
        loadMasterFacesPage(1, true);

        $('.master-faces').on('scroll', function () {
            if (masterFacesLoading || masterFacesPage >= masterFacesLastPage) {
                return;
            }

            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 180) {
                loadMasterFacesPage(masterFacesPage + 1);
            }
        });

        /* ----------- 主面人臉側欄開關 ----------- */
        const $btnToggle = $('#toggle-master-faces');
        const $sidebar = $('.master-faces');
        const $content = $('.container');
        const $controls = $('.controls');

        function updateToggleState(collapsed) {
            if (collapsed) {
                $sidebar.addClass('collapsed');
                $content.removeClass('expanded');
                $controls.removeClass('expanded');
                updateBtnPos(true);
                $btnToggle.html('☰');
            } else {
                $sidebar.removeClass('collapsed');
                $content.addClass('expanded');
                $controls.addClass('expanded');
                updateBtnPos(false);
                $btnToggle.html('❮');
            }
        }

        // 預設展開（You can set collapsed=true if you want）
        let collapsed = false;
        updateToggleState(collapsed);

        $btnToggle.on('click', () => {
            collapsed = !collapsed;
            updateToggleState(collapsed);
        });

        /* ------- ① 進頁面就把鈕頂到 h5 標題同高 ------- */
        const headerTop = $('.master-faces h5').offset().top;   // 與視窗頂端距離
        $('#toggle-master-faces').css('top', headerTop + 'px');

        /* ------- ② 監聽側欄開關，控制 .inside class ------- */

        // const $btnToggle = $('#toggle-master-faces');
        function updateBtnPos(collapsed) {
            collapsed ? $btnToggle.removeClass('inside')
                : $btnToggle.addClass('inside');
        }

        /* --------------------------------------------------
         * 確保送出前寫入 focus-id
         * -------------------------------------------------- */
        $('#controls-form').on('submit', function () {
            const fid = $('.video-row.focused').data('id') || '';
            $('#focus-id').val(fid);          // ← 送出表單前最後覆寫
        });
    });

    /* --------------------------------------------------
     * ResizeObserver
     * -------------------------------------------------- */
    const ro = new ResizeObserver(entries => {
        entries.forEach(ent => {
            if ($(ent.target).hasClass('focused'))
                ent.target.scrollIntoView({behavior: 'auto', block: 'center'});
        });
    });

    function watchFocusedRow() {
        ro.disconnect();
        const f = document.querySelector('.video-row.focused');
        if (f) ro.observe(f);
    }

    const listRO = new ResizeObserver(() => {
        const f = document.querySelector('.video-row.focused');
        if (!f) return;
        const rect = f.getBoundingClientRect(), vp = window.innerHeight / 2;
        if (Math.abs(rect.top + rect.height / 2 - vp) > 10)
            f.scrollIntoView({behavior: 'auto', block: 'center'});
    });
    listRO.observe(document.getElementById('videos-list'));

    /* --------------------------------------------------
     * 永遠聚焦最新 id 的那支影片
     * -------------------------------------------------- */
    function focusMaxId() {
        if (latestId === null) return;

        const $target = $('.video-row[data-id="' + latestId + '"]');

        if ($target.length) {
            $('.video-row').removeClass('focused');
            $target.addClass('focused');
            focusMasterFace(latestId);
            $target[0].scrollIntoView({behavior: 'smooth', block: 'center'});
        } else {
            // 這一頁沒有 → 動態查詢它在第幾頁，載進來再聚焦
            $.get("{{ route('video.findPage') }}", {video_id: latestId, video_type: videoType}, res => {
                if (res?.success && res.page) {
                    loadPageAndFocus(latestId, res.page);
                }
            });
        }
    }

    function focusInitial() {
        const targetId = (initialFocusId !== null) ? initialFocusId : latestId;
        if (targetId === null) return;

        const $t = $('.video-row[data-id="' + targetId + '"]');
        if ($t.length) {
            $('.video-row').removeClass('focused');
            $t.addClass('focused');
            focusMasterFace(targetId);
            $t[0].scrollIntoView({behavior: 'smooth', block: 'center'});
        }
        // 用完即丟，避免之後 rebuild 又蓋掉使用者手動選擇
        initialFocusId = null;                                     // ★ 新增
    }

    function getFaceSortParts(el) {
        const $el = $(el);
        const videoId = parseInt($el.data('video-id'), 10) || 0;
        const duration = parseFloat($el.data('duration')) || 0;

        return sortBy === 'duration'
            ? {primary: duration, secondary: videoId}
            : {primary: videoId, secondary: videoId};
    }

    function compareFaces(a, b) {
        const left = getFaceSortParts(a);
        const right = getFaceSortParts(b);

        return compareWithTiebreaker(left.primary, left.secondary, right.primary, right.secondary);
    }

    function buildMasterFaceElement(face) {
        const img = document.createElement('img');
        img.src = baseVideoUrl + '/' + normalizeMediaPath(face.face_image_path);
        img.className = 'master-face-img';
        img.alt = '主面人臉';
        img.dataset.videoId = String(face.video_id);
        img.dataset.duration = String(Number(face.video_duration) || 0);
        img.loading = 'lazy';
        img.decoding = 'async';
        img.fetchPriority = 'low';
        img.title = (face.video_name || '影片') + ' #' + face.video_id;

        return img;
    }

    function insertMasterFaceInOrder(el) {
        const $c = $('.master-face-images');
        const items = $c.children('img.master-face-img').get();
        let inserted = false;

        for (let i = 0; i < items.length; i++) {
            if (compareFaces(el, items[i]) < 0) {
                $(items[i]).before(el);
                inserted = true;
                break;
            }
        }
        if (!inserted) {
            $c.append(el);
        }
    }

    function repositionMasterFace(el) {
        const $el = $(el);
        $el.detach();
        insertMasterFaceInOrder(el);
    }

    function appendMasterFaces(faces, reset = false) {
        const $container = $('.master-face-images');
        if (reset) {
            $container.empty();
            masterFacesLoadedCount = 0;
        }

        faces.forEach(face => {
            const videoId = parseInt(face.video_id, 10) || 0;
            if (!videoId) {
                return;
            }

            const $existing = $container.children(`img.master-face-img[data-video-id="${videoId}"]`);
            if ($existing.length) {
                $existing
                    .attr('src', baseVideoUrl + '/' + normalizeMediaPath(face.face_image_path))
                    .attr('data-duration', Number(face.video_duration) || 0)
                    .attr('title', (face.video_name || '影片') + ' #' + face.video_id);
                repositionMasterFace($existing[0]);
                return;
            }

            insertMasterFaceInOrder(buildMasterFaceElement(face));
            masterFacesLoadedCount += 1;
        });

        const focusedId = getCurrentFocusedVideoId();
        if (focusedId) {
            focusMasterFace(focusedId);
        }

        updateMasterFacesStatus(
            masterFacesPage < masterFacesLastPage
                ? `主面人臉已載入 ${masterFacesLoadedCount} 張，背景續載中...`
                : `主面人臉已載入完成，共 ${masterFacesLoadedCount} 張。`,
            masterFacesLoadedCount > 0 && masterFacesPage >= masterFacesLastPage
        );
    }

    function queueMasterFacesPrefetch() {
        if (masterFacesLoading || masterFacesPage >= masterFacesLastPage) {
            return;
        }

        const schedule = window.requestIdleCallback
            ? cb => window.requestIdleCallback(cb, {timeout: 1200})
            : cb => setTimeout(cb, 180);

        schedule(() => {
            if (!masterFacesLoading && masterFacesPage < masterFacesLastPage) {
                loadMasterFacesPage(masterFacesPage + 1);
            }
        });
    }

    function loadMasterFacesPage(page = 1, reset = false) {
        if (masterFacesLoading) {
            return;
        }

        masterFacesLoading = true;
        if (reset) {
            masterFacesPage = 0;
            masterFacesLastPage = 1;
            updateMasterFacesStatus('主面人臉載入中...');
        } else {
            updateMasterFacesStatus(`主面人臉已載入 ${masterFacesLoadedCount} 張，繼續同步中...`);
        }

        $.ajax({
            url: "{{ route('video.loadMasterFaces') }}",
            method: 'GET',
            data: {
                page,
                per_page: 160,
                video_type: videoType,
                sort_by: sortBy,
                sort_dir: sortDir
            },
            success(res) {
                if (res?.success) {
                    masterFacesPage = Number(res.current_page || page) || page;
                    masterFacesLastPage = Number(res.last_page || masterFacesPage) || masterFacesPage;
                    appendMasterFaces(Array.isArray(res.data) ? res.data : [], reset);

                    if (masterFacesPage < masterFacesLastPage) {
                        queueMasterFacesPrefetch();
                    }
                } else {
                    updateMasterFacesStatus(res?.message || '主面人臉載入失敗。');
                }
            },
            error() {
                updateMasterFacesStatus('主面人臉載入失敗，請稍後再試。');
            },
            complete() {
                masterFacesLoading = false;
            }
        });
    }

    function updateMasterFace(face) {
        const videoId = parseInt(face.video_id, 10) || 0;
        if (!videoId) {
            showMessage('error', '主面人臉同步失敗：缺少影片資訊。');
            return;
        }

        appendMasterFaces([face], false);
        updateMasterFacesStatus(
            masterFacesPage < masterFacesLastPage
                ? `主面人臉已載入 ${masterFacesLoadedCount} 張，背景續載中...`
                : `主面人臉已載入完成，共 ${masterFacesLoadedCount} 張。`,
            masterFacesLoadedCount > 0 && masterFacesPage >= masterFacesLastPage
        );
        applySizes();
        applyMediaPerfOptimizations();
    }

    function resortMasterFacesByCurrentSort() {
        const $c = $('.master-face-images');
        const arr = $c.children('img.master-face-img').get();
        arr.sort(compareFaces);
        $c.empty().append(arr);
    }

    function applyMediaPerfOptimizations() {
        // 1) 避免列表中的每支影片預載，否則會把頻寬吃光，連帶拖慢所有圖片載入
        $('#videos-list video').each(function () {
            const $v = $(this);
            if (($v.attr('preload') || '').toLowerCase() !== 'none') {
                $v.attr('preload', 'none');
            }
        });

        // 2) 若列表影片沒有 poster，就用該列第一張「影片截圖」當 poster（只補一次）
        $('#videos-list .video-row').each(function () {
            const $row = $(this);
            const $video = $row.find('video').first();
            if (!$video.length) return;

            const poster = ($video.attr('poster') || '').trim();
            if (poster.length > 0) return;

            const $firstShot = $row.find('img.screenshot').first();
            if (!$firstShot.length) return;

            const shotSrc = ($firstShot.attr('src') || '').trim();
            if (shotSrc.length === 0) return;

            $video.attr('poster', shotSrc);
        });

        // 3) 主面人臉縮圖：統一每張只佔 1 格（清掉可能殘留的 landscape）
        $('.master-face-img').removeClass('landscape');
    }

    /* === 取代原有對 video mousemove 的綁定，加入快轉邏輯 === */
    $(document).off('mousemove', 'video');
    $(document).on('mousemove', 'video', function (e) {
        // 全螢幕時維持原控制條邏輯
        if (document.fullscreenElement === this) {
            onVideoMouseMove(e);
            return;
        }
        // 非全螢幕：左右移動即時快轉
        const rect = this.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const percent = x / rect.width;
        if (percent >= 0 && percent <= 1 && this.duration) {
            this.currentTime = percent * this.duration;
        }
    });
</script>
</body>
</html>
