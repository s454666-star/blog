<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>影片下載工具</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; padding: 30px; }
        .container { max-width: 800px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        input[type="text"], input[type="password"] { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 8px; }
        button { padding: 12px 20px; background: #007bff; color: white; border: none; border-radius: 8px; cursor: pointer; }
        button:hover { background: #0056b3; }
        pre { background: #212529; color: #f8f9fa; padding: 15px; border-radius: 8px; height: 300px; overflow-y: auto; }
        video { width: 100%; margin-top: 20px; border-radius: 8px; }
        #download-btn { display: none; margin-top: 15px; text-decoration: none; background: #28a745; padding: 12px 20px; border-radius: 8px; color: white; }
        #download-btn:hover { background: #218838; }
        #session-box { display: none; }
    </style>
</head>
<body>
<div class="container">
    <h2>影片下載工具</h2>
    <input type="text" id="url" placeholder="輸入影片 URL (支援 YouTube, Instagram, Bilibili...)">
    <div id="session-box">
        <input type="password" id="session" placeholder="請輸入 Instagram sessionid">
        <button id="save-session-btn">儲存 Session</button>
    </div>
    <button id="fetch-btn">解析影片</button>
    <pre id="log"></pre>
    <div id="video-container" style="display:none;">
        <video id="video-player" controls></video>
        <button id="download-btn">⬇️ 下載影片</button>
    </div>
</div>

<script>
    const hasSession = {{ $hasSession ? 'true' : 'false' }};
    const sessionBox = document.getElementById("session-box");
    if (!hasSession) {
        sessionBox.style.display = "block";
    }

    const sessionInput = document.getElementById("session");
    const saveSessionBtn = document.getElementById("save-session-btn");
    const urlInput = document.getElementById("url");
    const fetchBtn = document.getElementById("fetch-btn");
    const logBox = document.getElementById("log");
    const videoPlayer = document.getElementById("video-player");
    const videoContainer = document.getElementById("video-container");
    const downloadBtn = document.getElementById("download-btn");

    let currentVideoUrl = null; // ✅ 記錄影片連結

    saveSessionBtn.addEventListener("click", () => {
        const session = sessionInput.value;
        fetch("/save-session", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": "{{ csrf_token() }}" },
            body: JSON.stringify({ session })
        })
            .then(res => res.json())
            .then(data => {
                logBox.textContent = data.success ? "✅ " + data.message : "❌ " + data.error;
                if (data.success) sessionBox.style.display = "none";
            })
            .catch(err => {
                logBox.textContent = "❌ 發生錯誤: " + err;
            });
    });

    fetchBtn.addEventListener("click", () => {
        const url = urlInput.value;
        logBox.textContent = "🔍 開始解析中...\n";
        videoContainer.style.display = "none";

        fetch("/fetch-url", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": "{{ csrf_token() }}" },
            body: JSON.stringify({ url })
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    logBox.textContent = "✅ 找到影片連結:\n" + data.videoUrl +
                        "\n\nLOG:\n" + (data.log ? data.log.join("\n---\n") : "");

                    currentVideoUrl = data.videoUrl; // ✅ 保存連結
                    videoContainer.style.display = "block";
                    videoPlayer.src = data.videoUrl;
                    downloadBtn.style.display = "inline-block";
                } else {
                    logBox.textContent = "❌ 錯誤: " + data.error + "\n\nLOG:\n" + (data.log ? data.log.join("\n---\n") : "");
                    if (data.needSession) {
                        sessionBox.style.display = "block";
                    }
                }
            })
            .catch(err => {
                logBox.textContent = "❌ 發生錯誤: " + err;
            });
    });

    // ✅ 修正下載：用 blob + 當下日期時間命名
    downloadBtn.addEventListener("click", async () => {
        if (!currentVideoUrl) return;

        try {
            const res = await fetch(currentVideoUrl);
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);

            const a = document.createElement("a");
            a.href = url;

            // 產生檔名：yyyyMMdd_HHmmss.mp4
            const now = new Date();
            const filename = now.getFullYear().toString()
                + String(now.getMonth()+1).padStart(2,"0")
                + String(now.getDate()).padStart(2,"0") + "_"
                + String(now.getHours()).padStart(2,"0")
                + String(now.getMinutes()).padStart(2,"0")
                + String(now.getSeconds()).padStart(2,"0") + ".mp4";

            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);

            URL.revokeObjectURL(url);
        } catch (err) {
            logBox.textContent = "❌ 下載失敗: " + err;
        }
    });
</script>
</body>
</html>
