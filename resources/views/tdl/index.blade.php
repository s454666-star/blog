<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TDL 指令產生器</title>
    <style>
        body { font-family: Arial, "Microsoft JhengHei", sans-serif; margin: 16px; }
        .row { display: flex; gap: 16px; flex-wrap: wrap; }
        .col { flex: 1; min-width: 360px; }
        textarea { width: 100%; height: 360px; padding: 10px; box-sizing: border-box; font-family: Consolas, monospace; font-size: 13px; }
        input[type="number"] { width: 100%; padding: 8px; box-sizing: border-box; }
        input[type="text"] { width: 100%; padding: 8px; box-sizing: border-box; font-family: Consolas, monospace; font-size: 13px; }
        .btn { padding: 10px 14px; cursor: pointer; }
        .error { color: #b00020; margin: 10px 0; white-space: pre-wrap; }
        .hint { color: #666; font-size: 13px; margin-top: 6px; }
        .box { border: 1px solid #ddd; padding: 12px; border-radius: 8px; }
        label { display: block; margin: 10px 0 6px; font-weight: 600; }
    </style>
</head>
<body>
<h2>TDL 指令產生器</h2>

<div class="hint">
    貼上 JSON 後，在左邊輸入框按 Enter 就會送出（Shift+Enter 可換行）。
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
                placeholder="把很長的 JSON 貼在這裡..."
            >{{ $inputJson }}</textarea>

            <div class="hint">
                Enter：送出　｜　Shift+Enter：換行
            </div>
        </div>

        <div class="col box">
            <label for="workdir">下載位置（預設 C:\Users\User\Videos\Captures）</label>
            <input
                id="workdir"
                name="workdir"
                type="text"
                value="{{ $workdir }}"
                placeholder="C:\Users\User\Videos\Captures"
            >

            <label for="pair_per_line">每行放幾個 -u（預設 2）</label>
            <input id="pair_per_line" name="pair_per_line" type="number" min="1" value="{{ $pairPerLine }}">

            <label for="threads">-t（預設 12）</label>
            <input id="threads" name="threads" type="number" min="1" value="{{ $threads }}">

            <label for="limit">-l（預設 12）</label>
            <input id="limit" name="limit" type="number" min="1" value="{{ $limit }}">

            <div style="margin-top: 12px;">
                <button class="btn" type="submit">產生指令</button>
                <button class="btn" type="button" id="copyBtn">複製輸出</button>
            </div>

            <label for="output">輸出</label>
            <textarea id="output" readonly placeholder="這裡會出現 tdl 指令...">{{ $outputCmd }}</textarea>
        </div>
    </div>
</form>

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
