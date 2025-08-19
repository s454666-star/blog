<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>影片下載工具</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f6fa; }
        .container { margin-top: 60px; max-width: 800px; }
        .card { border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        #log-box { min-height: 120px; white-space: pre-wrap; background: #272822; color: #f8f8f2; padding: 10px; border-radius: 6px; font-family: monospace; overflow-y: auto; }
        video { max-width: 100%; margin-top: 20px; border-radius: 8px; }
    </style>
</head>
<body>
<div class="container">
    <div class="card p-4">
        <h2 class="mb-3">🎬 影片下載工具</h2>
        <div class="input-group mb-3">
            <input type="text" id="url-input" class="form-control" placeholder="輸入影片頁面 URL">
            <button class="btn btn-primary" id="fetch-btn">解析影片</button>
        </div>
        <div id="log-box">等待輸入 URL...</div>
        <div id="video-container" class="mt-4" style="display: none;">
            <h5>預覽影片：</h5>
            <video id="video-player" controls></video>
            <div class="mt-3">
                <a id="download-btn" class="btn btn-success" href="#">下載影片</a>
            </div>
        </div>
    </div>
</div>

<script>
    document.getElementById('fetch-btn').addEventListener('click', function () {
        const url = document.getElementById('url-input').value;
        const logBox = document.getElementById('log-box');
        const videoContainer = document.getElementById('video-container');
        const videoPlayer = document.getElementById('video-player');
        const downloadBtn = document.getElementById('download-btn');

        if (!url) {
            logBox.textContent = "⚠️ 請先輸入 URL";
            return;
        }

        logBox.textContent = "⏳ 正在解析中...";

        fetch("/fetch-url", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]').content
            },
            body: JSON.stringify({ url })
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    logBox.textContent = "✅ 找到影片連結:\n" + data.videoUrl + "\n\nLOG:\n" + (data.log ? data.log.join("\n---\n") : "");
                    videoContainer.style.display = "block";
                    videoPlayer.src = data.videoUrl;
                    downloadBtn.href = "/download?url=" + encodeURIComponent(data.videoUrl);
                } else {
                    logBox.textContent = "❌ 錯誤: " + data.error + "\n\nLOG:\n" + (data.log ? data.log.join("\n---\n") : "");
                    videoContainer.style.display = "none";
                }
            })
            .catch(err => {
                logBox.textContent = "❌ 請求失敗: " + err.message;
                videoContainer.style.display = "none";
            });
    });
</script>
</body>
</html>
