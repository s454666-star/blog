<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>文字過濾與存儲</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 8px 16px rgba(0,0,0,0.2);
            animation: fadeInDown 0.5s ease-out;
        }
        .btn-custom {
            background: linear-gradient(45deg, #f093fb, #f5576c);
            color: white;
            transition: transform .2s, box-shadow .2s;
        }
        .btn-custom:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .textarea-custom {
            border-radius: .5rem;
            padding: 1rem;
            resize: vertical;
            height: 70vh;
            overflow-y: auto;
        }

        /* 自訂的淡入動畫 */
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
<div class="container py-5" style="max-width: 800px;">
    <div class="card">
        <div class="card-body">
            <h3 class="text-center mb-4">文字過濾與存儲</h3>
            <form id="filterForm" method="post" action="{{ route('extract.process') }}">
                @csrf
                <div class="mb-3">
            <textarea name="text"
                      id="inputText"
                      class="form-control textarea-custom"
                      placeholder="請貼上含目標字串的文字後按下「開始掃描」或 Enter"
            ></textarea>
                </div>
                <div class="d-flex justify-content-center gap-3">
                    <button type="submit"
                            class="btn btn-custom">
                        開始掃描
                    </button>
                    <button type="button"
                            id="clearBtn"
                            class="btn btn-secondary">
                        清除
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if(!empty($codes))
        <div class="card mt-4" style="animation: zoomIn 0.4s ease-out;">
            <div class="card-body">
                <h5>掃描結果：</h5>
                <textarea id="resultText"
                          class="form-control"
                          rows="5"
                          readonly
                >{{ implode("\n", $codes) }}</textarea>
                <div class="mt-2 d-flex gap-2">
                    <button id="copyBtn" class="btn btn-success">複製結果</button>
                    <button id="clearResultBtn" class="btn btn-warning">清空顯示</button>
                </div>
            </div>
        </div>
    @endif
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // 按 Enter 即送出（不含 Shift+Enter）
    $('#inputText').on('keypress', function(e){
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $('#filterForm').submit();
        }
    });
    // 複製結果
    $('#copyBtn').click(function(){
        const el = document.getElementById('resultText');
        el.select();
        document.execCommand('copy');
    });
    // 清除結果區與輸入框
    $('#clearResultBtn').click(function(){
        $('#resultText').val('');
    });
    $('#clearBtn').click(function(){
        $('#inputText').val('');
        $('#resultText').val('');
    });
</script>
</body>
</html>
