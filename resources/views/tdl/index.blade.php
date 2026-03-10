<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TDL 指令產生器</title>
    <style>
        :root {
            --bg-a: #f7fbff;
            --bg-b: #eef7f1;
            --panel: rgba(255, 255, 255, 0.82);
            --panel-strong: rgba(255, 255, 255, 0.94);
            --line: rgba(148, 163, 184, 0.22);
            --line-strong: rgba(96, 165, 250, 0.30);
            --text: #18324d;
            --muted: #677a91;
            --accent: #5a95ff;
            --accent-soft: rgba(90, 149, 255, 0.14);
            --accent-2: #53c5a7;
            --danger: #b42318;
            --danger-bg: rgba(239, 68, 68, 0.08);
            --danger-line: rgba(239, 68, 68, 0.18);
            --shadow: 0 24px 60px rgba(134, 157, 181, 0.18);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "Microsoft JhengHei", sans-serif;
            color: var(--text);
            background:
                radial-gradient(980px 520px at 8% 10%, rgba(90, 149, 255, 0.14), transparent 58%),
                radial-gradient(760px 440px at 92% 12%, rgba(83, 197, 167, 0.16), transparent 56%),
                radial-gradient(1100px 680px at 50% 100%, rgba(255, 255, 255, 0.72), transparent 68%),
                linear-gradient(145deg, var(--bg-a), var(--bg-b));
        }

        .page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 36px 20px 48px;
            position: relative;
        }

        .page::before {
            content: "";
            position: absolute;
            inset: 14px 8px auto;
            height: 220px;
            border-radius: 30px;
            background: linear-gradient(135deg, rgba(255,255,255,.72), rgba(255,255,255,.34));
            filter: blur(2px);
            pointer-events: none;
            z-index: 0;
        }

        .hero {
            position: relative;
            z-index: 1;
            margin-bottom: 22px;
            padding: 28px 30px;
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,.74);
            background: linear-gradient(180deg, rgba(255,255,255,.88), rgba(255,255,255,.74));
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }

        h2 {
            margin: 0 0 10px;
            font-size: 34px;
            letter-spacing: .04em;
            color: #1d4f91;
        }

        .lead {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            max-width: 760px;
        }

        .row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .col {
            flex: 1;
            min-width: 360px;
        }

        .box {
            position: relative;
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,.74);
            background: linear-gradient(180deg, var(--panel), rgba(255,255,255,.76));
            box-shadow: var(--shadow);
            padding: 18px;
            backdrop-filter: blur(12px);
        }

        .box::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 6px;
            border-radius: 24px 24px 0 0;
            background: linear-gradient(90deg, rgba(90,149,255,.72), rgba(83,197,167,.72));
        }

        label {
            display: block;
            margin: 12px 0 8px;
            font-weight: 700;
            color: #29527d;
            letter-spacing: .01em;
        }

        textarea,
        input[type="number"],
        input[type="text"] {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--panel-strong);
            color: var(--text);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.82);
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        textarea {
            height: 360px;
            padding: 14px 16px;
            font-family: Consolas, monospace;
            font-size: 13px;
            line-height: 1.55;
            resize: vertical;
        }

        input[type="number"],
        input[type="text"] {
            padding: 11px 13px;
            font-family: Consolas, monospace;
            font-size: 13px;
        }

        textarea:focus,
        input[type="number"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: var(--line-strong);
            box-shadow: 0 0 0 5px var(--accent-soft);
            transform: translateY(-1px);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .btn {
            padding: 11px 18px;
            border: 0;
            border-radius: 14px;
            cursor: pointer;
            color: #fff;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            box-shadow: 0 18px 30px rgba(90,149,255,.24);
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 34px rgba(90,149,255,.28);
            filter: brightness(1.03);
        }

        .btn.secondary {
            color: #31557d;
            background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(237,246,255,.88));
            border: 1px solid rgba(96,165,250,.18);
            box-shadow: 0 14px 24px rgba(148,163,184,.16);
        }

        .error {
            margin: 0 0 16px;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid var(--danger-line);
            background: var(--danger-bg);
            color: var(--danger);
            white-space: pre-wrap;
            box-shadow: 0 12px 24px rgba(239,68,68,.08);
        }

        .hint {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
            margin-top: 8px;
        }

        @media (max-width: 860px) {
            .page { padding: 24px 14px 36px; }
            .hero { padding: 22px 18px; border-radius: 22px; }
            h2 { font-size: 28px; }
            .col { min-width: 100%; }
            textarea { height: 300px; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="hero">
        <h2>TDL 指令產生器</h2>
        <div class="lead">
            貼上 JSON 後可快速產出 tdl 指令。按 Enter 直接送出，Shift+Enter 仍可換行，不影響原本使用方式。
        </div>
    </div>

    @if (!empty($error))
        <div class="error">錯誤：{{ $error }}</div>
    @endif

    <form id="tdlForm" method="POST" action="/tdl">
        @csrf

        <div class="row">
            <div class="col box">
                <label for="json_text">JSON 內容</label>
                <textarea
                    id="json_text"
                    name="json_text"
                    placeholder="貼上或輸入 JSON 內容..."
                >{{ $inputJson }}</textarea>

                <div class="hint">
                    Enter 直接送出，Shift+Enter 可換行。
                </div>
            </div>

            <div class="col box">
                <label for="workdir">工作目錄，例如 C:\Users\User\Videos\Captures</label>
                <input
                    id="workdir"
                    name="workdir"
                    type="text"
                    value="{{ $workdir }}"
                    placeholder="C:\Users\User\Videos\Captures"
                >

                <label for="pair_per_line">每行配對數，對應 -u，例如 2</label>
                <input id="pair_per_line" name="pair_per_line" type="number" min="1" value="{{ $pairPerLine }}">

                <label for="threads">執行緒，對應 -t，例如 12</label>
                <input id="threads" name="threads" type="number" min="1" value="{{ $threads }}">

                <label for="limit">限制數，對應 -l，例如 12</label>
                <input id="limit" name="limit" type="number" min="1" value="{{ $limit }}">

                <div class="actions">
                    <button class="btn" type="submit">產生指令</button>
                    <button class="btn secondary" type="button" id="copyBtn">複製結果</button>
                </div>

                <label for="output">輸出指令</label>
                <textarea id="output" readonly placeholder="這裡會顯示產生出的 tdl 指令...">{{ $outputCmd }}</textarea>
            </div>
        </div>
    </form>
</div>

<script>
    (function () {
        const form = document.getElementById('tdlForm');
        const jsonText = document.getElementById('json_text');
        const output = document.getElementById('output');
        const copyBtn = document.getElementById('copyBtn');

        jsonText.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                form.submit();
            }
        });

        copyBtn.addEventListener('click', async function () {
            const text = output.value || '';
            if (!text.trim()) {
                return;
            }

            try {
                await navigator.clipboard.writeText(text);
            } catch (err) {
                output.focus();
                output.select();
                document.execCommand('copy');
            }
        });
    })();
</script>
</body>
</html>
