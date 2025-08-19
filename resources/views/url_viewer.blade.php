<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>影片下載工具</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; padding: 30px; }
        .container { max-width: 860px; margin: auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        input[type="text"], input[type="password"], textarea { width: 100%; padding: 12px; margin: 10px 0; border: 1px solid #ccc; border-radius: 8px; }
        button { padding: 12px 20px; background: #007bff; color: white; border: none; border-radius: 8px; cursor: pointer; }
        button:hover { background: #0056b3; }
        pre { background: #212529; color: #f8f9fa; padding: 15px; border-radius: 8px; height: 320px; overflow-y: auto; white-space: pre-wrap; }
        video { width: 100%; margin-top: 20px; border-radius: 8px; }
        #download-btn { display: none; margin-top: 15px; text-decoration: none; background: #28a745; padding: 12px 20px; border-radius: 8px; color: white; }
        #download-btn:hover { background: #218838; }
        #session-box { display: none; }
        .row { display: flex; gap: 10px; align-items: center; }
        .row > * { flex: 1; }
        .hint { color: #6c757d; font-size: 13px; margin-top: 6px; }
        .radio { display: flex; gap: 14px; align-items: center; margin-top: 6px; }
        label small { color: #6c757d; }
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
        <div class="radio">
            <label><input type="radio" name="cookie-site" value="ig" checked> Instagram</label>
            <label><input type="radio" name="cookie-site" value="yt"> YouTube</label>
        </div>

        <div id="ig-inputs">
            <input type="password" id="session-ig" placeholder="請輸入 Instagram sessionid 或含 sessionid=... 的 Cookie 片段">
            <div class="hint">會自動擷取 <code>sessionid</code> 並轉成 Netscape 格式。</div>
        </div>

        <div id="yt-inputs" style="display:none;">
            <textarea id="session-yt" rows="4" placeholder="請貼上 YouTube Cookies（name=value; name2=value2; ...）"></textarea>
            <div class="hint">建議用瀏覽器外掛（如 Cookie-Editor）匯出或複製當前 <code>youtube.com</code> 的 Cookie。將轉為 Netscape 格式儲存。</div>
        </div>

        <div class="row">
            <button id="save-session-btn">儲存 Cookie</button>
        </div>
    </div>

    <pre id="log"></pre>

    <div id="video-container" style="display:none;">
        <video id="video-player" controls></video>
        <button id="download-btn">⬇️ 下載影片 (含聲音)</button>
    </div>
</div>

<script>
    const hasIG = {{ $hasSession ? 'true' : 'false' }};
    const hasYT = {{ isset($hasYTCookie) && $hasYTCookie ? 'true' : 'false' }};

    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    const sessionBox = document.getElementById("session-box");
    const urlInput = document.getElementById("url");
    const fetchBtn = document.getElementById("fetch-btn");
    const logBox = document.getElementById("log");
    const videoPlayer = document.getElementById("video-player");
    const videoContainer = document.getElementById("video-container");
    const downloadBtn = document.getElementById("download-btn");

    const saveSessionBtn = document.getElementById("save-session-btn");
    const igInputs = document.getElementById("ig-inputs");
    const ytInputs = document.getElementById("yt-inputs");
    const sessionIG = document.getElementById("session-ig");
    const sessionYT = document.getElementById("session-yt");

    const radios = document.getElementsByName("cookie-site");

    // 初始：若 IG Cookie 無效，先露出輸入框（預設 IG）
    if (!hasIG) {
        sessionBox.style.display = "block";
        setSite('ig');
    }

    function setSite(site) {
        for (const r of radios) r.checked = (r.value === site);
        if (site === 'ig') {
            igInputs.style.display = "";
            ytInputs.style.display = "none";
        } else {
            igInputs.style.display = "none";
            ytInputs.style.display = "";
        }
    }

    for (const r of radios) {
        r.addEventListener('change', () => setSite(document.querySelector('input[name="cookie-site"]:checked').value));
    }

    function appendLog(msg) {
        logBox.textContent += msg + "\n";
        logBox.scrollTop = logBox.scrollHeight;
    }

    function isInstagramUrl(u) {
        try { return new URL(u).hostname.toLowerCase().includes('instagram.com'); } catch { return false; }
    }
    function isYouTubeUrl(u) {
        try {
            const h = new URL(u).hostname.toLowerCase();
            return h.includes('youtube.com') || h.includes('youtu.be');
        } catch { return false; }
    }

    saveSessionBtn.addEventListener("click", () => {
        const site = document.querySelector('input[name="cookie-site"]:checked').value;
        const session = site === 'ig' ? sessionIG.value.trim() : sessionYT.value.trim();

        if (!session) {
            logBox.textContent = "❌ 輸入不能為空";
            return;
        }

        fetch("/save-session", {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
            body: JSON.stringify({ site, session })
        })
            .then(async (res) => {
                const ct = res.headers.get("content-type") || "";
                if (!ct.includes("application/json")) {
                    const txt = await res.text();
                    throw new Error("伺服器未回傳 JSON：" + txt.slice(0, 200));
                }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    logBox.textContent = "✅ " + data.message;
                    // 若是 IG，成功後可隱藏；YouTube 視需求保留
                    if (site === 'ig') sessionBox.style.display = "none";
                } else {
                    logBox.textContent = "❌ " + (data.error || "儲存失敗");
                }
            })
            .catch(err => {
                logBox.textContent = "❌ 發生錯誤: " + err.message;
            });
    });

    let originalInputUrl = null;

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
        videoPlayer.removeAttribute('src');

        // 若是 IG 且目前沒有 IG Cookie，主動顯示 IG 欄
        if (isInstagramUrl(url) && !hasIG) {
            sessionBox.style.display = "block";
            setSite('ig');
        }

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 130000); // 130 秒避免卡住（含重試）

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
                    videoPlayer.src = data.urls[0];
                    videoContainer.style.display = "block";
                    downloadBtn.style.display = "inline-block";
                } else {
                    logBox.textContent = "❌ 錯誤：\n" + (data.error || "解析失敗");
                    // 若為 YouTube 且伺服器提示需要 Cookie，顯示 YT Cookie 輸入
                    if (data.needYTCookie || (isYouTubeUrl(url) && /429|confirm you.?re not a bot/i.test(data.error || ''))) {
                        sessionBox.style.display = "block";
                        setSite('yt');
                        appendLog("ℹ️ YouTube 可能觸發頻率限制/驗證，請在上方選擇 YouTube 並貼上 cookies 後重試。");
                    }
                    if (data.needSession) {
                        sessionBox.style.display = "block";
                        setSite('ig');
                        appendLog("ℹ️ Instagram 需要 sessionid，請輸入後重試。");
                    }
                }
            })
            .catch(err => {
                clearTimeout(timer);
                if (err.name === "AbortError") {
                    logBox.textContent = "⏱️ 解析逾時，請稍後重試或提供 YouTube Cookies。";
                    if (isYouTubeUrl(url)) {
                        sessionBox.style.display = "block";
                        setSite('yt');
                    }
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
