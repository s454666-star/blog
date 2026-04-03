<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Blog 指令工具台</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&family=Noto+Sans+TC:wght@400;500;700;900&display=swap');

        :root {
            --page-bg: #f7fbff;
            --page-bg-2: #fffaf4;
            --panel: rgba(255, 255, 255, 0.76);
            --line: rgba(83, 122, 167, 0.16);
            --ink-900: #16324a;
            --ink-700: #4a647b;
            --ink-500: #71879a;
            --status-idle: #6b8297;
            --status-running: #216adf;
            --status-success: #128661;
            --status-error: #c44747;
            --shadow-xl: 0 32px 80px rgba(79, 116, 156, 0.18);
            --shadow-lg: 0 22px 50px rgba(93, 133, 175, 0.14);
            --radius-xl: 30px;
            --radius-lg: 24px;
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Plus Jakarta Sans", "Noto Sans TC", sans-serif;
            color: var(--ink-900);
            background:
                radial-gradient(circle at 0% 10%, rgba(79, 168, 255, 0.16), transparent 36%),
                radial-gradient(circle at 100% 0%, rgba(255, 168, 94, 0.18), transparent 30%),
                radial-gradient(circle at 50% 100%, rgba(82, 211, 173, 0.14), transparent 34%),
                linear-gradient(140deg, var(--page-bg) 0%, #fbfdff 42%, var(--page-bg-2) 100%);
            overflow-x: hidden;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            width: 28rem;
            height: 28rem;
            border-radius: 50%;
            filter: blur(40px);
            z-index: -2;
            opacity: 0.54;
            animation: drift 18s ease-in-out infinite alternate;
        }

        body::before {
            top: -9rem;
            right: -7rem;
            background: radial-gradient(circle, rgba(49, 144, 255, 0.34), rgba(49, 144, 255, 0));
        }

        body::after {
            bottom: -10rem;
            left: -8rem;
            background: radial-gradient(circle, rgba(255, 159, 64, 0.28), rgba(255, 159, 64, 0));
            animation-duration: 24s;
        }

        .mesh {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            background-image:
                linear-gradient(rgba(118, 164, 214, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(118, 164, 214, 0.05) 1px, transparent 1px);
            background-size: 34px 34px;
            mask-image: radial-gradient(circle at center, rgba(0, 0, 0, 1) 34%, rgba(0, 0, 0, 0) 100%);
        }

        .shell {
            width: min(1400px, calc(100vw - 24px));
            margin: 0 auto;
            padding: 28px 0 42px;
        }

        .hero,
        .panel {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: var(--radius-xl);
            background: var(--panel);
            backdrop-filter: blur(22px);
            box-shadow: var(--shadow-xl);
        }

        .hero::before,
        .panel::before {
            content: "";
            position: absolute;
            inset: -35%;
            background: conic-gradient(from 160deg, rgba(70, 157, 255, 0.12), rgba(69, 210, 190, 0.08), rgba(255, 168, 94, 0.11), rgba(70, 157, 255, 0.12));
            animation: rotateAura 18s linear infinite;
            z-index: 0;
        }

        .hero > *,
        .panel > * {
            position: relative;
            z-index: 1;
        }

        .hero { padding: 34px; }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px;
            border-radius: 999px;
            border: 1px solid rgba(94, 151, 214, 0.22);
            background: rgba(255, 255, 255, 0.8);
            color: #325f84;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.95fr);
            gap: 26px;
            margin-top: 22px;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(2.4rem, 5vw, 4.4rem);
            line-height: 0.96;
            letter-spacing: -0.06em;
        }

        .hero h1 span {
            display: block;
            background: linear-gradient(110deg, #2683ef 0%, #11b89c 48%, #ff9d47 100%);
            -webkit-background-clip: text;
            color: transparent;
        }

        .hero p {
            margin: 18px 0 0;
            max-width: 760px;
            font-size: 1rem;
            line-height: 1.85;
            color: var(--ink-700);
        }

        .hero-actions,
        .hero-stack,
        .command-grid,
        .card-tags,
        .feature-list,
        .card-actions,
        .output-meta,
        .output-context {
            display: flex;
            gap: 14px;
        }

        .hero-actions,
        .card-tags,
        .card-actions,
        .output-meta,
        .output-context {
            flex-wrap: wrap;
        }

        .hero-actions { margin-top: 26px; }
        .hero-stack,
        .feature-list {
            flex-direction: column;
        }

        .hero-stack { gap: 16px; }

        .action-pill,
        .chip,
        .status-chip,
        .meta-pill,
        .card-tag {
            display: inline-flex;
            align-items: center;
            min-height: 38px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(86, 145, 207, 0.16);
            background: rgba(255, 255, 255, 0.84);
            color: #31526f;
            font-size: 0.9rem;
            font-weight: 800;
        }

        .action-pill,
        .chip {
            min-height: 44px;
            border-radius: 16px;
            box-shadow: 0 14px 28px rgba(102, 145, 190, 0.11);
        }

        .meta-card {
            padding: 20px;
            border-radius: 22px;
            border: 1px solid rgba(94, 151, 214, 0.18);
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.94), rgba(244, 251, 255, 0.84));
            box-shadow: var(--shadow-lg);
        }

        .meta-label {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ink-500);
        }

        .meta-value {
            margin-top: 10px;
            font-size: 1.28rem;
            font-weight: 800;
            line-height: 1.4;
        }

        .meta-sub {
            margin-top: 8px;
            color: var(--ink-700);
            font-size: 0.92rem;
            line-height: 1.65;
        }

        .command-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            margin-top: 24px;
        }

        .command-card {
            --accent-from: #2f8bff;
            --accent-to: #35d4c6;
            --accent-soft: rgba(47, 139, 255, 0.16);
            position: relative;
            padding: 22px;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(90, 145, 203, 0.18);
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.96), rgba(246, 251, 255, 0.78));
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease;
        }

        .command-card::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--accent-from), var(--accent-to));
        }

        .command-card::after {
            content: "";
            position: absolute;
            right: -3rem;
            top: -4rem;
            width: 12rem;
            height: 12rem;
            border-radius: 50%;
            background: radial-gradient(circle, var(--accent-soft), rgba(255, 255, 255, 0));
            pointer-events: none;
        }

        .command-card:hover,
        .command-card.is-active {
            transform: translateY(-6px);
            border-color: rgba(78, 142, 208, 0.32);
            box-shadow: 0 28px 60px rgba(85, 133, 181, 0.18);
        }

        .card-top,
        .output-head,
        .output-toolbar,
        .code-head,
        .terminal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .card-top {
            align-items: flex-start;
        }

        .card-eyebrow {
            display: inline-flex;
            align-items: center;
            padding: 7px 11px;
            border-radius: 999px;
            background: var(--accent-soft);
            color: #265177;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .card-tag {
            min-height: 28px;
            padding: 0 10px;
            color: #43637f;
            font-size: 12px;
        }

        .card-title {
            margin: 16px 0 0;
            font-size: 1.46rem;
            line-height: 1.25;
            letter-spacing: -0.03em;
        }

        .card-summary,
        .card-details,
        .output-title p,
        .footer-note {
            margin: 12px 0 0;
            color: var(--ink-700);
            line-height: 1.72;
            font-size: 0.96rem;
        }

        .feature-item {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            color: #33516c;
            font-size: 0.92rem;
            line-height: 1.65;
        }

        .feature-item::before {
            content: "";
            width: 10px;
            height: 10px;
            margin-top: 0.46rem;
            border-radius: 999px;
            flex: 0 0 auto;
            background: linear-gradient(135deg, var(--accent-from), var(--accent-to));
            box-shadow: 0 0 0 5px var(--accent-soft);
        }

        .code-shell,
        .output-shell {
            overflow: hidden;
            border: 1px solid rgba(84, 143, 204, 0.14);
            background: linear-gradient(180deg, rgba(14, 28, 44, 0.94), rgba(24, 42, 62, 0.95));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        .code-shell {
            margin-top: 18px;
            border-radius: 20px;
        }

        .output-shell {
            margin-top: 18px;
            border-radius: 24px;
            border-color: rgba(88, 145, 204, 0.2);
        }

        .code-head,
        .terminal-head {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.04);
            color: rgba(220, 236, 255, 0.78);
            font-size: 12px;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .code-head-dots {
            display: inline-flex;
            gap: 6px;
        }

        .code-head-dots span {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.26);
        }

        .code-shell pre,
        .terminal-output {
            margin: 0;
            color: #ebf5ff;
            font-family: "Cascadia Code", "Consolas", monospace;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .code-shell pre {
            padding: 16px;
            font-size: 0.85rem;
            line-height: 1.72;
            color: #dbeeff;
        }

        .run-btn,
        .ghost-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 52px;
            padding: 0 18px;
            border-radius: 16px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 800;
            font-size: 0.95rem;
            transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease, filter 180ms ease;
        }

        .run-btn {
            border: 0;
            color: #fff;
            background: linear-gradient(135deg, var(--accent-from), var(--accent-to));
            box-shadow: 0 20px 34px var(--accent-soft);
        }

        .ghost-btn {
            border: 1px solid rgba(91, 151, 213, 0.18);
            color: #2e4f6d;
            background: rgba(255, 255, 255, 0.82);
            box-shadow: 0 12px 24px rgba(101, 140, 179, 0.11);
        }

        .run-btn:hover:not(:disabled),
        .ghost-btn:hover {
            transform: translateY(-2px);
            filter: brightness(1.03);
        }

        .run-btn:disabled {
            opacity: 0.68;
            cursor: wait;
            transform: none;
        }

        .output-panel {
            margin-top: 24px;
            padding: 24px;
        }

        .output-head {
            align-items: flex-start;
        }

        .output-title h2 {
            margin: 0;
            font-size: 1.6rem;
            letter-spacing: -0.03em;
        }

        .status-chip[data-state="idle"] { color: var(--status-idle); }
        .status-chip[data-state="running"] { color: var(--status-running); }
        .status-chip[data-state="success"] { color: var(--status-success); }
        .status-chip[data-state="error"] { color: var(--status-error); }

        .output-toolbar {
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .terminal-output {
            min-height: 480px;
            max-height: 880px;
            overflow: auto;
            padding: 22px;
            font-size: 0.95rem;
            line-height: 1.72;
        }

        .terminal-output.is-placeholder {
            color: rgba(219, 238, 255, 0.54);
        }

        .terminal-output::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }

        .terminal-output::-webkit-scrollbar-thumb {
            border-radius: 999px;
            border: 2px solid transparent;
            background: rgba(140, 182, 222, 0.28);
            background-clip: padding-box;
        }

        .footer-note {
            font-size: 0.9rem;
        }

        .command-grid {
            align-items: stretch;
        }

        .command-card {
            display: flex;
            flex-direction: column;
            min-height: 100%;
        }

        .command-card > * {
            position: relative;
            z-index: 1;
        }

        .card-main {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
        }

        .card-copy {
            display: grid;
            align-content: start;
        }

        .card-tags {
            justify-content: flex-end;
            gap: 10px;
        }

        .feature-list {
            display: grid;
            gap: 12px;
            margin-top: 16px;
        }

        .card-input-stack {
            display: grid;
            gap: 12px;
            margin-top: 0;
        }

        .card-input-wrap {
            display: grid;
            gap: 8px;
        }

        .card-input-label {
            font-size: 0.88rem;
            font-weight: 800;
            color: #40607a;
            letter-spacing: 0.01em;
        }

        .card-path-input {
            width: 100%;
            min-height: 56px;
            padding: 0 18px;
            border-radius: 16px;
            border: 1px solid rgba(91, 151, 213, 0.18);
            background: rgba(255, 255, 255, 0.9);
            color: var(--ink-900);
            font-family: "Cascadia Code", "Consolas", monospace;
            font-size: 0.92rem;
            box-shadow: 0 12px 24px rgba(101, 140, 179, 0.11);
            transition: border-color 180ms ease, box-shadow 180ms ease, transform 180ms ease;
        }

        .card-path-input:focus {
            outline: none;
            border-color: rgba(82, 145, 212, 0.44);
            box-shadow: 0 0 0 5px rgba(93, 156, 219, 0.14);
            transform: translateY(-1px);
        }

        .card-path-input::placeholder {
            color: #8aa1b8;
        }

        .card-command-zone {
            display: grid;
            gap: 16px;
            margin-top: auto;
            align-content: end;
        }

        .card-command-zone.is-buttons {
            min-height: 236px;
        }

        .card-command-zone.is-input {
            min-height: 332px;
        }

        .code-shell {
            margin-top: 0;
        }

        .code-shell pre {
            height: 136px;
            overflow: auto;
        }

        .card-actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 0;
        }

        .run-btn,
        .ghost-btn {
            width: 100%;
            min-height: 54px;
        }

        .ghost-btn:disabled {
            opacity: 0.68;
            cursor: wait;
            transform: none;
        }

        .card-runtime {
            display: grid;
            grid-template-rows: 0fr;
            margin-top: 0;
            transition: grid-template-rows 220ms ease, margin-top 220ms ease;
        }

        .command-card.runtime-open .card-runtime {
            grid-template-rows: 1fr;
            margin-top: 18px;
        }

        .card-runtime-inner {
            min-height: 0;
            overflow: hidden;
        }

        .runtime-panel {
            padding: 20px;
            border-radius: 22px;
            border: 1px solid rgba(90, 145, 203, 0.16);
            background: linear-gradient(180deg, rgba(251, 254, 255, 0.96), rgba(241, 248, 255, 0.9));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72);
        }

        .runtime-head {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: start;
        }

        .runtime-title h3 {
            margin: 0;
            font-size: 1.26rem;
            letter-spacing: -0.02em;
        }

        .runtime-title p,
        .runtime-note {
            margin: 12px 0 0;
            color: var(--ink-700);
            line-height: 1.72;
            font-size: 0.96rem;
        }

        .runtime-meta,
        .runtime-context {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .runtime-meta {
            justify-content: flex-end;
        }

        .runtime-toolbar {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: center;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .runtime-action-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            width: min(360px, 100%);
        }

        .runtime-shell {
            margin-top: 18px;
            overflow: hidden;
            border: 1px solid rgba(88, 145, 204, 0.2);
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(14, 28, 44, 0.94), rgba(24, 42, 62, 0.95));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        .runtime-output {
            margin: 0;
            min-height: 320px;
            max-height: 780px;
            overflow: auto;
            padding: 20px;
            font-family: "Cascadia Code", "Consolas", monospace;
            font-size: 0.92rem;
            line-height: 1.72;
            white-space: pre-wrap;
            word-break: break-word;
            color: #ebf5ff;
        }

        .runtime-output.is-placeholder {
            color: rgba(219, 238, 255, 0.54);
        }

        .runtime-output::-webkit-scrollbar,
        .code-shell pre::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }

        .runtime-output::-webkit-scrollbar-thumb,
        .code-shell pre::-webkit-scrollbar-thumb {
            border-radius: 999px;
            border: 2px solid transparent;
            background: rgba(140, 182, 222, 0.28);
            background-clip: padding-box;
        }

        @keyframes drift {
            from { transform: translate3d(0, 0, 0) scale(1); }
            to { transform: translate3d(-18px, 20px, 0) scale(1.08); }
        }

        @keyframes rotateAura {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @media (max-width: 1120px) {
            .hero-grid,
            .command-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .shell {
                width: min(100vw - 14px, 1400px);
                padding: 16px 0 28px;
            }

            .hero,
            .command-card {
                padding: 20px;
            }

            .hero h1 {
                font-size: clamp(2rem, 11vw, 3.1rem);
            }

            .hero-actions,
            .card-actions,
            .runtime-meta,
            .runtime-context {
                flex-direction: column;
                align-items: stretch;
            }

            .card-top,
            .terminal-head,
            .code-head,
            .runtime-toolbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .card-actions,
            .runtime-action-grid {
                grid-template-columns: 1fr;
            }

            .runtime-head {
                grid-template-columns: 1fr;
            }

            .code-shell pre {
                height: auto;
                min-height: 136px;
            }

            .card-command-zone.is-buttons,
            .card-command-zone.is-input {
                min-height: 0;
            }

            .runtime-output {
                min-height: 280px;
                padding: 18px;
                font-size: 0.88rem;
            }
        }
    </style>
</head>
<body>
    <div class="mesh"></div>
    <main class="shell">
        <section class="hero">
            <span class="eyebrow">Preset Command Deck</span>
            <div class="hero-grid">
                <div>
                    <h1>一鍵執行的 <span>Blog 指令工具台</span></h1>
                    <p>
                        這個頁面把常用的 Telegram token 掃描、backlog 補跑、影片重複比對流程整理成固定按鈕。
                        每張卡都附上用途說明與實際會跑的命令，按一下就直接在 <code>C:\www\blog</code> 執行，結果會在該卡片下方展開。
                    </p>
                    <div class="hero-actions">
                        <div class="action-pill">白名單模式，只允許固定 4 組流程</div>
                        <div class="action-pill">固定工作目錄：<code>C:\www\blog</code></div>
                        <div class="action-pill">執行結果會跟著該卡片一起展開</div>
                    </div>
                </div>
                <div class="hero-stack">
                    <article class="meta-card">
                        <div class="meta-label">版面調整</div>
                        <div class="meta-value">按鈕等寬、指令區等高、卡片整體等高</div>
                        <div class="meta-sub">同一列的卡片現在會維持更穩定的基線，指令區塊和按鈕會固定對齊，不會一高一低。</div>
                    </article>
                    <article class="meta-card">
                        <div class="meta-label">結果顯示方式</div>
                        <div class="meta-value">點哪張卡，就在那張卡的下面打開輸出區</div>
                        <div class="meta-sub">不再固定塞在頁面底部。可以直接對照該組 preset 的說明、命令內容和執行結果。</div>
                    </article>
                </div>
            </div>
        </section>

        <section class="command-grid">
            @foreach ($presets as $preset)
                <article
                    class="command-card"
                    data-card="{{ $preset['id'] }}"
                    style="--accent-from: {{ $preset['accent_from'] }}; --accent-to: {{ $preset['accent_to'] }}; --accent-soft: {{ $preset['accent_soft'] }};"
                >
                    <div class="card-main">
                        <div class="card-top">
                            <span class="card-eyebrow">{{ $preset['eyebrow'] }}</span>
                            <div class="card-tags">
                                @foreach ($preset['tags'] as $tag)
                                    <span class="card-tag">{{ $tag }}</span>
                                @endforeach
                            </div>
                        </div>

                        <div class="card-copy">
                            <h2 class="card-title">{{ $preset['title'] }}</h2>
                            <p class="card-summary">{{ $preset['summary'] }}</p>
                            <p class="card-details">{{ $preset['details'] }}</p>

                            <div class="feature-list">
                                @foreach ($preset['highlights'] as $highlight)
                                    <div class="feature-item">{{ $highlight }}</div>
                                @endforeach
                            </div>
                        </div>

                        <div class="card-command-zone {{ !empty($preset['path_input']) ? 'is-input' : 'is-buttons' }}">
                            <div class="code-shell">
                                <div class="code-head">
                                    <div class="code-head-dots">
                                        <span></span>
                                        <span></span>
                                        <span></span>
                                    </div>
                                    <span>Preset Command</span>
                                </div>
                                <pre
                                    data-command-preview
                                    data-command-preview-template="{{ isset($preset['command_preview_template']) ? base64_encode($preset['command_preview_template']) : '' }}"
                                >{{ $preset['command_preview'] }}</pre>
                            </div>

                            @if (!empty($preset['path_input']))
                                <div class="card-input-stack">
                                    <label class="card-input-wrap">
                                        <span class="card-input-label">{{ $preset['path_input']['label'] }}</span>
                                        <input
                                            type="text"
                                            class="card-path-input"
                                            data-path-input
                                            data-path-input-name="{{ $preset['path_input']['name'] }}"
                                            value="{{ $preset['path_input']['value'] ?? $preset['path_input']['default'] ?? '' }}"
                                            placeholder="{{ $preset['path_input']['placeholder'] ?? '' }}"
                                            spellcheck="false"
                                            autocomplete="off"
                                        >
                                    </label>
                                    <button
                                        type="button"
                                        class="run-btn"
                                        data-run-preset="{{ $preset['id'] }}"
                                        data-preset-title="{{ $preset['title'] }}"
                                    >
                                        輸入後按 Enter 或點這裡執行
                                    </button>
                                </div>
                            @else
                                <div class="card-actions">
                                @if (!empty($preset['button_variants']))
                                    @foreach ($preset['button_variants'] as $variant)
                                        <button
                                            type="button"
                                            class="run-btn"
                                            data-run-preset="{{ $variant['preset'] }}"
                                            data-preset-title="{{ $variant['title'] }}"
                                        >
                                            {{ $variant['label'] }}
                                        </button>
                                    @endforeach
                                @else
                                    <button
                                        type="button"
                                        class="run-btn"
                                        data-run-preset="{{ $preset['id'] }}"
                                        data-preset-title="{{ $preset['title'] }}"
                                    >
                                        執行這組指令
                                    </button>
                                    <button
                                        type="button"
                                        class="ghost-btn"
                                        data-copy-preview="{{ $preset['id'] }}"
                                    >
                                        複製指令
                                    </button>
                                @endif
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="card-runtime" data-runtime>
                        <div class="card-runtime-inner">
                            <section class="runtime-panel">
                                <div class="runtime-head">
                                    <div class="runtime-title">
                                        <h3>執行結果輸出區</h3>
                                        <p>這裡只顯示這張卡片的執行結果，方便直接對照這組 preset 的說明、命令和輸出。</p>
                                    </div>
                                    <div class="runtime-meta">
                                        <div class="status-chip" data-run-status data-state="idle">待命中</div>
                                        <div class="meta-pill" data-run-exit>exit: -</div>
                                        <div class="meta-pill" data-run-duration>duration: -</div>
                                    </div>
                                </div>

                                <div class="runtime-toolbar">
                                    <div class="runtime-context">
                                        <div class="meta-pill" data-run-title>尚未執行</div>
                                        <div class="meta-pill" data-run-finished>finished: -</div>
                                    </div>
                                    <div class="runtime-action-grid">
                                        <button type="button" class="ghost-btn" data-copy-output>複製輸出</button>
                                        <button type="button" class="ghost-btn" data-close-output>收起結果</button>
                                    </div>
                                </div>

                                <div class="runtime-shell">
                                    <div class="terminal-head">
                                        <span>Runtime Console</span>
                                        <span data-terminal-caption>Waiting for this preset</span>
                                    </div>
                                    <pre class="runtime-output is-placeholder" data-runtime-output>按下這張卡的「執行這組指令」後，結果會直接展開在這裡。</pre>
                                </div>

                                <div class="runtime-note">
                                    這個輸出區只跟目前這張卡片有關，不會混到其他 preset 的結果。
                                </div>
                            </section>
                        </div>
                    </div>
                </article>
            @endforeach
        </section>
    </main>

    <script>
        (() => {
            const runStreamUrl = @json(route('command-runner.stream'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const cards = [...document.querySelectorAll('[data-card]')];
            const runButtons = [...document.querySelectorAll('[data-run-preset]')];
            const previewButtons = [...document.querySelectorAll('[data-copy-preview]')];
            const pathInputs = [...document.querySelectorAll('[data-path-input]')];
            const copyOutputButtons = [...document.querySelectorAll('[data-copy-output]')];
            const closeOutputButtons = [...document.querySelectorAll('[data-close-output]')];

            let isRunning = false;
            let activeCardId = null;

            const runtimeElements = (card) => ({
                status: card.querySelector('[data-run-status]'),
                exit: card.querySelector('[data-run-exit]'),
                duration: card.querySelector('[data-run-duration]'),
                title: card.querySelector('[data-run-title]'),
                finished: card.querySelector('[data-run-finished]'),
                caption: card.querySelector('[data-terminal-caption]'),
                output: card.querySelector('[data-runtime-output]'),
            });

            const setRuntimeMeta = (card, state) => {
                const elements = runtimeElements(card);

                if (state.statusState !== undefined) {
                    elements.status.dataset.state = state.statusState;
                }

                if (state.statusText !== undefined) {
                    elements.status.textContent = state.statusText;
                }

                if (state.exitText !== undefined) {
                    elements.exit.textContent = state.exitText;
                }

                if (state.durationText !== undefined) {
                    elements.duration.textContent = state.durationText;
                }

                if (state.titleText !== undefined) {
                    elements.title.textContent = state.titleText;
                }

                if (state.finishedText !== undefined) {
                    elements.finished.textContent = state.finishedText;
                }

                if (state.captionText !== undefined) {
                    elements.caption.textContent = state.captionText;
                }
            };

            const setRuntimeOutput = (card, text, placeholder = false) => {
                const elements = runtimeElements(card);
                elements.output.textContent = text;
                elements.output.classList.toggle('is-placeholder', placeholder);
            };

            const appendRuntimeOutput = (card, text) => {
                const elements = runtimeElements(card);

                if (elements.output.classList.contains('is-placeholder')) {
                    elements.output.textContent = '';
                    elements.output.classList.remove('is-placeholder');
                }

                elements.output.textContent += text;
                elements.output.scrollTop = elements.output.scrollHeight;
            };

            const setRuntimeState = (card, state) => {
                setRuntimeMeta(card, state);
                setRuntimeOutput(card, state.outputText, Boolean(state.placeholder));
            };

            const resetRuntime = (card) => {
                setRuntimeState(card, {
                    statusState: 'idle',
                    statusText: '待命中',
                    exitText: 'exit: -',
                    durationText: 'duration: -',
                    titleText: '尚未執行',
                    finishedText: 'finished: -',
                    captionText: 'Waiting for this preset',
                    outputText: '按下這張卡的「執行這組指令」後，結果會直接展開在這裡。',
                    placeholder: true,
                });
            };

            const setRunButtonsDisabled = (disabled) => {
                runButtons.forEach((button) => {
                    button.disabled = disabled;
                });
            };

            const openRuntime = (card) => {
                cards.forEach((item) => {
                    const isTarget = item === card;
                    item.classList.toggle('runtime-open', isTarget);
                    item.classList.toggle('is-active', isTarget);
                });

                activeCardId = card.dataset.card || null;
            };

            const closeRuntime = (card) => {
                if (isRunning && card.dataset.card === activeCardId) {
                    return;
                }

                card.classList.remove('runtime-open', 'is-active');

                if (card.dataset.card === activeCardId) {
                    activeCardId = null;
                }
            };

            const copyText = async (text) => {
                if (!text.trim()) {
                    return false;
                }

                try {
                    await navigator.clipboard.writeText(text);
                    return true;
                } catch (error) {
                    return false;
                }
            };

            const parseSseEvent = (rawEvent) => {
                const lines = rawEvent.split('\n');
                let event = 'message';
                const dataLines = [];

                lines.forEach((line) => {
                    if (line.startsWith('event:')) {
                        event = line.slice(6).trim();
                        return;
                    }

                    if (line.startsWith('data:')) {
                        dataLines.push(line.slice(5).trimStart());
                    }
                });

                return {
                    event,
                    data: dataLines.length > 0 ? JSON.parse(dataLines.join('\n')) : {},
                };
            };

            const readStreamResponse = async (response, onEvent) => {
                if (!response.body) {
                    throw new Error('瀏覽器沒有拿到可讀取的串流回應。');
                }

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    buffer += decoder.decode(value || new Uint8Array(), { stream: !done }).replace(/\r\n/g, '\n');

                    let boundary = buffer.indexOf('\n\n');
                    while (boundary !== -1) {
                        const rawEvent = buffer.slice(0, boundary).trim();
                        buffer = buffer.slice(boundary + 2);

                        if (rawEvent !== '') {
                            onEvent(parseSseEvent(rawEvent));
                        }

                        boundary = buffer.indexOf('\n\n');
                    }

                    if (done) {
                        break;
                    }
                }

                const tail = buffer.trim();
                if (tail !== '') {
                    onEvent(parseSseEvent(tail));
                }
            };

            cards.forEach((card) => {
                resetRuntime(card);
            });

            previewButtons.forEach((button) => {
                button.addEventListener('click', async () => {
                    const card = button.closest('[data-card]');
                    const preview = card?.querySelector('[data-command-preview]')?.textContent || '';
                    await copyText(preview);
                });
            });

            const updatePreviewFromInput = (input) => {
                const card = input.closest('[data-card]');
                const preview = card?.querySelector('[data-command-preview]');
                const templateEncoded = preview?.dataset.commandPreviewTemplate || '';

                if (!preview || !templateEncoded) {
                    return;
                }

                const template = atob(templateEncoded);
                const value = input.value.trim() || input.placeholder || '';
                preview.textContent = template.replaceAll('__PATH__', value);
            };

            pathInputs.forEach((input) => {
                updatePreviewFromInput(input);

                input.addEventListener('input', () => {
                    updatePreviewFromInput(input);
                });

                input.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter') {
                        return;
                    }

                    event.preventDefault();

                    const card = input.closest('[data-card]');
                    const runButton = card?.querySelector('[data-run-preset]');
                    runButton?.click();
                });
            });

            copyOutputButtons.forEach((button) => {
                button.addEventListener('click', async () => {
                    const card = button.closest('[data-card]');
                    const output = card?.querySelector('[data-runtime-output]')?.textContent || '';
                    await copyText(output);
                });
            });

            closeOutputButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const card = button.closest('[data-card]');
                    if (!card) {
                        return;
                    }

                    closeRuntime(card);
                });
            });

            runButtons.forEach((button) => {
                button.addEventListener('click', async () => {
                    if (isRunning) {
                        return;
                    }

                    const preset = button.dataset.runPreset;
                    const title = button.dataset.presetTitle || preset || 'Preset command';
                    const card = button.closest('[data-card]');

                    if (!preset || !card) {
                        return;
                    }

                    isRunning = true;
                    setRunButtonsDisabled(true);
                    openRuntime(card);
                    setRuntimeState(card, {
                        statusState: 'running',
                        statusText: '執行中',
                        exitText: 'exit: ...',
                        durationText: 'duration: running',
                        titleText: title,
                        finishedText: 'finished: running',
                        captionText: 'Streaming live output',
                        outputText: '',
                        placeholder: false,
                    });

                    requestAnimationFrame(() => {
                        card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    });

                    try {
                        const payload = { preset };
                        const pathInput = card.querySelector('[data-path-input]');

                        if (pathInput) {
                            payload[pathInput.dataset.pathInputName || 'path'] = pathInput.value;
                        }

                        const response = await fetch(runStreamUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'text/event-stream',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify(payload),
                        });

                        if (!response.ok) {
                            const contentType = response.headers.get('content-type') || '';
                            let message = '指令執行失敗。';

                            if (contentType.includes('application/json')) {
                                const data = await response.json();
                                message = data.message || message;
                            } else {
                                const text = await response.text();
                                if (text.trim() !== '') {
                                    message = text;
                                }
                            }

                            throw new Error(message);
                        }

                        let completionReceived = false;

                        await readStreamResponse(response, ({ event, data }) => {
                            if (event === 'chunk') {
                                appendRuntimeOutput(card, data.text || '');
                                return;
                            }

                            if (event === 'complete') {
                                completionReceived = true;
                                setRuntimeMeta(card, {
                                    statusState: data.success ? 'success' : 'error',
                                    statusText: data.success ? '執行完成' : '執行失敗',
                                    exitText: `exit: ${data.exit_code}`,
                                    durationText: `duration: ${data.duration_ms} ms`,
                                    titleText: data.preset?.title || title,
                                    finishedText: `finished: ${data.finished_at || '-'}`,
                                    captionText: data.success ? 'Preset finished successfully' : 'Preset finished with errors',
                                });
                                return;
                            }

                            if (event === 'error') {
                                completionReceived = true;
                                appendRuntimeOutput(card, `\n[stream error] ${data.message || '未知錯誤'}\n`);
                                setRuntimeMeta(card, {
                                    statusState: 'error',
                                    statusText: '執行失敗',
                                    exitText: 'exit: stream-error',
                                    durationText: 'duration: -',
                                    titleText: title,
                                    finishedText: 'finished: stream-error',
                                    captionText: 'Stream finished with errors',
                                });
                            }
                        });

                        if (!completionReceived) {
                            appendRuntimeOutput(card, '\n[warning] 後端串流已結束，但前端沒有收到完成事件。\n');
                            setRuntimeMeta(card, {
                                statusState: 'error',
                                statusText: '未收到完成事件',
                                exitText: 'exit: unknown',
                                durationText: 'duration: unknown',
                                titleText: title,
                                finishedText: 'finished: missing-complete',
                                captionText: 'Stream ended without completion metadata',
                            });
                        }
                    } catch (error) {
                        const message = error instanceof Error ? error.message : '未知錯誤';

                        setRuntimeState(card, {
                            statusState: 'error',
                            statusText: '執行失敗',
                            exitText: 'exit: request-error',
                            durationText: 'duration: -',
                            titleText: title,
                            finishedText: 'finished: request-error',
                            captionText: 'Request failed before command completed',
                            outputText: `無法完成這次執行。\n\n${message}`,
                            placeholder: false,
                        });
                    } finally {
                        isRunning = false;
                        setRunButtonsDisabled(false);
                    }
                });
            });
        })();
    </script>
</body>
</html>
