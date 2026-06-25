<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>玉山庫存即時看板</title>
    <style>
        :root {
            --bg: #101317;
            --panel: #20242b;
            --panel-soft: #2a3038;
            --panel-hard: #15191f;
            --line: #38404b;
            --line-soft: rgba(148, 163, 184, 0.18);
            --text: #f3f4f6;
            --muted: #9ca3af;
            --muted-2: #737373;
            --red: #ff3b5c;
            --green: #22c55e;
            --amber: #f59e0b;
            --cyan: #38bdf8;
            --teal: #14b8a6;
            --shadow: 0 18px 44px rgba(0, 0, 0, 0.28);
            --glow: 0 0 0 1px rgba(56, 189, 248, 0.1), 0 18px 60px rgba(20, 184, 166, 0.12);
        }

        * { box-sizing: border-box; }

        html,
        body {
            cursor: auto;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at 18% 12%, rgba(56, 189, 248, 0.2), transparent 28%),
                radial-gradient(circle at 80% 0%, rgba(245, 158, 11, 0.13), transparent 22%),
                linear-gradient(145deg, #071827 0%, #10152b 42%, #152033 100%);
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
            overflow-x: hidden;
            isolation: isolate;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -2;
            pointer-events: none;
            background-image:
                radial-gradient(circle, rgba(255, 255, 255, 0.95) 0 1px, transparent 1.8px),
                radial-gradient(circle, rgba(125, 211, 252, 0.62) 0 1px, transparent 2px),
                radial-gradient(circle, rgba(253, 230, 138, 0.52) 0 1.2px, transparent 2.3px),
                radial-gradient(circle, rgba(255, 255, 255, 0.34) 0 1px, transparent 2px);
            background-position: 0 0, 72px 48px, 24px 110px, 126px 86px;
            background-size: 150px 150px, 230px 230px, 310px 310px, 420px 420px;
            opacity: 0.58;
            mask-image: linear-gradient(to bottom, rgba(0, 0, 0, 0.92), rgba(0, 0, 0, 0.52) 72%, transparent);
            will-change: opacity, filter, background-position;
            animation:
                starDrift 58s linear infinite,
                starTwinkle 5.6s ease-in-out infinite;
        }

        .shell {
            position: relative;
            z-index: 1;
            width: min(1680px, calc(100vw - 28px));
            margin: 0 auto;
            padding: 18px 0 42px;
        }

        .meteor-field {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }

        .meteor {
            position: absolute;
            top: -14vh;
            left: var(--x);
            width: var(--size, 3px);
            height: var(--size, 3px);
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.98);
            box-shadow:
                0 0 9px rgba(255, 255, 255, 0.95),
                0 0 18px rgba(125, 211, 252, 0.72),
                0 0 30px rgba(20, 184, 166, 0.28);
            opacity: 0;
            transform: translate3d(0, 0, 0) rotate(132deg);
            animation: meteorFall var(--duration, 12s) linear infinite;
            animation-delay: var(--delay, 0s);
            will-change: opacity, transform;
        }

        .meteor::after {
            content: "";
            position: absolute;
            top: 50%;
            right: 1px;
            width: var(--tail, 150px);
            height: 1px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(255, 255, 255, 0.9), rgba(125, 211, 252, 0.36), transparent);
            transform: translateY(-50%);
            filter: blur(0.2px);
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 0 14px;
            border-bottom: 1px solid var(--line);
            position: relative;
        }

        .topbar::after {
            content: "";
            position: absolute;
            bottom: -1px;
            left: 0;
            width: min(420px, 48vw);
            height: 2px;
            background: linear-gradient(90deg, var(--teal), var(--cyan), var(--amber));
            box-shadow: 0 0 18px rgba(56, 189, 248, 0.35);
            animation: headerGlow 4.8s ease-in-out infinite;
        }

        .title-block {
            min-width: 0;
        }

        h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.18;
            font-weight: 900;
            text-shadow: 0 0 22px rgba(56, 189, 248, 0.16);
        }

        .subtitle {
            margin-top: 7px;
            color: var(--muted);
            font-size: 13px;
        }

        .status-bar {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 34px;
            padding: 0 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: rgba(32, 36, 43, 0.82);
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .pill::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: var(--muted-2);
            box-shadow: 0 0 0 3px rgba(115, 115, 115, 0.14);
        }

        .pill.live {
            color: var(--green);
            border-color: rgba(34, 197, 94, 0.45);
            background: rgba(34, 197, 94, 0.1);
        }

        .pill.live::before {
            background: var(--green);
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.14), 0 0 16px rgba(34, 197, 94, 0.5);
            animation: livePulse 1.8s ease-in-out infinite;
        }

        .pill.error {
            color: var(--red);
            border-color: rgba(255, 59, 92, 0.45);
            background: rgba(255, 59, 92, 0.1);
        }

        .pill.error::before {
            background: var(--red);
            box-shadow: 0 0 0 3px rgba(255, 59, 92, 0.14);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            margin: 16px 0 12px;
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            min-height: 116px;
            padding: 16px;
            border: 1px solid var(--line-soft);
            border-radius: 8px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.055), rgba(255, 255, 255, 0.015)),
                var(--panel);
            box-shadow: var(--shadow);
            transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
            animation: panelIn 360ms ease both;
        }

        .summary-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--teal), var(--cyan), var(--amber));
            opacity: 0.72;
        }

        .summary-card::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(110deg, transparent 0%, rgba(255, 255, 255, 0.08) 45%, transparent 62%);
            transform: translateX(-140%);
            transition: transform 600ms ease;
        }

        .summary-card:nth-child(2) { animation-delay: 40ms; }
        .summary-card:nth-child(3) { animation-delay: 80ms; }
        .summary-card:nth-child(4) { animation-delay: 120ms; }
        .summary-card:nth-child(5) { animation-delay: 160ms; }
        .summary-card:nth-child(6) { animation-delay: 200ms; }

        .summary-card:hover {
            transform: translateY(-2px);
            border-color: rgba(56, 189, 248, 0.34);
            box-shadow: var(--shadow), var(--glow);
        }

        .summary-card:hover::after {
            transform: translateX(140%);
        }

        .label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .value {
            margin-top: 9px;
            font-size: 30px;
            line-height: 1.08;
            font-weight: 900;
            font-variant-numeric: tabular-nums;
            transition: color 180ms ease, text-shadow 180ms ease;
        }

        .sub {
            margin-top: 9px;
            color: var(--muted-2);
            font-size: 12px;
            line-height: 1.45;
            font-variant-numeric: tabular-nums;
        }

        .return-rate-line {
            margin-top: 8px;
            color: var(--amber);
            font-size: 17px;
            font-weight: 900;
            line-height: 1.25;
            font-variant-numeric: tabular-nums;
            text-shadow: 0 0 16px rgba(245, 158, 11, 0.16);
        }

        .capital-card {
            background:
                radial-gradient(circle at 86% 12%, rgba(245, 158, 11, 0.12), transparent 36%),
                radial-gradient(circle at 10% 92%, rgba(20, 184, 166, 0.12), transparent 34%),
                linear-gradient(180deg, rgba(255, 255, 255, 0.06), rgba(255, 255, 255, 0.018)),
                var(--panel);
        }

        .investment-metrics {
            display: grid;
            gap: 7px;
            margin-top: 12px;
        }

        .investment-line {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 10px;
            min-height: 34px;
            padding: 7px 9px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.045);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .investment-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .investment-value {
            color: var(--amber);
            font-size: 20px;
            line-height: 1.05;
            font-weight: 950;
            text-align: right;
            font-variant-numeric: tabular-nums;
            text-shadow: 0 0 18px rgba(245, 158, 11, 0.18);
        }

        .investment-line.bank .investment-value {
            color: var(--cyan);
            font-size: 18px;
            text-shadow: 0 0 18px rgba(56, 189, 248, 0.18);
        }

        .positive { color: var(--red); text-shadow: 0 0 18px rgba(255, 59, 92, 0.16); }
        .negative { color: var(--green); text-shadow: 0 0 18px rgba(34, 197, 94, 0.14); }
        .neutral { color: var(--text); }
        .amber { color: var(--amber); }
        .cyan { color: var(--cyan); text-shadow: 0 0 18px rgba(56, 189, 248, 0.18); }
        .muted { color: var(--muted); }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            padding: 12px;
            border: 1px solid var(--line-soft);
            border-radius: 8px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.035), rgba(255, 255, 255, 0.01)), var(--panel-hard);
            box-shadow: 0 12px 34px rgba(0, 0, 0, 0.22);
        }

        .refresh-actions,
        .controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .button,
        input {
            min-height: 36px;
            border: 1px solid var(--line-soft);
            border-radius: 8px;
            background: var(--panel);
            color: var(--text);
            padding: 0 12px;
            font: inherit;
            font-size: 13px;
            font-weight: 800;
        }

        .button {
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            overflow: hidden;
            min-width: 118px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22), inset 0 1px 0 rgba(255, 255, 255, 0.06);
            cursor: pointer;
            transition: transform 160ms ease, border-color 160ms ease, box-shadow 160ms ease, color 160ms ease;
        }

        .button::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: var(--cyan);
            box-shadow: 0 0 14px rgba(56, 189, 248, 0.6);
            flex: 0 0 auto;
        }

        .button::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(110deg, transparent, rgba(255, 255, 255, 0.14), transparent);
            transform: translateX(-120%);
            transition: transform 520ms ease;
        }

        .button:hover {
            border-color: rgba(56, 189, 248, 0.58);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.28), 0 0 24px rgba(56, 189, 248, 0.14);
            transform: translateY(-1px);
        }

        .button:hover::after {
            transform: translateX(120%);
        }

        .button:active {
            transform: translateY(0);
        }

        .button.primary {
            border-color: rgba(34, 197, 94, 0.48);
            background: linear-gradient(135deg, #064e3b, #0f766e 55%, #155e75);
            color: #eafff7;
        }

        .button.primary::before {
            background: #4ade80;
            box-shadow: 0 0 16px rgba(74, 222, 128, 0.76);
        }

        .button.secondary {
            border-color: rgba(56, 189, 248, 0.34);
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.15), rgba(148, 163, 184, 0.08));
        }

        .button:disabled {
            cursor: not-allowed;
            opacity: 0.62;
            transform: none;
        }

        input {
            width: 210px;
            outline: none;
            background: rgba(15, 23, 42, 0.68);
            transition: border-color 160ms ease, box-shadow 160ms ease;
        }

        input:focus {
            border-color: rgba(56, 189, 248, 0.66);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.14);
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line-soft);
            border-radius: 8px;
            background: var(--panel);
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            min-width: 1540px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 10px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.12);
            text-align: right;
            white-space: nowrap;
            font-size: 14px;
            font-variant-numeric: tabular-nums;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 1;
            color: #d1d5db;
            background: linear-gradient(180deg, #303742, #252b34);
            font-size: 13px;
            font-weight: 900;
        }

        .sort-button {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            width: 100%;
            min-height: 26px;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font: inherit;
            font-weight: 900;
            text-align: right;
            white-space: nowrap;
            transition: color 150ms ease;
        }

        .sort-button:hover {
            color: #ffffff;
        }

        th:first-child .sort-button {
            justify-content: flex-start;
            text-align: left;
        }

        .sort-icon {
            min-width: 14px;
            color: var(--muted-2);
            font-size: 11px;
        }

        th.sorted .sort-icon {
            color: var(--amber);
        }

        th.sorted {
            color: #ffffff;
            background: linear-gradient(180deg, rgba(56, 189, 248, 0.2), #252b34);
        }

        td:first-child,
        th:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
            text-align: left;
            background: #20262e;
            box-shadow: 1px 0 0 #0b0d10, 10px 0 18px rgba(0, 0, 0, 0.16);
        }

        th:first-child {
            z-index: 3;
            background: linear-gradient(180deg, #303742, #252b34);
        }

        tbody tr {
            transition: background-color 150ms ease, box-shadow 150ms ease;
        }

        tr:hover td {
            background: #29323c;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.025), inset 0 -1px 0 rgba(255, 255, 255, 0.025);
        }

        tr:hover td:first-child {
            background: #25303a;
            box-shadow: inset 3px 0 0 rgba(56, 189, 248, 0.68), 1px 0 0 #0b0d10, 10px 0 18px rgba(0, 0, 0, 0.16);
        }

        [data-dashboard] :where(
            h1,
            .subtitle,
            .pill,
            .summary-card,
            .label,
            .value,
            .sub,
            tbody td,
            tbody td strong,
            tbody td .muted,
            .badge,
            .exchange-badge,
            .stock-name,
            .stock-code
        ) {
            cursor: default;
        }

        .stock-cell {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px 10px;
            align-items: center;
        }

        .badge-stack {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            color: #ffb020;
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.24), rgba(245, 158, 11, 0.09));
            font-size: 12px;
            font-weight: 900;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }

        .badge.cash {
            color: #38bdf8;
            background: linear-gradient(135deg, rgba(56, 189, 248, 0.24), rgba(56, 189, 248, 0.08));
        }

        .badge.short {
            color: #ff3b5c;
            background: linear-gradient(135deg, rgba(255, 59, 92, 0.24), rgba(255, 59, 92, 0.08));
        }

        .badge.day-trade {
            color: #22c55e;
            background: linear-gradient(135deg, rgba(34, 197, 94, 0.24), rgba(34, 197, 94, 0.08));
        }

        .exchange-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 999px;
            border: 1px solid currentColor;
            color: #cbd5e1;
            background: rgba(15, 23, 42, 0.48);
            font-size: 12px;
            font-weight: 900;
            line-height: 1;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08), 0 8px 16px rgba(0, 0, 0, 0.18);
        }

        .exchange-badge--twse {
            color: #60a5fa;
            background: rgba(37, 99, 235, 0.14);
        }

        .exchange-badge--tpex {
            color: #34d399;
            background: rgba(5, 150, 105, 0.14);
        }

        .exchange-badge--emerging {
            color: #fbbf24;
            background: rgba(180, 83, 9, 0.16);
        }

        .stock-name {
            font-size: 17px;
            font-weight: 900;
            color: #f5f5f5;
        }

        tbody tr:hover .stock-name {
            color: #ffffff;
            text-shadow: 0 0 18px rgba(56, 189, 248, 0.16);
        }

        .stock-code {
            margin-top: 2px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .empty,
        .error-box {
            padding: 24px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            color: var(--muted);
            text-align: center;
        }

        .error-box {
            color: #fecdd3;
            border-color: rgba(255, 59, 92, 0.45);
            background: rgba(255, 59, 92, 0.1);
        }

        .mini {
            font-size: 12px;
            color: var(--muted);
        }

        @keyframes panelIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes livePulse {
            0%,
            100% {
                transform: scale(1);
                opacity: 1;
            }
            50% {
                transform: scale(1.28);
                opacity: 0.7;
            }
        }

        @keyframes headerGlow {
            0%,
            100% {
                opacity: 0.65;
                filter: saturate(1);
            }
            50% {
                opacity: 1;
                filter: saturate(1.4);
            }
        }

        @keyframes starDrift {
            from {
                background-position: 0 0, 72px 48px, 24px 110px, 126px 86px;
            }
            to {
                background-position: 150px 150px, 302px 278px, 334px 420px, 546px 506px;
            }
        }

        @keyframes starTwinkle {
            0%,
            100% {
                opacity: 0.5;
                filter: brightness(0.9) saturate(1);
            }
            28% {
                opacity: 0.74;
                filter: brightness(1.35) saturate(1.25);
            }
            58% {
                opacity: 0.42;
                filter: brightness(0.72) saturate(0.92);
            }
            78% {
                opacity: 0.68;
                filter: brightness(1.18) saturate(1.18);
            }
        }

        @keyframes meteorFall {
            0% {
                opacity: 0;
                transform: translate3d(0, -18vh, 0) rotate(132deg) scale(0.82);
            }
            5% {
                opacity: 0;
            }
            12% {
                opacity: 0.96;
            }
            28% {
                opacity: 0.9;
            }
            44%,
            100% {
                opacity: 0;
                transform: translate3d(var(--dx, 38vw), 124vh, 0) rotate(132deg) scale(1.04);
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 1ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: 1ms !important;
            }

            body::before {
                animation-duration: 120s, 14s !important;
                animation-iteration-count: infinite !important;
            }

            body::after {
                animation: none !important;
            }

            .meteor {
                animation-duration: 24s !important;
                animation-iteration-count: infinite !important;
            }
        }

        @media (max-width: 1180px) {
            .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 760px) {
            .shell {
                width: min(100vw - 16px, 760px);
                padding-top: 10px;
            }

            .topbar,
            .toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .status-bar,
            .refresh-actions,
            .controls {
                justify-content: flex-start;
            }

            h1 { font-size: 24px; }
            .summary-grid { grid-template-columns: 1fr; }
            .value { font-size: 28px; }
            input { width: 100%; }
            .button { flex: 1 1 150px; }
        }
</style>
</head>
<body>
<div class="meteor-field" aria-hidden="true">
    <span class="meteor" style="--x: 8%; --dx: 34vw; --tail: 130px; --duration: 13s; --delay: -1s; --size: 2px;"></span>
    <span class="meteor" style="--x: 19%; --dx: 38vw; --tail: 170px; --duration: 15s; --delay: -9s; --size: 3px;"></span>
    <span class="meteor" style="--x: 32%; --dx: 30vw; --tail: 120px; --duration: 11s; --delay: -5s; --size: 2px;"></span>
    <span class="meteor" style="--x: 46%; --dx: 42vw; --tail: 190px; --duration: 17s; --delay: -13s; --size: 3px;"></span>
    <span class="meteor" style="--x: 57%; --dx: 29vw; --tail: 110px; --duration: 12s; --delay: -7s; --size: 2px;"></span>
    <span class="meteor" style="--x: 70%; --dx: 35vw; --tail: 150px; --duration: 14s; --delay: -3s; --size: 2px;"></span>
    <span class="meteor" style="--x: 83%; --dx: 26vw; --tail: 125px; --duration: 16s; --delay: -11s; --size: 2px;"></span>
    <span class="meteor" style="--x: 92%; --dx: 18vw; --tail: 160px; --duration: 18s; --delay: -15s; --size: 3px;"></span>
</div>
<div class="shell" data-dashboard>
    <header class="topbar">
        <div class="title-block">
            <h1>玉山庫存即時看板</h1>
            <div class="subtitle">正式 API · 玉山每分鐘校準 · 開盤每秒用雙來源確認價重算損益</div>
        </div>
        <div class="status-bar">
            <span class="pill" data-market-status>{{ $initialMarket['label'] }}</span>
            <span class="pill" data-refresh-status>等待更新</span>
            <span class="pill" data-last-updated>--</span>
        </div>
    </header>

    <section class="summary-grid">
        <div class="summary-card">
            <div class="label">今日損益</div>
            <div class="value" data-summary="todayPnl">--</div>
            <div class="sub" data-summary="todayPnlRate">--</div>
        </div>
        <div class="summary-card">
            <div class="label">即時累積損益</div>
            <div class="value" data-summary="unrealizedPnl">--</div>
            <div class="sub" data-summary="unrealizedPnlRate">--</div>
        </div>
        <div class="summary-card">
            <div class="label">股票市值</div>
            <div class="value neutral" data-summary="marketValue">--</div>
            <div class="sub" data-summary="costBasis">成本 --</div>
        </div>
        <div class="summary-card">
            <div class="label">今年總損益</div>
            <div class="value" data-summary="yearTotalPnl">--</div>
            <div class="return-rate-line" data-summary="yearTotalPnlRate">今年報酬率 --</div>
            <div class="sub" data-summary="yearTotalPnlBreakdown">玉山已實現 -- · 沖銷 --</div>
        </div>
        <div class="summary-card capital-card">
            <div class="label">投入總成本</div>
            <div class="value neutral" data-summary="investedCost">--</div>
            <div class="investment-metrics" data-summary="investmentLevel">
                <div class="investment-line">
                    <span class="investment-label">投資水位</span>
                    <span class="investment-value">--</span>
                </div>
                <div class="investment-line bank">
                    <span class="investment-label">銀行餘額</span>
                    <span class="investment-value">--</span>
                </div>
            </div>
        </div>
        <div class="summary-card">
            <div class="label">資料來源</div>
            <div class="value cyan" data-summary="sourceAge">--</div>
            <div class="sub" data-summary="servedAt">--</div>
        </div>
    </section>

    <section class="toolbar">
        <div class="refresh-actions">
            <button class="button primary" type="button" data-esun-refresh>更新玉山API</button>
            <button class="button secondary" type="button" data-quote-refresh>更新即時報價</button>
        </div>
        <div class="controls">
            <input type="search" placeholder="搜尋代號或名稱" data-filter>
        </div>
    </section>

    <div data-error class="error-box" style="display: none;"></div>

    <section class="table-wrap" data-position-wrap>
        <table>
            <thead>
            <tr>
                <th><button class="sort-button" type="button" data-sort-key="stock">庫存股 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="currentPrice">即時價 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="dayChangeRate">漲跌幅 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="todayPnl">今日損益 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="unrealizedPnl">即時總損益 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="unrealizedPnlRate">總報酬率 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="quantity">股數 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="averagePrice">均價 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="costBasis">投資成本 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="marketValue">即時市值 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="marketWeight">庫存占比 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="breakevenPrice">損益平衡價 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="fiveDayReturn">近5日漲幅 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="twentyDayReturn">近20日漲幅 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="sixtyDayReturn">近60日漲幅 <span class="sort-icon" data-sort-icon></span></button></th>
            </tr>
            </thead>
            <tbody data-positions>
            <tr><td colspan="15" class="empty">讀取中</td></tr>
            </tbody>
        </table>
    </section>
</div>

<script>
const apiUrl = @json($apiUrl);
const quoteUrl = @json($quoteUrl);
const dashboardToken = @json($token);
const state = {
    rows: [],
    dataLoading: false,
    quoteTimer: null,
    esunTimer: null,
    quoteLoading: false,
    lastPayload: null,
    sort: {
        key: 'unrealizedPnl',
        direction: 'desc',
    },
};

const els = {
    dashboard: document.querySelector('[data-dashboard]'),
    marketStatus: document.querySelector('[data-market-status]'),
    refreshStatus: document.querySelector('[data-refresh-status]'),
    lastUpdated: document.querySelector('[data-last-updated]'),
    positions: document.querySelector('[data-positions]'),
    error: document.querySelector('[data-error]'),
    filter: document.querySelector('[data-filter]'),
    esunRefresh: document.querySelector('[data-esun-refresh]'),
    quoteRefresh: document.querySelector('[data-quote-refresh]'),
    sortButtons: document.querySelectorAll('[data-sort-key]'),
};

function number(value) {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : 0;
}

function formatInteger(value) {
    return Math.round(number(value)).toLocaleString('zh-TW');
}

function formatMoney(value) {
    const numeric = number(value);
    const prefix = numeric > 0 ? '+' : '';
    return prefix + Math.round(numeric).toLocaleString('zh-TW');
}

function formatPrice(value) {
    if (value === null || value === undefined || value === '') return '--';
    return number(value).toLocaleString('zh-TW', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatPercent(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '--';
    const numeric = Number(value);
    const prefix = numeric > 0 ? '+' : '';
    return `${prefix}${numeric.toFixed(2)}%`;
}

function formatWeight(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '--';
    return `${Number(value).toFixed(2)}%`;
}

function formatInvestmentLevel(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '--';
    const roundedPercent = Math.round(Number(value));
    const cheng = Math.trunc(roundedPercent / 10);
    const fraction = Math.abs(roundedPercent % 10);

    return fraction === 0 ? `${cheng}成` : `${cheng}成${fraction}`;
}

function annualizeReturnRate(returnRate, elapsedDays) {
    if (returnRate === null || returnRate === undefined || !Number.isFinite(Number(returnRate))) {
        return null;
    }

    const days = Math.max(1, Number(elapsedDays) || 1);
    return Number(returnRate) * 365 / days;
}

function toneClass(value) {
    const numeric = Number(value);
    if (numeric > 0) return 'positive';
    if (numeric < 0) return 'negative';
    return 'neutral';
}

function setTone(element, value) {
    element.classList.remove('positive', 'negative', 'neutral');
    element.classList.add(toneClass(value));
}

function updateSummary(payload) {
    const summary = payload.summary || {};
    const source = payload.source || {};

    updateSummaryCards(
        { ...summary, marketOpen: Boolean(payload.market?.isOpen) },
        `庫存 ${payload.cacheSeconds || 0}s · 玉山 ${formatAge(source.ageSeconds)}`,
    );
}

function updateSummaryCards(summary, sourceText) {
    const esunTodayPnl = finiteNumber(summary.esunTodayPnl ?? state.lastPayload?.summary?.todayPnl ?? summary.todayPnl);
    const esunUnrealizedPnl = finiteNumber(summary.esunUnrealizedPnl ?? state.lastPayload?.summary?.unrealizedPnl ?? summary.unrealizedPnl);
    const esunMarketValue = finiteNumber(summary.esunMarketValue ?? state.lastPayload?.summary?.marketValue ?? summary.marketValue);
    const costBasis = finiteNumber(summary.costBasis ?? state.lastPayload?.summary?.costBasis);
    const bankBalance = finiteNumber(summary.bankBalance ?? state.lastPayload?.summary?.bankBalance);
    const investmentLevelRate = finiteNumber(summary.investmentLevelRate ?? state.lastPayload?.summary?.investmentLevelRate);
    const yearTotalPnl = finiteNumber(summary.yearTotalPnl ?? state.lastPayload?.summary?.yearTotalPnl);
    const yearTotalPnlRate = finiteNumber(summary.yearTotalPnlRate ?? state.lastPayload?.summary?.yearTotalPnlRate);
    const realizedYearPnl = finiteNumber(summary.realizedYearPnl ?? state.lastPayload?.summary?.realizedYearPnl);
    const dayTradeYearPnl = finiteNumber(summary.dayTradeYearPnl ?? state.lastPayload?.summary?.dayTradeYearPnl);

    const today = document.querySelector('[data-summary="todayPnl"]');
    today.textContent = formatMoney(summary.todayPnl);
    setTone(today, summary.todayPnl);

    const todayRate = document.querySelector('[data-summary="todayPnlRate"]');
    todayRate.textContent = summaryDeltaText(formatPercent(summary.todayPnlRate), '玉山', esunTodayPnl, number(summary.todayPnl) - number(esunTodayPnl));
    todayRate.className = `sub ${toneClass(summary.todayPnlRate)}`;

    const unrealized = document.querySelector('[data-summary="unrealizedPnl"]');
    unrealized.textContent = formatMoney(summary.unrealizedPnl);
    setTone(unrealized, summary.unrealizedPnl);

    const unrealizedRate = document.querySelector('[data-summary="unrealizedPnlRate"]');
    unrealizedRate.textContent = summaryDeltaText(formatPercent(summary.unrealizedPnlRate), '玉山', esunUnrealizedPnl, number(summary.unrealizedPnl) - number(esunUnrealizedPnl));
    unrealizedRate.className = `sub ${toneClass(summary.unrealizedPnlRate)}`;

    document.querySelector('[data-summary="marketValue"]').textContent = formatInteger(summary.marketValue);
    document.querySelector('[data-summary="costBasis"]').textContent = `玉山投資成本 ${formatInteger(costBasis)} · 玉山市值 ${formatInteger(esunMarketValue)} · 差 ${formatMoney(number(summary.marketValue) - number(esunMarketValue))}`;
    const year = document.querySelector('[data-summary="yearTotalPnl"]');
    year.textContent = yearTotalPnl === null ? '--' : formatMoney(yearTotalPnl);
    setTone(year, yearTotalPnl);
    const yearRate = document.querySelector('[data-summary="yearTotalPnlRate"]');
    yearRate.textContent = `今年報酬率 ${formatPercent(yearTotalPnlRate)}`;
    yearRate.className = `return-rate-line ${toneClass(yearTotalPnlRate)}`;
    document.querySelector('[data-summary="yearTotalPnlBreakdown"]').textContent =
        `玉山已實現 ${realizedYearPnl === null ? '--' : formatMoney(realizedYearPnl)} · ` +
        `沖銷 ${dayTradeYearPnl === null ? '--' : formatMoney(dayTradeYearPnl)}`;
    document.querySelector('[data-summary="investedCost"]').textContent = formatInteger(costBasis);
    renderInvestmentLevel(investmentLevelRate, bankBalance);
    document.querySelector('[data-summary="sourceAge"]').textContent = summary.marketOpen ? 'LIVE' : 'ONCE';
    document.querySelector('[data-summary="servedAt"]').textContent = sourceText;
}

function renderInvestmentLevel(investmentLevelRate, bankBalance) {
    const target = document.querySelector('[data-summary="investmentLevel"]');
    const levelValue = target.querySelector('.investment-line:not(.bank) .investment-value');
    const bankValue = target.querySelector('.investment-line.bank .investment-value');

    levelValue.textContent = formatInvestmentLevel(investmentLevelRate);
    bankValue.textContent = bankBalance === null ? '--' : formatInteger(bankBalance);
}

function finiteNumber(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : null;
}

function summaryDeltaText(prefix, label, baseline, diff) {
    if (baseline === null) {
        return prefix;
    }

    return `${prefix} · ${label} ${formatMoney(baseline)} · 差 ${formatMoney(diff)}`;
}

function stockCell(row) {
    const tradeLabel = row.tradeTypeLabel || tradeTypeLabel(row.tradeType);
    const badgeClass = tradeBadgeClass(row.tradeType);

    return `
        <div class="stock-cell">
            <span class="badge-stack">
                <span class="badge ${badgeClass}">${badgeLabelHtml(tradeLabel)}</span>
                ${exchangeBadgeHtml(row)}
            </span>
            <div>
                <div class="stock-name">${escapeHtml(row.stockName || '')}</div>
                <div class="stock-code">${escapeHtml(row.stockNo || '')}</div>
            </div>
        </div>
    `;
}

function exchangeBadgeHtml(row) {
    const meta = exchangeBadgeMeta(row);
    if (!meta) {
        return '';
    }

    return `<span class="exchange-badge exchange-badge--${escapeHtml(meta.className)}" title="${escapeHtml(meta.title)}" aria-label="${escapeHtml(meta.title)}" data-copy-text="${escapeHtml(meta.title)}">${escapeHtml(meta.label)}</span>`;
}

function exchangeBadgeMeta(row) {
    const backendLabel = String(row.exchangeShortLabel || '').trim();
    const backendClass = String(row.exchangeClass || '').trim();
    const backendTitle = String(row.exchangeLabel || '').trim();
    if (backendLabel !== '' && backendClass !== '' && backendTitle !== '') {
        return {
            label: backendLabel,
            className: backendClass,
            title: backendTitle,
        };
    }

    switch (String(row.exchange || '').trim().toUpperCase()) {
        case 'TWSE':
        case 'SII':
        case 'TSE':
        case 'TAI':
        case '上市':
            return { label: '市', className: 'twse', title: '上市' };
        case 'TPEX':
        case 'OTC':
        case 'TWO':
        case '上櫃':
            return { label: '櫃', className: 'tpex', title: '上櫃' };
        case 'EMERGING':
        case 'ESB':
        case '興櫃':
            return { label: '興', className: 'emerging', title: '興櫃' };
        default:
            return null;
    }
}

function tradeTypeLabel(tradeType) {
    switch (String(tradeType || '')) {
        case '0': return '現股';
        case '3': return '融資';
        case '4': return '融券';
        case '9': return '當沖';
        case 'A': return '沖賣';
        default: return tradeType ? String(tradeType) : '--';
    }
}

function tradeBadgeClass(tradeType) {
    switch (String(tradeType || '')) {
        case '0': return 'cash';
        case '4': return 'short';
        case '9':
        case 'A':
            return 'day-trade';
        default:
            return '';
    }
}

function badgeLabelHtml(label) {
    const value = String(label || '--');
    return value.length === 2
        ? `${escapeHtml(value[0])}<br>${escapeHtml(value[1])}`
        : escapeHtml(value);
}

function renderPositions() {
    const keyword = (els.filter.value || '').trim().toLowerCase();
    const rows = sortRows(state.rows.filter(row => {
        if (!keyword) return true;
        return String(row.stockNo).toLowerCase().includes(keyword)
            || String(row.stockName).toLowerCase().includes(keyword);
    }));

    if (!rows.length) {
        els.positions.innerHTML = '<tr><td colspan="15" class="empty">沒有符合條件的庫存</td></tr>';
        return;
    }

    els.positions.innerHTML = rows.map(row => `
        <tr>
            <td>${stockCell(row)}</td>
            <td class="${toneClass(row.realtimeDayChangeRate ?? row.dayChangeRate)}">
                <strong>${formatPrice(row.realtimePrice ?? row.currentPrice)}</strong>
            </td>
            <td class="${toneClass(row.realtimeDayChangeRate ?? row.dayChangeRate)}">
                ${formatPercent(row.realtimeDayChangeRate ?? row.dayChangeRate)}
            </td>
            <td class="${toneClass(row.todayPnl)}"><strong>${formatMoney(row.todayPnl)}</strong></td>
            <td class="${toneClass(row.unrealizedPnl)}">
                <strong>${formatMoney(row.unrealizedPnl)}</strong>
            </td>
            <td class="${toneClass(row.unrealizedPnlRate)}">${formatPercent(row.unrealizedPnlRate)}</td>
            <td>${formatInteger(row.quantity)}</td>
            <td>${formatPrice(row.averagePrice)}</td>
            <td>${formatInteger(row.costBasis)}</td>
            <td>
                ${formatInteger(row.marketValue)}
            </td>
            <td>${formatWeight(row.marketWeight)}</td>
            <td>${formatPrice(row.breakevenPrice)}</td>
            <td class="${toneClass(row.fiveDayReturn)}">${formatPercent(row.fiveDayReturn)}</td>
            <td class="${toneClass(row.twentyDayReturn)}">${formatPercent(row.twentyDayReturn)}</td>
            <td class="${toneClass(row.sixtyDayReturn)}">${formatPercent(row.sixtyDayReturn)}</td>
        </tr>
    `).join('');
}

function applyPayload(payload) {
    state.lastPayload = payload;
    state.rows = payload.rows || [];
    updateSummary(payload);
    updateSortIndicators();
    renderPositions();

    const market = payload.market || {};
    const source = payload.source || {};
    els.marketStatus.textContent = market.label || '--';
    els.marketStatus.classList.toggle('live', Boolean(market.isOpen));
    els.refreshStatus.textContent = source.status === 'stale'
        ? '顯示最近成功資料'
        : (market.isOpen ? '每秒報價 · 整分鐘校準玉山' : '非開盤已暫停輪詢');
    els.refreshStatus.classList.toggle('live', Boolean(market.isOpen));
    els.refreshStatus.classList.toggle('error', source.status === 'stale');
    els.lastUpdated.textContent = `更新 ${formatDateTime(payload.queriedAt || payload.servedAt)}`;
    fetchQuotes();
    scheduleQuotePolling(market);
    scheduleEsunPolling(market);
}

function sortRows(rows) {
    const direction = state.sort.direction === 'asc' ? 1 : -1;
    const key = state.sort.key;

    return [...rows].sort((a, b) => {
        const av = sortValue(a, key);
        const bv = sortValue(b, key);
        if (av === null && bv === null) return stockFallback(a, b);
        if (av === null) return 1;
        if (bv === null) return -1;
        if (typeof av === 'string' || typeof bv === 'string') {
            const compared = String(av).localeCompare(String(bv), 'zh-Hant', { numeric: true });
            return compared === 0 ? stockFallback(a, b) : compared * direction;
        }
        const compared = av === bv ? 0 : av > bv ? 1 : -1;
        return compared === 0 ? stockFallback(a, b) : compared * direction;
    });
}

function sortValue(row, key) {
    if (key === 'stock') {
        return `${row.stockNo || ''} ${row.stockName || ''}`;
    }

    if (key === 'currentPrice') {
        const price = Number(row.realtimePrice ?? row.currentPrice);
        return Number.isFinite(price) ? price : null;
    }

    const numeric = Number(row[key]);
    return Number.isFinite(numeric) ? numeric : null;
}

function stockFallback(a, b) {
    return String(a.stockNo || '').localeCompare(String(b.stockNo || ''), 'zh-Hant', { numeric: true });
}

function setSort(key) {
    if (state.sort.key === key) {
        state.sort.direction = state.sort.direction === 'asc' ? 'desc' : 'asc';
    } else {
        state.sort.key = key;
        state.sort.direction = key === 'stock' ? 'asc' : 'desc';
    }

    updateSortIndicators();
    renderPositions();
}

function updateSortIndicators() {
    els.sortButtons.forEach(button => {
        const active = button.dataset.sortKey === state.sort.key;
        const th = button.closest('th');
        const icon = button.querySelector('[data-sort-icon]');
        th.classList.toggle('sorted', active);
        th.setAttribute('aria-sort', active ? (state.sort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
        icon.textContent = active ? (state.sort.direction === 'asc' ? '▲' : '▼') : '↕';
    });
}

function scheduleQuotePolling(market) {
    if (state.quoteTimer) {
        clearInterval(state.quoteTimer);
        state.quoteTimer = null;
    }

    if (market?.isOpen) {
        state.quoteTimer = setInterval(() => fetchQuotes(), 1000);
    }
}

function millisecondsUntilNextMinute(date = new Date()) {
    const elapsed = date.getSeconds() * 1000 + date.getMilliseconds();

    // Small offset keeps the browser from firing a hair before the minute flips.
    return Math.max(250, 60000 - elapsed + 250);
}

function scheduleEsunPolling(market) {
    if (state.esunTimer) {
        clearTimeout(state.esunTimer);
        state.esunTimer = null;
    }

    if (!market?.isOpen) {
        return;
    }

    state.esunTimer = setTimeout(async () => {
        state.esunTimer = null;
        await fetchData(true, { background: true });

        if (!state.esunTimer) {
            scheduleEsunPolling(state.lastPayload?.market || market);
        }
    }, millisecondsUntilNextMinute());
}

async function fetchData(force, options = {}) {
    if (state.dataLoading) {
        return;
    }

    state.dataLoading = true;
    els.esunRefresh.disabled = true;
    const url = new URL(apiUrl, window.location.origin);
    if (dashboardToken) {
        url.searchParams.set('token', dashboardToken);
    }
    if (force) url.searchParams.set('force', '1');

    if (!options.background) {
        els.refreshStatus.textContent = force ? '讀取玉山庫存中' : '更新中';
        els.refreshStatus.classList.remove('error');
    }
    els.error.style.display = 'none';

    try {
        const response = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        applyPayload(await response.json());
    } catch (error) {
        if (!options.background) {
            els.refreshStatus.textContent = '更新失敗';
            els.refreshStatus.classList.add('error');
        }
        els.error.style.display = 'block';
        els.error.textContent = `讀取玉山庫存失敗：${error.message}`;
    } finally {
        state.dataLoading = false;
        els.esunRefresh.disabled = false;
    }
}

async function fetchQuotes(manual = false) {
    if (state.quoteLoading || !state.rows.length) {
        return;
    }

    state.quoteLoading = true;
    els.quoteRefresh.disabled = true;
    if (manual) {
        els.refreshStatus.textContent = '更新即時報價中';
        els.refreshStatus.classList.remove('error');
    }
    const url = new URL(quoteUrl, window.location.origin);
    if (dashboardToken) {
        url.searchParams.set('token', dashboardToken);
    }
    url.searchParams.set('codes', state.rows.map(row => row.stockNo).join(','));

    try {
        const response = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        applyQuotes(await response.json());
    } catch (error) {
        els.refreshStatus.textContent = '報價暫時失敗';
        els.refreshStatus.classList.add('error');
    } finally {
        state.quoteLoading = false;
        els.quoteRefresh.disabled = false;
    }
}

function applyQuotes(payload) {
    const quotes = payload.quotes || {};
    let changed = false;

    state.rows = state.rows.map(row => {
        const quote = quotes[row.stockNo];
        if (!quote || !Number.isFinite(Number(quote.price))) {
            const quoteContext = quoteContextFromUnconfirmed(payload, row.stockNo);
            if (!quoteContext) {
                return row;
            }

            const nextRow = applyPreviousCloseToRow(row, quoteContext);
            if (nextRow !== row) {
                changed = true;
            }

            return nextRow;
        }

        changed = true;
        return applyQuoteToRow(row, quote);
    });

    if (!changed) {
        updateQuoteStatus(payload);
        return;
    }

    recalculateWeights();
    updateSummaryCards(buildSummaryFromRows(), quoteSourceText(payload));
    updateSortIndicators();
    renderPositions();
    updateQuoteStatus(payload);
}

function applyQuoteToRow(row, quote) {
    const price = number(quote.price);
    const quotePreviousClose = Number.isFinite(Number(quote.previousClose))
        ? Number(quote.previousClose)
        : null;
    const rowPreviousClose = Number.isFinite(Number(row.previousClose))
        ? Number(row.previousClose)
        : null;
    const previousClose = quotePreviousClose ?? rowPreviousClose;
    const quantity = number(row.quantity);
    const costBasis = number(row.costBasis);
    const esunMarketValue = Number.isFinite(Number(row.esunMarketValue)) ? Number(row.esunMarketValue) : number(row.marketValue);
    const esunUnrealizedPnl = Number.isFinite(Number(row.esunUnrealizedPnl)) ? Number(row.esunUnrealizedPnl) : number(row.unrealizedPnl);
    const esunCurrentPrice = Number.isFinite(Number(row.esunCurrentPrice)) ? Number(row.esunCurrentPrice) : number(row.currentPrice);
    const pnlBasePrice = Number.isFinite(Number(row.realtimePnlBasePrice)) ? Number(row.realtimePnlBasePrice) : esunCurrentPrice;
    const dayChange = previousClose === null || previousClose === undefined ? null : price - number(previousClose);
    const esunDayChange = previousClose === null || previousClose === undefined ? row.dayChange : esunCurrentPrice - number(previousClose);
    const todayPnl = previousClose === null || previousClose === undefined
        ? row.todayPnl
        : dayChange * quantity;
    const esunTodayPnl = previousClose === null || previousClose === undefined
        ? row.esunTodayPnl
        : esunDayChange * quantity;
    const marketValue = price * quantity;
    const unrealizedPnl = esunUnrealizedPnl + (price - pnlBasePrice) * quantity;

    return {
        ...row,
        esunCurrentPrice,
        esunMarketValue,
        esunUnrealizedPnl,
        realtimePnlBasePrice: pnlBasePrice,
        previousClose,
        dayChange: esunDayChange,
        dayChangeRate: number(previousClose) > 0 ? esunDayChange / number(previousClose) * 100 : row.dayChangeRate,
        esunTodayPnl,
        realtimePrice: price,
        realtimePreviousClose: previousClose,
        realtimeDayChange: dayChange,
        realtimeDayChangeRate: number(previousClose) > 0 ? dayChange / number(previousClose) * 100 : null,
        todayPnl,
        marketValue,
        unrealizedPnl,
        unrealizedPnlRate: costBasis > 0 ? unrealizedPnl / costBasis * 100 : null,
        quoteSource: quote.sourceLabel || quote.source || '',
        quoteType: quote.priceType || 'last',
        quoteAt: quote.quotedAt || null,
        bestBid: quote.bestBid ?? null,
        bestAsk: quote.bestAsk ?? null,
        quoteConfirmationCount: quote.confirmationCount ?? null,
        quoteConfirmedBy: quote.confirmedBy || [],
    };
}

function quoteContextFromUnconfirmed(payload, stockNo) {
    const candidates = payload.unconfirmed?.[stockNo];
    if (!Array.isArray(candidates)) {
        return null;
    }

    return candidates.find(candidate => Number.isFinite(Number(candidate.previousClose))) || null;
}

function applyPreviousCloseToRow(row, quote) {
    const previousClose = Number.isFinite(Number(quote.previousClose))
        ? Number(quote.previousClose)
        : null;
    if (previousClose === null) {
        return row;
    }

    const currentPreviousClose = Number.isFinite(Number(row.previousClose))
        ? Number(row.previousClose)
        : null;
    const needsFallbackUpdate = !Number.isFinite(Number(row.esunMarketValue))
        || !Number.isFinite(Number(row.esunUnrealizedPnl))
        || !Number.isFinite(Number(row.esunTodayPnl))
        || !Number.isFinite(Number(row.todayPnl));
    if (currentPreviousClose === previousClose && Number.isFinite(Number(row.dayChangeRate)) && !needsFallbackUpdate) {
        return row;
    }

    const esunCurrentPrice = Number.isFinite(Number(row.esunCurrentPrice))
        ? Number(row.esunCurrentPrice)
        : number(row.currentPrice);
    const esunMarketValue = Number.isFinite(Number(row.esunMarketValue))
        ? Number(row.esunMarketValue)
        : number(row.marketValue);
    const esunUnrealizedPnl = Number.isFinite(Number(row.esunUnrealizedPnl))
        ? Number(row.esunUnrealizedPnl)
        : number(row.unrealizedPnl);
    const pnlBasePrice = Number.isFinite(Number(row.realtimePnlBasePrice))
        ? Number(row.realtimePnlBasePrice)
        : esunCurrentPrice;
    const quantity = number(row.quantity);
    const esunDayChange = esunCurrentPrice - previousClose;
    const hasRealtimePrice = Number.isFinite(Number(row.realtimePrice));

    return {
        ...row,
        esunCurrentPrice,
        esunMarketValue,
        esunUnrealizedPnl,
        realtimePnlBasePrice: pnlBasePrice,
        previousClose,
        dayChange: esunDayChange,
        dayChangeRate: previousClose > 0 ? esunDayChange / previousClose * 100 : row.dayChangeRate,
        esunTodayPnl: esunDayChange * quantity,
        todayPnl: hasRealtimePrice ? row.todayPnl : esunDayChange * quantity,
        realtimePreviousClose: row.realtimePreviousClose ?? previousClose,
    };
}

function recalculateWeights() {
    const totalMarketValue = state.rows.reduce((sum, row) => sum + number(row.marketValue), 0);
    state.rows = state.rows.map(row => ({
        ...row,
        marketWeight: totalMarketValue > 0 ? number(row.marketValue) / totalMarketValue * 100 : null,
    }));
}

function buildSummaryFromRows() {
    const marketValue = state.rows.reduce((sum, row) => sum + number(row.marketValue), 0);
    const costBasis = state.rows.reduce((sum, row) => sum + number(row.costBasis), 0);
    const bankBalance = finiteNumber(state.lastPayload?.summary?.bankBalance);
    const totalCapital = bankBalance === null ? null : costBasis + bankBalance;
    const todayPnl = state.rows.reduce((sum, row) => sum + number(row.todayPnl), 0);
    const esunTodayPnl = state.rows.reduce((sum, row) => sum + number(row.esunTodayPnl), 0);
    const unrealizedPnl = state.rows.reduce((sum, row) => sum + number(row.unrealizedPnl), 0);
    const realizedYearPnl = finiteNumber(state.lastPayload?.summary?.realizedYearPnl);
    const yearTotalPnl = realizedYearPnl;
    const yearReturnBase = yearTotalPnl !== null && totalCapital !== null ? totalCapital - yearTotalPnl : null;
    const yearTotalPnlRate = yearReturnBase !== null && yearReturnBase > 0 ? yearTotalPnl / yearReturnBase * 100 : null;
    const yearElapsedDays = Number(state.lastPayload?.summary?.yearElapsedDays) || 1;
    const previousMarketValue = state.rows.reduce((sum, row) => {
        const previousClose = row.realtimePreviousClose ?? row.previousClose;
        return Number.isFinite(Number(previousClose)) ? sum + number(previousClose) * number(row.quantity) : sum;
    }, 0);

    return {
        ...(state.lastPayload?.summary || {}),
        marketValue,
        costBasis,
        bankBalance,
        investmentLevelRate: totalCapital !== null && totalCapital > 0 ? costBasis / totalCapital * 100 : null,
        todayPnl,
        todayPnlRate: previousMarketValue > 0 ? todayPnl / previousMarketValue * 100 : null,
        unrealizedPnl,
        unrealizedPnlRate: costBasis > 0 ? unrealizedPnl / costBasis * 100 : null,
        yearTotalPnl,
        yearReturnBase,
        yearTotalPnlRate,
        yearElapsedDays,
        annualizedReturnRate: annualizeReturnRate(yearTotalPnlRate, yearElapsedDays),
        esunTodayPnl,
        esunUnrealizedPnl: state.lastPayload?.summary?.unrealizedPnl ?? null,
        esunMarketValue: state.lastPayload?.summary?.marketValue ?? null,
        marketOpen: Boolean((state.lastPayload?.market || {}).isOpen),
    };
}

function updateQuoteStatus(payload) {
    const market = payload.market || state.lastPayload?.market || {};
    const source = payload.source || {};
    els.marketStatus.textContent = market.label || els.marketStatus.textContent;
    els.marketStatus.classList.toggle('live', Boolean(market.isOpen));

    const ok = ['live', 'partial'].includes(source.status);
    els.refreshStatus.textContent = ok
        ? `報價每秒更新 · ${source.label || '--'}`
        : (market.isOpen ? '報價來源暫時缺漏' : '非開盤已暫停輪詢');
    els.refreshStatus.classList.toggle('live', ok && Boolean(market.isOpen));
    els.refreshStatus.classList.toggle('error', !ok && Boolean(market.isOpen));
    els.lastUpdated.textContent = `報價 ${formatDateTime(payload.servedAt)}`;

    if (market.isOpen === false) {
        scheduleQuotePolling(market);
        scheduleEsunPolling(market);
    }
}

function quoteSourceText(payload) {
    const source = payload.source || {};
    return `即時損益 · ${source.label || '--'} · 快取 ${payload.cacheSeconds || 1}s`;
}

function formatDate(value) {
    const raw = String(value || '');
    if (raw.length !== 8) return raw || '--';
    return `${raw.slice(0, 4)}/${raw.slice(4, 6)}/${raw.slice(6, 8)}`;
}

function formatTime(value) {
    const raw = String(value || '');
    if (raw.length < 6) return raw || '--';
    return `${raw.slice(0, 2)}:${raw.slice(2, 4)}:${raw.slice(4, 6)}`;
}

function formatDateTime(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString('zh-TW', {
        timeZone: 'Asia/Taipei',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
}

function formatAge(value) {
    const seconds = Number(value);
    if (!Number.isFinite(seconds)) return '--';
    if (seconds < 60) return `${Math.round(seconds)} 秒前`;
    const minutes = Math.floor(seconds / 60);
    const rest = Math.round(seconds % 60);
    return rest > 0 ? `${minutes} 分 ${rest} 秒前` : `${minutes} 分前`;
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function setupSilentCopy() {
    els.dashboard.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0) return;
        const target = event.target;
        if (!(target instanceof Element)) return;
        if (target.closest('button, input, textarea, select, a')) return;

        const copyTarget = target.closest([
            '[data-copy-text]',
            '.stock-name',
            '.stock-code',
            '.badge',
            '.exchange-badge',
            'strong',
            '.muted',
            '.value',
            '.sub',
            '.label',
            '.pill',
            'td',
            'h1',
            '.subtitle',
            '.summary-card',
        ].join(', '));
        if (!copyTarget || !els.dashboard.contains(copyTarget)) return;

        const text = copyTarget.getAttribute('data-copy-text')
            || copyTarget.innerText
            || copyTarget.textContent
            || '';
        const normalized = normalizeCopyText(text);
        if (normalized === '') return;

        copyText(normalized);
    });
}

function normalizeCopyText(value) {
    return String(value)
        .replace(/\u00a0/g, ' ')
        .split(/\n+/)
        .map(line => line.replace(/\s+/g, ' ').trim())
        .filter(Boolean)
        .join(' ');
}

function copyText(value) {
    if (navigator.clipboard?.writeText) {
        navigator.clipboard.writeText(value).catch(() => fallbackCopyText(value));
        return;
    }

    fallbackCopyText(value);
}

function fallbackCopyText(value) {
    const textarea = document.createElement('textarea');
    textarea.value = value;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
    } finally {
        textarea.remove();
    }
}

els.filter.addEventListener('input', () => {
    renderPositions();
});
els.esunRefresh.addEventListener('click', () => fetchData(true));
els.quoteRefresh.addEventListener('click', () => fetchQuotes(true));
els.sortButtons.forEach(button => {
    button.addEventListener('click', () => setSort(button.dataset.sortKey));
});

setupSilentCopy();
updateSortIndicators();
fetchData(true);
</script>
</body>
</html>
