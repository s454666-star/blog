<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>影片下載工具</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; padding: 30px; }
        .container { max-width: 800px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        input[type="text"], input[type="password"] { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 8px; }
        button { padding: 12px 20px; background: #007bff; color: white; border: none; border-radius: 8px; cursor: pointer; }
        button:hover { background: #0056b3; }
        pre { background: #212529; color: #f8f9fa; padding: 15px; border-radius: 8px; height: 300px; overflow-y: auto; white-space: pre-wrap; }
        video { width: 100%; margin-top: 20px; border-radius: 8px; }
        #download-btn { display: none; margin-top: 15px; text-decoration: none; background: #28a745; padding: 12px 20px; border-radius: 8px; color: white; }
        #download-btn:hover { background: #218838; }
        #session-box { display: none; }
        .row { display: flex; gap: 10px; align-items: center; }
        .row > * { flex: 1; }
        .hint { color: #6c757d; font-size: 13px; margin-top: 6px; }
    </style>
</head>
<body>
<div class="container">
    <h2>影片下載工具</h2>
    <div class="row">
        <input type="text" id="url" placeholder="輸入影片 URL (支援 YouTube, Instagram, Bilibili...)">
        <button id="fetch-btn">解析影片</button>
    </div>

    <div id="session-box">
        <input type="password" id="session" placeholder="請輸入 Instagram sessionid 或整串 Cookie（需含 sessionid=...）">
        <div class="row">
            <button id="save-session-btn">儲存 Session</button>
        </div>
        <div class="hint">提示：可直接貼 <code>sessionid=XXXX</code> 或整串 Cookie，我會自動擷取 <code>sessionid</code> 並轉為 Netscape 格式。</div>
    </div>

    <pre id="log"></pre>

    <div id="video-container" style="display:none;">
        <video id="video-player" controls></video>
        <button id="download-btn">⬇️ 下載影片 (含聲音)</button>
    </div>
</div>

<script>
    const hasSession = {{ $hasSession ? 'true' : 'false' }};
    const sessionBox = document.getElementById("session-box");
    const sessionInput = document.getElementById("session");
    const saveSessionBtn = document.getElementById("save-session-btn");
    const urlInput = document.getElementById("url");
    const fetchBtn = document.getElementById("fetch-btn");
    const logBox = document.getElementById("log");
    const videoPlayer = document.getElementById("video-player");
    const videoContainer = document.getElementById("video-container");
    const downloadBtn = document.getElementById("download-btn");
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    // 初始：如果伺服器端驗證 cookie 檔無效，就顯示輸入框（只影響 IG）
    if (!hasSession) {
        sessionBox.style.display = "block";
    }

    let currentVideoUrl = null;
    let originalInputUrl = null;

    function isInstagramUrl(u) {
        try {
            const host = new URL(u).hostname.toLowerCase();
            return host.includes('instagram.com');
        } catch (e) {
            return false;
        }
    }

    function appendLog(msg) {
        logBox.textContent += msg + "\n";
        logBox.scrollTop = logBox.scrollHeight;
    }

    saveSessionBtn.addEventListener("click", () => {
        const session = sessionInput.value.trim();
        if (!session) {
            logBox.textContent = "❌ sessionid 不能為空";
            return;
        }
        fetch("/save-session", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
            body: JSON.stringify({ session })
        })
            .then(async (res) => {
                const ct = res.headers.get("content-type") || "";
                if (!ct.includes("application/json")) {
                    throw new Error("伺服器未回傳 JSON");
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    logBox.textContent = "✅ Session 已儲存 (Netscape 格式)";
                    sessionBox.style.display = "none";
                } else {
                    logBox.textContent = "❌ " + (data.error || "儲存失敗");
                }
            })
            .catch(err => {
                logBox.textContent = "❌ 發生錯誤: " + err.message;
            });
    });

    fetchBtn.addEventListener("click", () => {
        const url = urlInput.value.trim();
        if (!url) {
            logBox.textContent = "❌ 請先輸入網址";
            return;
        }

        originalInputUrl = url;
        logBox.textContent = "🔍 開始解析中...\n";
        videoContainer.style.display = "none";
        downloadBtn.style.display = "none";
        currentVideoUrl = null;

        // 若是 IG 且尚未有 session，主動顯示輸入框
        if (isInstagramUrl(url) && sessionBox.style.display === "none" && !hasSession) {
            sessionBox.style.display = "block";
        }

        // 設定 90 秒逾時，避免永遠卡住
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 90000);

        fetch("/fetch-url", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
            body: JSON.stringify({ url }),
            signal: controller.signal
        })
            .then(async (res) => {
                clearTimeout(timer);
                const ct = res.headers.get("content-type") || "";
                if (!ct.includes("application/json")) {
                    const txt = await res.text();
                    throw new Error("伺服器未回傳 JSON，狀態碼 " + res.status + "，內容：" + txt.slice(0, 200));
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    logBox.textContent = "✅ 找到影片直連：\n" + data.urls.join("\n");
                    currentVideoUrl = data.urls[0];
                    videoPlayer.src = currentVideoUrl;
                    videoContainer.style.display = "block";
                    downloadBtn.style.display = "inline-block";
                } else {
                    logBox.textContent = "❌ 錯誤：\n" + (data.error || "解析失敗");
                    if (data.needSession) {
                        sessionBox.style.display = "block";
                        appendLog("ℹ️ 請輸入 Instagram sessionid 後再試一次。");
                    }
                }
            })
            .catch(err => {
                clearTimeout(timer);
                if (err.name === "AbortError") {
                    logBox.textContent = "⏱️ 解析逾時，請稍後重試或確認網址。";
                } else {
                    logBox.textContent = "❌ 發生錯誤: " + err.message;
                }
            });
    });

    downloadBtn.addEventListener("click", () => {
        if (!originalInputUrl) {
            logBox.textContent = "❌ 請先輸入網址並解析";
            return;
        }
        window.location.href = "/download?url=" + encodeURIComponent(originalInputUrl);
    });
</script>
</body>
</html>
