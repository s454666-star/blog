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
            --scrollbar-size: 15px;
            --scrollbar-track: linear-gradient(180deg, rgba(255, 255, 255, .95), rgba(241, 225, 255, .88));
            --scrollbar-thumb: linear-gradient(180deg, rgba(208, 123, 255, .96), rgba(127, 59, 208, .96));
            --scrollbar-thumb-hover: linear-gradient(180deg, rgba(219, 152, 255, .98), rgba(145, 74, 224, .98));
            --scrollbar-thumb-active: linear-gradient(180deg, rgba(123, 204, 159, .96), rgba(89, 164, 123, .96));
            --scrollbar-thumb-shadow: 0 8px 18px rgba(124, 76, 168, .22);
            --scrollbar-track-shadow: inset 0 0 0 1px rgba(165, 92, 246, .12), inset 0 8px 18px rgba(165, 92, 246, .08);
            --scrollbar-button-bg: linear-gradient(135deg, rgba(255, 255, 255, .95), rgba(244, 230, 255, .95));
            --scrollbar-button-hover: linear-gradient(135deg, rgba(255, 255, 255, .98), rgba(236, 215, 255, .98));
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

        html,
        body,
        .master-faces,
        .screenshot-images .d-flex,
        .face-screenshot-images .d-flex {
            scrollbar-width: thin;
            scrollbar-color: rgba(127, 59, 208, .92) rgba(241, 225, 255, .5);
        }

        *::-webkit-scrollbar {
            width: var(--scrollbar-size);
            height: var(--scrollbar-size);
        }

        *::-webkit-scrollbar-track {
            border: 3px solid transparent;
            border-radius: 999px;
            background: var(--scrollbar-track);
            background-clip: padding-box;
            box-shadow: var(--scrollbar-track-shadow);
        }

        *::-webkit-scrollbar-thumb {
            border: 3px solid transparent;
            border-radius: 999px;
            background: var(--scrollbar-thumb);
            background-clip: padding-box;
            box-shadow: var(--scrollbar-thumb-shadow);
        }

        *::-webkit-scrollbar-thumb:hover {
            background: var(--scrollbar-thumb-hover);
            background-clip: padding-box;
        }

        *::-webkit-scrollbar-thumb:active {
            background: var(--scrollbar-thumb-active);
            background-clip: padding-box;
        }

        *::-webkit-scrollbar-corner {
            background: transparent;
        }

        *::-webkit-scrollbar-button:single-button {
            display: block;
            width: var(--scrollbar-size);
            height: var(--scrollbar-size);
            border: 3px solid transparent;
            border-radius: 999px;
            background: var(--scrollbar-button-bg);
            background-position: center;
            background-repeat: no-repeat;
            background-size: 10px 10px;
            background-clip: padding-box;
            box-shadow: 0 5px 12px rgba(124, 76, 168, .12);
        }

        *::-webkit-scrollbar-button:single-button:hover {
            background: var(--scrollbar-button-hover);
            background-position: center;
            background-repeat: no-repeat;
            background-size: 10px 10px;
            background-clip: padding-box;
        }

        *::-webkit-scrollbar-button:single-button:active {
            background: linear-gradient(135deg, rgba(236, 215, 255, .98), rgba(223, 245, 232, .95));
            background-position: center;
            background-repeat: no-repeat;
            background-size: 10px 10px;
            background-clip: padding-box;
        }

        *::-webkit-scrollbar-button:single-button:vertical:decrement {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath d='M5.2 12.4 10 7.6l4.8 4.8' fill='none' stroke='%237f3bd0' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }

        *::-webkit-scrollbar-button:single-button:vertical:increment {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath d='M5.2 7.6 10 12.4l4.8-4.8' fill='none' stroke='%237f3bd0' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }

        *::-webkit-scrollbar-button:single-button:horizontal:decrement {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath d='M12.4 5.2 7.6 10l4.8 4.8' fill='none' stroke='%237f3bd0' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }

        *::-webkit-scrollbar-button:single-button:horizontal:increment {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20'%3E%3Cpath d='M7.6 5.2 12.4 10l-4.8 4.8' fill='none' stroke='%237f3bd0' stroke-width='2.3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        }

        /* === 敶梁???================================================= */
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

        /* === 敶梁?璅?? =========================================== */
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

        /* 霈楝敺?擃?敺桀?銝暺??啗嚗?頝?瘚? */
        .video-path {
            font-size: .85em;
            color: var(--theme-text-soft);
            font-weight: 400;
        }

        /* === 撠?璅?蝢?嚗撓撅斗???蝺?+ 敺桀???===================== */
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

            /* 摨?撌血瘚?? */
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

        /* === 銝?內蝢?嚗??脣? + 撌血?脩垢銝?內 =============== */
        .upload-instructions {
            font-size: .9rem;
            font-weight: 600;
            color: var(--theme-accent-strong);
            letter-spacing: .5px;
            position: relative;
            padding-left: 26px; /* ???內蝛粹? */
            width: 100%;
            text-align: center;
            margin-top: 12px;
        }

        /* ? SVG ?內嚗? CSS嚗??憿?瑼?嚗?*/
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

        /* === 銝駁鈭箄?蝮桀?嚗虜????+ Hover 瞍詨惜? =================== */
        .master-face-img {
            position: relative;
            border-radius: 6px;
            overflow: hidden;
        }

        /* 1. 撣豢?嚗??賣?嚗?批 box?hadow嚗?*/
        .master-face-img::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, .35) inset;
            transition: opacity .4s;
            pointer-events: none;
            z-index: 1; /* ?踹?鋡思???:before ?? */
        }

        /* 2. Hover嚗撓撅斗????堆??曉 :before嚗???啁獢? */
        .master-face-img::before {
            content: '';
            position: absolute;
            inset: -2px; /* 蝔凝???嚗??唳憿舐 */
            border-radius: inherit;
            background: linear-gradient(135deg, var(--theme-accent-2) 0%, var(--theme-accent) 50%, var(--theme-accent-2) 100%);
            background-size: 300% 300%;
            opacity: 0;
            transition: opacity .4s;
            pointer-events: none;
            z-index: 0;
        }

        /* ???恍嚗??交?暺漁銝行???*/
        .master-face-img:hover::before {
            opacity: 1;
            animation: borderFlow 3s linear infinite;
        }

        /* 撌脰??佗?.focused嚗?瘞賊?靽?鈭桀? */
        .master-face-img.focused::before {
            opacity: 1;
            animation: borderFlow 3s linear infinite;
        }

        /* 瘚??敶望 */
        @keyframes borderFlow {
            0% {
                background-position: 0% 50%;
            }
            100% {
                background-position: 200% 50%;
            }
        }

        /* === 敶梁???捆??=== */
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

        /* === ?啣?嚗???格頠?=== */
        .screenshot-images .d-flex,
        .face-screenshot-images .d-flex {
            max-height: 250px;
            overflow-y: auto;
            overflow-x: hidden
        }

        /* === ?曉之?? (hover) === */
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

        /* === 摨?批??=== */
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

        /* === 銝駁鈭箄??湔? === */
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

        /* ?箏?銝??4 撘蛛?瘥撐?賭? 1 ??*/
        .master-face-images {
            display: grid;
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap: 10px;
            width: 100%
        }

        /* 霈??葬?曉??脫摮??剜嚗?鋆?嚗?*/
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

        /* === 銝餉??批捆? === */
        .container {
            margin-left: 30%;
            padding-top: 24px;
            padding-bottom: 104px
        }

        /* === 敹怨?閮 === */
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

        /* === ?芷?身?箔蜓?Ｘ???=== */
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

        /* === ?刻撟芋撘???=== */
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

        /* === RWD 隤踵 === */
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

        /* === Bootstrap Container 撖砍漲銝?隤踵 (憭扯撟? === */
        @media (min-width: 1200px) {
            .container, .container-lg, .container-md, .container-sm, .container-xl {
                max-width: 1750px
            }

            /* ?湔?撅????踹???FHD / 蝟餌絞蝮格??銝摰寡??箄?蝒??*/
            .container.expanded {
                max-width: min(1750px, 70%)
            }
        }

        /* === 銝駁鈭箄??湔???? =========================== */
        .master-faces {
            transition: transform .45s ease-in-out; /* 皛?? */
        }

        .master-faces.collapsed {
            transform: translateX(-100%); /* 摰?撌血 */
        }

        /* ?批捆?頝雿宏 ???湔??margin-left 霈摰寡?朣撟?*/
        .container {
            transition: margin-left .45s ease-in-out;
        }

        .container.expanded { /* ?湔?撅??雁?? 30% ?? */
            margin-left: 30%;
        }

        /* ???頨恬??芣??X嚗 ??JS 撖怠 inline-style */
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

        /* 撅????JS ?? .inside嚗??湔???典?湔??批蝺?*/
        #toggle-master-faces.inside {
            transform: translateX(calc(260px - 50%)) rotate(180deg);
            opacity: .6;
        }

        #toggle-master-faces.hide { /* 撅??葬?脣甈?*/
            opacity: .6;
            transform: translate(calc(-50% + 260px), 0) rotate(180deg);
        }

        .controls {
            position: fixed;
            bottom: 0;
            transition: left .45s ease-in-out; /* ?啣?? */
        }

        .controls.expanded {
            left: 30%;
        }

        /* ?湔?撅?蝬剜???蝵?*/

        /* ---------- 霈?閮剖祝摨西? 100%嚗??.expanded ?? 30% ---------- */
        .container {
            margin-left: 0 !important;
        }

        /* ?嗅???皛?*/
        .controls {
            left: 0 !important;
        }

        /* ?嗅??票撌?*/

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

        /* === 控制列元件重製 === */
        .container {
            padding-bottom: 172px;
        }

        .controls {
            left: 0 !important;
            right: 0;
            padding: 16px 18px 18px;
            overflow: hidden;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, .82), rgba(248, 236, 255, .96)),
                rgba(255, 249, 255, .94);
            border-top: 1px solid rgba(163, 110, 214, .28);
            box-shadow: 0 -24px 50px rgba(124, 76, 168, .16);
            backdrop-filter: blur(18px);
        }

        .controls.expanded {
            left: 30% !important;
        }

        .controls::before {
            content: '';
            position: absolute;
            top: 0;
            left: 18px;
            right: 18px;
            height: 1px;
            background: linear-gradient(90deg, rgba(165, 92, 246, 0), rgba(165, 92, 246, .7), rgba(165, 92, 246, 0));
        }

        .controls-form {
            position: relative;
            z-index: 1;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            width: 100%;
            align-items: stretch;
        }

        .controls .control-group {
            margin: 0;
            min-width: 0;
            max-width: 220px;
            flex: 1 1 160px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 10px;
            padding: 14px 16px;
            border-radius: 22px;
            border: 1px solid rgba(163, 110, 214, .2);
            background:
                radial-gradient(circle at top right, rgba(255, 255, 255, .92), rgba(255, 255, 255, 0) 42%),
                linear-gradient(145deg, rgba(255, 255, 255, .95), rgba(245, 233, 255, .9));
            box-shadow: 0 16px 30px rgba(124, 76, 168, .12);
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
        }

        .controls .control-group:hover {
            transform: translateY(-2px);
            border-color: rgba(163, 110, 214, .4);
            box-shadow: 0 20px 38px rgba(124, 76, 168, .16);
        }

        .controls .control-group--action {
            flex: 1 1 220px;
            max-width: 250px;
            margin-left: auto;
        }

        .controls .control-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            min-height: 28px;
            margin-bottom: 2px;
        }

        .controls .control-label {
            margin: 0;
            color: var(--theme-text-soft);
            font-size: .8rem;
            font-weight: 800;
            letter-spacing: .08em;
            line-height: 1.1;
        }

        .controls .control-label--ghost {
            opacity: .75;
        }

        .controls .control-status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 64px;
            min-height: 30px;
            padding: 0 12px;
            border-radius: 999px;
            border: 1px solid rgba(165, 92, 246, .2);
            background: rgba(255, 255, 255, .86);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .88), 0 8px 18px rgba(124, 76, 168, .1);
            color: var(--theme-accent-strong);
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .controls .control-status.is-active {
            border-color: rgba(165, 92, 246, .34);
            background: linear-gradient(135deg, rgba(165, 92, 246, .18), rgba(208, 123, 255, .24));
            color: var(--theme-accent-strong);
        }

        .controls .control-range {
            -webkit-appearance: none;
            appearance: none;
            width: 100%;
            height: 12px;
            margin: 0;
            border: none;
            outline: none;
            border-radius: 999px;
            cursor: pointer;
            background: linear-gradient(
                90deg,
                var(--theme-accent) 0,
                var(--theme-accent) var(--range-progress, 50%),
                rgba(165, 92, 246, .14) var(--range-progress, 50%),
                rgba(165, 92, 246, .14) 100%
            );
            box-shadow: inset 0 2px 4px rgba(124, 76, 168, .08), 0 1px 0 rgba(255, 255, 255, .8);
        }

        .controls .control-range:focus {
            box-shadow: inset 0 2px 4px rgba(124, 76, 168, .08), 0 0 0 4px rgba(165, 92, 246, .14);
        }

        .controls .control-range::-webkit-slider-runnable-track {
            height: 12px;
            background: transparent;
            border: none;
        }

        .controls .control-range::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 22px;
            height: 22px;
            margin-top: -5px;
            border: 4px solid #fff;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--theme-accent), var(--theme-accent-strong));
            box-shadow: 0 10px 22px rgba(124, 76, 168, .18);
        }

        .controls .control-range::-moz-range-track {
            height: 12px;
            background: rgba(165, 92, 246, .14);
            border: none;
            border-radius: 999px;
        }

        .controls .control-range::-moz-range-progress {
            height: 12px;
            background: var(--theme-accent);
            border-radius: 999px;
        }

        .controls .control-range::-moz-range-thumb {
            width: 22px;
            height: 22px;
            border: 4px solid #fff;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--theme-accent), var(--theme-accent-strong));
            box-shadow: 0 10px 22px rgba(124, 76, 168, .18);
        }

        .controls .control-select-wrap {
            position: relative;
        }

        .controls .control-select-wrap::after {
            content: '▾';
            position: absolute;
            top: 50%;
            right: 14px;
            transform: translateY(-50%);
            pointer-events: none;
            color: var(--theme-accent-strong);
            font-size: 16px;
        }

        .controls .control-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            width: 100%;
            min-height: 48px;
            padding: 0 42px 0 14px;
            border-radius: 16px;
            border: 1px solid rgba(165, 92, 246, .22);
            background: linear-gradient(135deg, rgba(255, 255, 255, .96), rgba(246, 236, 255, .94));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .88), 0 10px 20px rgba(124, 76, 168, .08);
            color: var(--theme-text);
            font-weight: 700;
        }

        .controls .control-select:focus {
            border-color: rgba(165, 92, 246, .42);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .88), 0 0 0 4px rgba(165, 92, 246, .14);
        }

        .controls .control-action-btn {
            width: 100%;
            min-height: 48px;
            padding: 0 18px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 18px;
            font-weight: 800;
            letter-spacing: .02em;
        }

        @media (max-width: 1700px) {
            .container {
                padding-bottom: 236px;
            }

            .controls .control-group,
            .controls .control-group--action {
                max-width: none;
                flex-basis: calc(25% - 12px);
                margin-left: 0;
            }
        }

        @media (max-width: 1200px) {
            .container {
                padding-bottom: 320px;
            }

            .controls {
                padding: 14px 14px 16px;
            }

            .controls .control-group,
            .controls .control-group--action {
                flex-basis: calc(50% - 12px);
            }
        }

        @media (max-width: 768px) {
            .container {
                padding-bottom: 520px;
            }

            .controls-form {
                gap: 10px;
            }

            .controls .control-group,
            .controls .control-group--action {
                flex-basis: 100%;
            }

            .controls .control-group {
                padding: 12px 14px;
            }
        }
