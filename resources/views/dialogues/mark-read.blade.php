<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dialogues - 標記已讀</title>
    <style>
        :root {
            --bg-a: #f7fbff;
            --bg-b: #eef6f8;
            --panel: rgba(255,255,255,.84);
            --panel-strong: rgba(255,255,255,.94);
            --line: rgba(148,163,184,.22);
            --line-strong: rgba(96,165,250,.28);
            --text: #19324d;
            --muted: #677b92;
            --accent: #4f8cff;
            --accent-2: #58c7b2;
            --accent-soft: rgba(79,140,255,.14);
            --ok-bg: rgba(34,197,94,.10);
            --ok-line: rgba(34,197,94,.18);
            --ok-text: #1f6b3a;
            --err-bg: rgba(239,68,68,.08);
            --err-line: rgba(239,68,68,.18);
            --err-text: #b42318;
            --shadow: 0 26px 60px rgba(132, 154, 181, .18);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "Noto Sans TC", sans-serif;
            color: var(--text);
            background:
                radial-gradient(980px 520px at 8% 10%, rgba(79,140,255,.15), transparent 58%),
                radial-gradient(780px 460px at 92% 12%, rgba(88,199,178,.16), transparent 56%),
                radial-gradient(1100px 680px at 50% 100%, rgba(255,255,255,.70), transparent 68%),
                linear-gradient(145deg, var(--bg-a), var(--bg-b));
        }

        .wrap {
            max-width: 1040px;
            margin: 0 auto;
            padding: 34px 18px 48px;
            position: relative;
        }

        .wrap::before {
            content: "";
            position: absolute;
            inset: 12px 8px auto;
            height: 220px;
            border-radius: 30px;
            background: linear-gradient(135deg, rgba(255,255,255,.72), rgba(255,255,255,.34));
            filter: blur(2px);
            pointer-events: none;
            z-index: 0;
        }

        .hero,
        .card {
            position: relative;
            z-index: 1;
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,.74);
            background: linear-gradient(180deg, rgba(255,255,255,.88), rgba(255,255,255,.74));
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }

        .hero {
            padding: 28px 30px;
            margin-bottom: 20px;
        }

        .card {
            padding: 20px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 32px;
            letter-spacing: .03em;
            color: #1d4f91;
        }

        .hint {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.75;
        }

        textarea {
            width: 100%;
            min-height: 280px;
            padding: 14px 16px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: var(--panel-strong);
            color: var(--text);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.82);
            font-size: 14px;
            line-height: 1.7;
            resize: vertical;
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        textarea:focus {
            outline: none;
            border-color: var(--line-strong);
            box-shadow: 0 0 0 5px var(--accent-soft);
            transform: translateY(-1px);
        }

        .row {
            display: flex;
            gap: 10px;
            margin-top: 14px;
            align-items: center;
            flex-wrap: wrap;
        }

        button {
            padding: 11px 18px;
            border: 0;
            border-radius: 14px;
            cursor: pointer;
            font-weight: 700;
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
        }

        button:hover {
            transform: translateY(-1px);
            filter: brightness(1.03);
        }

        .btn-primary {
            color: #fff;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            box-shadow: 0 18px 30px rgba(79,140,255,.24);
        }

        .btn-secondary {
            color: #31557d;
            background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(237,246,255,.88));
            border: 1px solid rgba(96,165,250,.18);
            box-shadow: 0 14px 24px rgba(148,163,184,.16);
        }

        .msg,
        .err {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 16px;
            white-space: pre-wrap;
        }

        .msg {
            background: var(--ok-bg);
            color: var(--ok-text);
            border: 1px solid var(--ok-line);
        }

        .err {
            background: var(--err-bg);
            color: var(--err-text);
            border: 1px solid var(--err-line);
        }

        .small {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.7;
        }

        code {
            background: rgba(240,244,255,.92);
            color: #34527a;
            padding: 2px 7px;
            border-radius: 8px;
            border: 1px solid rgba(148,163,184,.16);
        }

        ul {
            margin: 8px 0 0 18px;
            padding: 0;
        }

        @media (max-width: 780px) {
            .wrap { padding: 24px 14px 36px; }
            .hero { padding: 22px 18px; }
            .card { padding: 16px; }
            h1 { font-size: 28px; }
            textarea { min-height: 240px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero">
        <h1>Dialogues 標記已讀</h1>
        <div class="hint">
            貼上要標記的文字內容，每行一筆。<br>
            在輸入框按 <b>Enter</b> 會直接送出；如果要換行，請改按 <b>Shift + Enter</b>。
        </div>
    </div>

    <div class="card">
        @if (session('mark_result'))
            <div class="msg">{{ session('mark_result') }}</div>
        @endif

        @if ($errors->any())
            <div class="err">
                <div><b>送出失敗：</b></div>
                <ul>
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="markForm" method="POST" action="{{ route('dialogues.markRead.mark') }}">
            @csrf
            <textarea id="lines" name="lines" placeholder="每行貼上一筆內容，按 Enter 直接送出。">{{ old('lines', '') }}</textarea>

            <div class="row">
                <button type="submit" class="btn-primary">標記已讀</button>
                <button type="button" class="btn-secondary" id="clearBtn">清空內容</button>
                <span class="small">會比對 <code>dialogues.text</code>，將符合項目的 <code>is_read</code> 更新為 <code>1</code>。</span>
            </div>
        </form>
    </div>
</div>

<script>
    (function () {
        var textarea = document.getElementById('lines');
        var form = document.getElementById('markForm');
        var clearBtn = document.getElementById('clearBtn');

        clearBtn.addEventListener('click', function () {
            textarea.value = '';
            textarea.focus();
        });

        textarea.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') {
                return;
            }

            if (event.shiftKey) {
                return;
            }

            event.preventDefault();

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        });
    })();
</script>
</body>
</html>
