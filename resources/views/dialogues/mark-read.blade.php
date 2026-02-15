<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dialogues - 標記已讀</title>
    <style>
        body { font-family: Arial, "Noto Sans TC", sans-serif; background: #f6f7fb; margin: 0; padding: 0; }
        .wrap { max-width: 980px; margin: 30px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 6px 20px rgba(0,0,0,0.06); padding: 18px; }
        h1 { margin: 0 0 10px; font-size: 20px; }
        .hint { color: #666; font-size: 14px; line-height: 1.6; margin-bottom: 12px; }
        textarea { width: 100%; min-height: 260px; padding: 12px; border-radius: 10px; border: 1px solid #d7dbe7; font-size: 14px; line-height: 1.6; resize: vertical; box-sizing: border-box; }
        .row { display: flex; gap: 10px; margin-top: 12px; align-items: center; flex-wrap: wrap; }
        button { padding: 10px 14px; border: none; border-radius: 10px; cursor: pointer; font-weight: 600; }
        .btn-primary { background: #2f6fed; color: #fff; }
        .btn-secondary { background: #eef1f9; color: #223; }
        .msg { margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: #eef9f0; color: #1b5e20; }
        .err { margin-top: 12px; padding: 10px 12px; border-radius: 10px; background: #fdecea; color: #b71c1c; }
        .small { font-size: 12px; color: #666; }
        code { background: #f0f2f8; padding: 2px 6px; border-radius: 6px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>Dialogues 標記已讀</h1>

        <div class="hint">
            貼上多行文字（每行一筆）<br>
            送出方式：在文字框內 <b>按 Enter</b>（送出標記）或按下「標記」按鈕。<br>
            若你需要在框內手動換行，請用 <b>Shift + Enter</b>。
        </div>

        @if (session('mark_result'))
            <div class="msg">{{ session('mark_result') }}</div>
        @endif

        @if ($errors->any())
            <div class="err">
                <div><b>送出失敗：</b></div>
                <ul style="margin: 8px 0 0 18px;">
                    @foreach ($errors->all() as $e)
                        <li>{{ $e }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="markForm" method="POST" action="{{ route('dialogues.markRead.mark') }}">
            @csrf
            <textarea id="lines" name="lines" placeholder="每行一筆，貼上後按 Enter 或按「標記」即可">{{ old('lines', '') }}</textarea>

            <div class="row">
                <button type="submit" class="btn-primary">標記</button>
                <button type="button" class="btn-secondary" id="clearBtn">清空</button>
                <span class="small">依 <code>dialogues.text</code> 精準比對，符合的資料會把 <code>is_read</code> 更新成 1。</span>
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
