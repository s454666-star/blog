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
            --page-bg: #fff8ff;
            --page-bg-2: #fff1ff;
            --panel: rgba(255, 255, 255, 0.82);
            --line: rgba(213, 164, 233, 0.22);
            --ink-900: #422d52;
            --ink-700: #735d87;
            --ink-500: #9c88b0;
            --status-idle: #9b86b1;
            --status-running: #be72dc;
            --status-success: #9c5fbe;
            --status-stopped: #b26f96;
            --status-error: #c44747;
            --shadow-xl: 0 32px 80px rgba(186, 140, 214, 0.18);
            --shadow-lg: 0 22px 50px rgba(200, 154, 223, 0.16);
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
                radial-gradient(circle at 0% 10%, rgba(255, 191, 255, 0.26), transparent 36%),
                radial-gradient(circle at 100% 0%, rgba(255, 208, 255, 0.22), transparent 30%),
                radial-gradient(circle at 50% 100%, rgba(230, 210, 255, 0.18), transparent 34%),
                linear-gradient(140deg, var(--page-bg) 0%, #fffbff 42%, var(--page-bg-2) 100%);
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
            background: radial-gradient(circle, rgba(214, 142, 244, 0.34), rgba(214, 142, 244, 0));
        }

        body::after {
            bottom: -10rem;
            left: -8rem;
            background: radial-gradient(circle, rgba(255, 191, 255, 0.28), rgba(255, 191, 255, 0));
            animation-duration: 24s;
        }

        .mesh {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            background-image:
                linear-gradient(rgba(214, 176, 232, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(214, 176, 232, 0.08) 1px, transparent 1px);
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
            background: conic-gradient(from 160deg, rgba(214, 132, 244, 0.14), rgba(255, 191, 255, 0.11), rgba(255, 208, 255, 0.12), rgba(214, 132, 244, 0.14));
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
            border: 1px solid rgba(206, 156, 233, 0.28);
            background: rgba(255, 250, 255, 0.92);
            color: #9b5cb9;
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
            background: linear-gradient(110deg, #c678e2 0%, #ffbfff 48%, #ffd0ff 100%);
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
            border: 1px solid rgba(206, 156, 233, 0.18);
            background: rgba(255, 249, 255, 0.92);
            color: #8b63a8;
            font-size: 0.9rem;
            font-weight: 800;
        }

        .action-pill,
        .chip {
            min-height: 44px;
            border-radius: 16px;
            box-shadow: 0 14px 28px rgba(204, 164, 226, 0.16);
        }

        .meta-card {
            padding: 20px;
            border-radius: 22px;
            border: 1px solid rgba(212, 170, 233, 0.2);
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.97), rgba(255, 244, 255, 0.92));
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
            gap: 20px;
            margin-top: 24px;
        }

        .command-row {
            display: grid;
            gap: 20px;
        }

        .command-row-cards {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
            align-items: stretch;
        }

        .command-card {
            --accent-from: #2f8bff;
            --accent-to: #35d4c6;
            --accent-soft: rgba(47, 139, 255, 0.16);
            position: relative;
            padding: 22px;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(213, 173, 233, 0.24);
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.97), rgba(255, 245, 255, 0.88));
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
            border-color: rgba(197, 132, 228, 0.34);
            box-shadow: 0 28px 60px rgba(188, 139, 216, 0.24);
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
            color: #8451a8;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .card-tag {
            min-height: 28px;
            padding: 0 10px;
            color: #825c9f;
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
            color: #755d89;
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
            border: 1px solid rgba(194, 153, 224, 0.18);
            background: linear-gradient(180deg, rgba(55, 32, 82, 0.96), rgba(82, 50, 112, 0.96));
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

        .code-head-meta,
        .terminal-head-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex-wrap: wrap;
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

        .shell-copy-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: #ebf5ff;
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: none;
            cursor: pointer;
            transition: transform 180ms ease, background-color 180ms ease, border-color 180ms ease;
            flex: 0 0 auto;
        }

        .shell-copy-btn:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(255, 255, 255, 0.2);
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
            border: 1px solid rgba(208, 165, 233, 0.2);
            color: #744f94;
            background: rgba(255, 249, 255, 0.92);
            box-shadow: 0 12px 24px rgba(200, 164, 226, 0.16);
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
        .status-chip[data-state="stopped"] { color: var(--status-stopped); }
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

        .card-input-stack.is-inline {
            grid-template-columns: minmax(0, 1fr) minmax(260px, 320px);
            align-items: end;
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
            border: 1px solid rgba(210, 170, 233, 0.2);
            background: rgba(255, 251, 255, 0.95);
            color: var(--ink-900);
            font-family: "Cascadia Code", "Consolas", monospace;
            font-size: 0.92rem;
            box-shadow: 0 12px 24px rgba(197, 164, 224, 0.15);
            transition: border-color 180ms ease, box-shadow 180ms ease, transform 180ms ease;
        }

        .card-path-input:focus {
            outline: none;
            border-color: rgba(201, 133, 228, 0.46);
            box-shadow: 0 0 0 5px rgba(222, 184, 241, 0.22);
            transform: translateY(-1px);
        }

        .card-path-input::placeholder {
            color: #9e91bc;
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
            min-height: 396px;
        }

        .card-command-zone.is-input-inline {
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

        .card-input-stack.is-inline .run-btn {
            min-height: 56px;
        }

        .ghost-btn:disabled {
            opacity: 0.62;
            cursor: not-allowed;
            transform: none;
        }

        .stop-btn {
            border-color: rgba(207, 137, 188, 0.24);
            color: #9a4d81;
            background: linear-gradient(135deg, rgba(255, 245, 252, 0.98), rgba(255, 228, 245, 0.94));
        }

        .row-runtime {
            display: grid;
            grid-template-rows: 0fr;
            margin-top: 0;
            transition: grid-template-rows 220ms ease, margin-top 220ms ease;
        }

        .command-row.runtime-open .row-runtime {
            grid-template-rows: 1fr;
            margin-top: 18px;
        }

        .row-runtime-inner {
            min-height: 0;
            overflow: hidden;
        }

        .runtime-panel {
            padding: 20px;
            border-radius: 22px;
            border: 1px solid rgba(213, 172, 233, 0.2);
            background: linear-gradient(180deg, rgba(255, 251, 255, 0.98), rgba(255, 242, 255, 0.95));
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
            border: 1px solid rgba(213, 172, 233, 0.22);
            border-radius: 22px;
            background: linear-gradient(180deg, rgba(55, 32, 82, 0.97), rgba(80, 49, 110, 0.97));
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
            background: rgba(176, 153, 224, 0.32);
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
            .hero-grid {
                grid-template-columns: 1fr;
            }

            .command-row-cards {
                grid-template-columns: 1fr;
            }

            .card-input-stack.is-inline {
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
                        每張卡都附上用途說明與實際會跑的命令，按一下就直接在 <code>C:\www\blog</code> 執行，結果會在該排卡片下方另外展開成一張大卡。
                    </p>
                    <div class="hero-actions">
                        <div class="action-pill">白名單模式，只允許固定 5 組流程</div>
                        <div class="action-pill">固定工作目錄：<code>C:\www\blog</code></div>
                        <div class="action-pill">執行結果會掛在該排卡片下方的大結果卡，可中途按「停止」</div>
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
                        <div class="meta-value">點哪張卡，就在該排下方打開一張共用結果卡</div>
                        <div class="meta-sub">上面兩張卡保持對稱，輸出區改成獨立大卡。你還是可以直接對照目前正在跑的那組 preset。</div>
                    </article>
                </div>
            </div>
        </section>

        <section class="command-grid">
            @foreach (array_chunk($presets, 2, true) as $rowIndex => $rowPresets)
                <section class="command-row" data-row="{{ $rowIndex }}">
                    <div class="command-row-cards">
                    @foreach ($rowPresets as $presetIndex => $preset)
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

                                <div class="card-command-zone {{ !empty($preset['inputs']) ? 'is-input' : 'is-buttons' }} {{ !empty($preset['inputs']) && count($preset['inputs']) === 1 ? 'is-input-inline' : '' }}">
                                    <div class="code-shell">
                                        <div class="code-head">
                                            <div class="code-head-meta">
                                                <div class="code-head-dots">
                                                    <span></span>
                                                    <span></span>
                                                    <span></span>
                                                </div>
                                                <span>Preset Command</span>
                                            </div>
                                            <button
                                                type="button"
                                                class="shell-copy-btn"
                                                data-copy-preview="{{ $preset['id'] }}"
                                                aria-label="複製指令"
                                            >
                                                複製
                                            </button>
                                        </div>
                                        <pre
                                            data-command-preview
                                            data-command-preview-template="{{ isset($preset['command_preview_template']) ? base64_encode($preset['command_preview_template']) : '' }}"
                                        >{{ $preset['command_preview'] }}</pre>
                                    </div>

                                    @if (!empty($preset['inputs']))
                                        <div class="card-input-stack {{ count($preset['inputs']) === 1 ? 'is-inline' : '' }}">
                                            @foreach ($preset['inputs'] as $inputField)
                                                <label class="card-input-wrap">
                                                    <span class="card-input-label">{{ $inputField['label'] }}</span>
                                                    <input
                                                        type="text"
                                                        class="card-path-input"
                                                        data-preset-input
                                                        data-preset-input-name="{{ $inputField['name'] }}"
                                                        data-preview-token="{{ $inputField['preview_token'] ?? '' }}"
                                                        value="{{ $inputField['value'] ?? $inputField['default'] ?? '' }}"
                                                        placeholder="{{ $inputField['placeholder'] ?? '' }}"
                                                        spellcheck="false"
                                                        autocomplete="off"
                                                    >
                                                </label>
                                            @endforeach
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
                                        @if (!empty($preset['button_variants']))
                                            <div class="card-actions">
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
                                            </div>
                                        @else
                                            <button
                                                type="button"
                                                class="run-btn"
                                                data-run-preset="{{ $preset['id'] }}"
                                                data-preset-title="{{ $preset['title'] }}"
                                            >
                                                執行這組指令
                                            </button>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        </article>
                    @endforeach
                    </div>

                    <div class="row-runtime" data-runtime-row>
                        <div class="row-runtime-inner">
                            <section class="runtime-panel">
                                <div class="runtime-head">
                                    <div class="runtime-title">
                                        <h3>執行結果輸出區</h3>
                                        <p>這裡只顯示這一排目前被點擊卡片的執行結果，方便直接對照 preset 說明、命令和輸出。</p>
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
                                        <button type="button" class="ghost-btn stop-btn" data-stop-run disabled>停止</button>
                                        <button type="button" class="ghost-btn" data-close-output>收起結果</button>
                                    </div>
                                </div>

                                <div class="runtime-shell">
                                    <div class="terminal-head">
                                        <div class="terminal-head-meta">
                                            <span>Runtime Console</span>
                                            <span data-terminal-caption>Waiting for this preset</span>
                                        </div>
                                        <button type="button" class="shell-copy-btn" data-copy-output aria-label="複製輸出">複製</button>
                                    </div>
                                    <pre class="runtime-output is-placeholder" data-runtime-output>按下這一排卡片的「執行這組指令」後，結果會展開在這裡。</pre>
                                </div>

                                <div class="runtime-note">
                                    這個輸出區是這一排共用的大卡片，但內容只會顯示目前正在跑的那一張 preset。
                                </div>
                            </section>
                        </div>
                    </div>
                </section>
            @endforeach
        </section>
    </main>

    <script>
        (() => {
            const runStreamUrl = @json(route('command-runner.stream'));
            const stopRunUrl = @json(route('command-runner.stop'));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const rows = [...document.querySelectorAll('[data-row]')];
            const cards = [...document.querySelectorAll('[data-card]')];
            const runButtons = [...document.querySelectorAll('[data-run-preset]')];
            const previewButtons = [...document.querySelectorAll('[data-copy-preview]')];
            const presetInputs = [...document.querySelectorAll('[data-preset-input]')];
            const stopButtons = [...document.querySelectorAll('[data-stop-run]')];
            const copyOutputButtons = [...document.querySelectorAll('[data-copy-output]')];
            const closeOutputButtons = [...document.querySelectorAll('[data-close-output]')];
            const copyFeedbackTimers = new WeakMap();

            let isRunning = false;
            let isStopping = false;
            let activeCardId = null;
            let activeRowId = null;
            let activeRunToken = null;

            const runtimeRowForCard = (card) => card.closest('[data-row]');

            const runtimeElements = (row) => ({
                status: row.querySelector('[data-run-status]'),
                exit: row.querySelector('[data-run-exit]'),
                duration: row.querySelector('[data-run-duration]'),
                title: row.querySelector('[data-run-title]'),
                finished: row.querySelector('[data-run-finished]'),
                caption: row.querySelector('[data-terminal-caption]'),
                output: row.querySelector('[data-runtime-output]'),
            });

            const setRuntimeMeta = (row, state) => {
                const elements = runtimeElements(row);

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

            const setRuntimeOutput = (row, text, placeholder = false) => {
                const elements = runtimeElements(row);
                elements.output.textContent = text;
                elements.output.classList.toggle('is-placeholder', placeholder);
            };

            const appendRuntimeOutput = (row, text) => {
                const elements = runtimeElements(row);

                if (elements.output.classList.contains('is-placeholder')) {
                    elements.output.textContent = '';
                    elements.output.classList.remove('is-placeholder');
                }

                elements.output.textContent += text;
                elements.output.scrollTop = elements.output.scrollHeight;
            };

            const setRuntimeState = (row, state) => {
                setRuntimeMeta(row, state);
                setRuntimeOutput(row, state.outputText, Boolean(state.placeholder));
            };

            const resetRuntime = (row) => {
                setRuntimeState(row, {
                    statusState: 'idle',
                    statusText: '待命中',
                    exitText: 'exit: -',
                    durationText: 'duration: -',
                    titleText: '尚未執行',
                    finishedText: 'finished: -',
                    captionText: 'Waiting for this preset',
                    outputText: '按下這一排卡片的「執行這組指令」後，結果會展開在這裡。',
                    placeholder: true,
                });
            };

            const setRunButtonsDisabled = (disabled) => {
                runButtons.forEach((button) => {
                    button.disabled = disabled;
                });
            };

            const setStopButtonsState = (runningRow = null, stopping = false) => {
                stopButtons.forEach((button) => {
                    const row = button.closest('[data-row]');
                    const isActiveRow = Boolean(runningRow) && row === runningRow;

                    button.disabled = !isActiveRow || !isRunning || stopping;
                    button.textContent = isActiveRow && stopping ? '停止中...' : '停止';
                });
            };

            const openRuntime = (card) => {
                const targetRow = runtimeRowForCard(card);

                rows.forEach((row) => {
                    row.classList.toggle('runtime-open', row === targetRow);
                });

                cards.forEach((item) => {
                    item.classList.toggle('is-active', item === card);
                });

                activeCardId = card.dataset.card || null;
                activeRowId = targetRow?.dataset.row || null;
            };

            const closeRuntime = (target) => {
                const row = target?.matches?.('[data-row]') ? target : target?.closest?.('[data-row]');

                if (!row) {
                    return;
                }

                if (isRunning && row.dataset.row === activeRowId) {
                    return;
                }

                row.classList.remove('runtime-open');
                cards.forEach((card) => {
                    if (runtimeRowForCard(card) === row) {
                        card.classList.remove('is-active');
                    }
                });

                if (row.dataset.row === activeRowId) {
                    activeCardId = null;
                    activeRowId = null;
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

            const showCopyFeedback = (button, nextLabel) => {
                if (!button) {
                    return;
                }

                const idleLabel = button.dataset.idleLabel || button.textContent.trim() || '複製';
                button.dataset.idleLabel = idleLabel;
                button.textContent = nextLabel;

                const activeTimer = copyFeedbackTimers.get(button);
                if (activeTimer) {
                    window.clearTimeout(activeTimer);
                }

                const timer = window.setTimeout(() => {
                    button.textContent = idleLabel;
                    copyFeedbackTimers.delete(button);
                }, 1400);

                copyFeedbackTimers.set(button, timer);
            };

            const createRunToken = () => {
                if (window.crypto?.randomUUID) {
                    return window.crypto.randomUUID();
                }

                return `runner-${Date.now()}-${Math.random().toString(16).slice(2)}`;
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

            rows.forEach((row) => {
                resetRuntime(row);
            });
            setStopButtonsState();

            previewButtons.forEach((button) => {
                button.addEventListener('click', async () => {
                    const card = button.closest('[data-card]');
                    const preview = card?.querySelector('[data-command-preview]')?.textContent || '';
                    const copied = await copyText(preview);
                    showCopyFeedback(button, copied ? '已複製' : preview.trim() ? '複製失敗' : '沒有內容');
                });
            });

            const updatePreviewFromInputs = (card) => {
                const preview = card?.querySelector('[data-command-preview]');
                const templateEncoded = preview?.dataset.commandPreviewTemplate || '';

                if (!preview || !templateEncoded) {
                    return;
                }

                let template = atob(templateEncoded);
                const inputs = [...card.querySelectorAll('[data-preset-input]')];

                inputs.forEach((input) => {
                    const token = input.dataset.previewToken || '';

                    if (!token) {
                        return;
                    }

                    const value = input.value.trim() || input.placeholder || '';
                    template = template.replaceAll(token, value);
                });

                preview.textContent = template;
            };

            presetInputs.forEach((input) => {
                const card = input.closest('[data-card]');
                if (card) {
                    updatePreviewFromInputs(card);
                }

                input.addEventListener('input', () => {
                    const currentCard = input.closest('[data-card]');
                    if (currentCard) {
                        updatePreviewFromInputs(currentCard);
                    }
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
                    const row = button.closest('[data-row]');
                    const outputNode = row?.querySelector('[data-runtime-output]');
                    const output = outputNode?.classList.contains('is-placeholder') ? '' : outputNode?.textContent || '';
                    const copied = await copyText(output);
                    showCopyFeedback(button, copied ? '已複製' : output.trim() ? '複製失敗' : '沒有內容');
                });
            });

            closeOutputButtons.forEach((button) => {
                button.addEventListener('click', () => {
                    const row = button.closest('[data-row]');
                    if (!row) {
                        return;
                    }

                    closeRuntime(row);
                });
            });

            stopButtons.forEach((button) => {
                button.addEventListener('click', async () => {
                    const row = button.closest('[data-row]');

                    if (!row || !isRunning || isStopping || row.dataset.row !== activeRowId || !activeRunToken) {
                        return;
                    }

                    isStopping = true;
                    setStopButtonsState(row, true);
                    appendRuntimeOutput(row, '\n[stop] 已送出停止請求，等待目前程序結束...\n');
                    setRuntimeMeta(row, {
                        statusState: 'running',
                        statusText: '停止中',
                        captionText: 'Stop requested, waiting for process to exit',
                    });

                    try {
                        const response = await fetch(stopRunUrl, {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken,
                            },
                            body: JSON.stringify({
                                run_token: activeRunToken,
                            }),
                        });
                        const contentType = response.headers.get('content-type') || '';
                        const data = contentType.includes('application/json') ? await response.json() : {};

                        if (!response.ok) {
                            throw new Error(data.message || '停止指令失敗。');
                        }

                        if (data.message) {
                            appendRuntimeOutput(row, `[stop] ${data.message}\n`);
                        }
                    } catch (error) {
                        const message = error instanceof Error ? error.message : '停止指令失敗。';
                        appendRuntimeOutput(row, `[stop error] ${message}\n`);
                        isStopping = false;
                        setStopButtonsState(row, false);
                    }
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
                    const row = card ? runtimeRowForCard(card) : null;

                    if (!preset || !card || !row) {
                        return;
                    }

                    const runToken = createRunToken();
                    isRunning = true;
                    isStopping = false;
                    activeRunToken = runToken;
                    setRunButtonsDisabled(true);
                    openRuntime(card);
                    setStopButtonsState(row, false);
                    setRuntimeState(row, {
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
                        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    });

                    try {
                        const payload = {
                            preset,
                            run_token: runToken,
                        };
                        const inputs = [...card.querySelectorAll('[data-preset-input]')];

                        inputs.forEach((input) => {
                            payload[input.dataset.presetInputName || 'path'] = input.value;
                        });

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
                                appendRuntimeOutput(row, data.text || '');
                                return;
                            }

                            if (event === 'complete') {
                                completionReceived = true;
                                setRuntimeMeta(row, {
                                    statusState: data.cancelled ? 'stopped' : (data.success ? 'success' : 'error'),
                                    statusText: data.cancelled ? '已停止' : (data.success ? '執行完成' : '執行失敗'),
                                    exitText: `exit: ${data.exit_code}`,
                                    durationText: `duration: ${data.duration_ms} ms`,
                                    titleText: data.preset?.title || title,
                                    finishedText: `finished: ${data.finished_at || '-'}`,
                                    captionText: data.cancelled
                                        ? 'Preset stopped by request'
                                        : (data.success ? 'Preset finished successfully' : 'Preset finished with errors'),
                                });
                                return;
                            }

                            if (event === 'error') {
                                completionReceived = true;
                                appendRuntimeOutput(row, `\n[stream error] ${data.message || '未知錯誤'}\n`);
                                setRuntimeMeta(row, {
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
                            appendRuntimeOutput(row, '\n[warning] 後端串流已結束，但前端沒有收到完成事件。\n');
                            setRuntimeMeta(row, {
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

                        setRuntimeState(row, {
                            statusState: isStopping ? 'stopped' : 'error',
                            statusText: isStopping ? '已停止' : '執行失敗',
                            exitText: isStopping ? 'exit: stop-requested' : 'exit: request-error',
                            durationText: 'duration: -',
                            titleText: title,
                            finishedText: isStopping ? 'finished: stop-requested' : 'finished: request-error',
                            captionText: isStopping ? 'Preset stopped while request stream closed' : 'Request failed before command completed',
                            outputText: isStopping ? `已送出停止要求，但串流在完成前中斷。\n\n${message}` : `無法完成這次執行。\n\n${message}`,
                            placeholder: false,
                        });
                    } finally {
                        isRunning = false;
                        isStopping = false;
                        activeRunToken = null;
                        setRunButtonsDisabled(false);
                        setStopButtonsState();
                    }
                });
            });
        })();
    </script>
</body>
</html>
