<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR Workspace</title>
    <style>
        :root {
            --page-bg:
                radial-gradient(circle at top left, rgba(255, 255, 255, 0.92), rgba(255, 255, 255, 0) 32%),
                linear-gradient(140deg, #f5efe2 0%, #e8f2fb 48%, #eef6ee 100%);
            --panel-bg: rgba(255, 255, 255, 0.82);
            --panel-border: rgba(102, 145, 190, 0.16);
            --panel-shadow: 0 22px 60px rgba(45, 80, 114, 0.12);
            --text-strong: #19324a;
            --text-muted: #5d7287;
            --line-soft: rgba(113, 145, 176, 0.18);
            --accent: #2583d8;
            --accent-deep: #1161a9;
            --accent-soft: rgba(37, 131, 216, 0.12);
            --success-bg: rgba(228, 245, 234, 0.92);
            --success-border: rgba(63, 130, 84, 0.18);
            --error-bg: rgba(251, 234, 231, 0.94);
            --error-border: rgba(186, 79, 67, 0.18);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", "Noto Sans TC", sans-serif;
            color: var(--text-strong);
            background: var(--page-bg);
            padding: 32px 18px 48px;
        }

        .workspace {
            max-width: 1180px;
            margin: 0 auto;
        }

        .hero {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(300px, .85fr);
            gap: 24px;
            align-items: stretch;
        }

        .panel {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 30px;
            box-shadow: var(--panel-shadow);
            backdrop-filter: blur(16px);
        }

        .hero-panel {
            padding: 34px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(82, 129, 174, 0.14);
            color: var(--accent-deep);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: linear-gradient(135deg, #61b0f3, #1a72bf);
            box-shadow: 0 0 0 5px rgba(37, 131, 216, 0.12);
        }

        h1 {
            margin: 20px 0 12px;
            font-size: clamp(2.4rem, 4vw, 4rem);
            line-height: .95;
            letter-spacing: -.04em;
        }

        .hero-copy {
            margin: 0;
            max-width: 34rem;
            font-size: 1.04rem;
            line-height: 1.8;
            color: var(--text-muted);
        }

        .hero-points {
            margin: 28px 0 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 12px;
        }

        .hero-points li {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #37566f;
            font-size: .97rem;
        }

        .hero-points li::before {
            content: "";
            flex: 0 0 10px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1f8ef1, #46c0e3);
        }

        .shortcut-card {
            padding: 30px 28px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .shortcut-card::before {
            content: "";
            position: absolute;
            inset: auto -12% 34% 48%;
            height: 220px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(37, 131, 216, 0.18), rgba(37, 131, 216, 0));
            pointer-events: none;
        }

        .shortcut-title {
            margin: 0 0 12px;
            font-size: 1.18rem;
            font-weight: 700;
        }

        .shortcut-copy {
            margin: 0;
            color: var(--text-muted);
            line-height: 1.8;
        }

        .shortcut-list {
            display: grid;
            gap: 12px;
            margin-top: 22px;
        }

        .shortcut-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.76);
            border: 1px solid rgba(104, 143, 180, 0.14);
        }

        .shortcut-label {
            font-size: .82rem;
            color: var(--text-muted);
        }

        .shortcut-value {
            font-size: .98rem;
            font-weight: 700;
        }

        .stage {
            margin-top: 26px;
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr);
            gap: 24px;
            align-items: start;
        }

        .composer {
            padding: 30px;
        }

        .composer-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 22px;
        }

        .composer-title {
            margin: 0;
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: -.03em;
        }

        .composer-subtitle {
            margin: 8px 0 0;
            color: var(--text-muted);
            line-height: 1.7;
        }

        .status-chip {
            flex: 0 0 auto;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid rgba(103, 144, 181, 0.14);
            color: var(--accent-deep);
            font-size: .85rem;
            font-weight: 700;
        }

        .dropzone {
            position: relative;
            min-height: 340px;
            border: 2px dashed rgba(37, 131, 216, .32);
            border-radius: 28px;
            background:
                radial-gradient(circle at top left, rgba(255, 255, 255, .96), rgba(255, 255, 255, 0) 38%),
                linear-gradient(145deg, rgba(236, 247, 255, .98), rgba(255, 255, 255, .94) 50%, rgba(242, 248, 242, .96));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .9);
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
            overflow: hidden;
        }

        .dropzone::before {
            content: "";
            position: absolute;
            inset: -25%;
            background: linear-gradient(115deg, rgba(255, 255, 255, 0) 30%, rgba(255, 255, 255, .45) 50%, rgba(255, 255, 255, 0) 70%);
            transform: translateX(-60%) rotate(14deg);
            animation: sweep 5s ease-in-out infinite;
            pointer-events: none;
        }

        .dropzone.is-active,
        .dropzone:hover,
        .dropzone:focus-within {
            transform: translateY(-1px);
            border-color: rgba(37, 131, 216, .62);
            box-shadow: 0 18px 42px rgba(28, 91, 143, .14), 0 0 0 5px rgba(37, 131, 216, .08);
        }

        .dropzone.has-preview::before {
            animation: none;
            opacity: 0;
        }

        @keyframes sweep {
            0%,
            100% {
                transform: translateX(-65%) rotate(14deg);
                opacity: .26;
            }
            50% {
                transform: translateX(66%) rotate(14deg);
                opacity: .84;
            }
        }

        .paste-target {
            min-height: 340px;
            width: 100%;
            border: 0;
            background: transparent;
            outline: 0;
            padding: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: text;
            position: relative;
            z-index: 1;
        }

        .paste-target[contenteditable="true"]:empty::before {
            content: attr(data-placeholder);
            white-space: pre-line;
            text-align: center;
            color: transparent;
        }

        .dropzone-copy {
            display: grid;
            gap: 14px;
            max-width: 470px;
            text-align: center;
            pointer-events: none;
        }

        .dropzone-kicker {
            display: inline-flex;
            margin: 0 auto;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .72);
            color: var(--accent-deep);
            font-size: .83rem;
            font-weight: 700;
        }

        .dropzone-kicker::before {
            content: "⌘";
            font-size: .92rem;
            opacity: .7;
        }

        .dropzone h2 {
            margin: 0;
            font-size: clamp(1.5rem, 2.3vw, 2.2rem);
            letter-spacing: -.04em;
            line-height: 1.05;
        }

        .dropzone p {
            margin: 0;
            color: var(--text-muted);
            line-height: 1.8;
            font-size: 1rem;
        }

        .preview-shell {
            display: none;
            width: 100%;
            gap: 18px;
            align-items: stretch;
        }

        .dropzone.has-preview .preview-shell {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 220px;
        }

        .dropzone.has-preview .dropzone-copy {
            display: none;
        }

        .preview-card {
            overflow: hidden;
            border-radius: 22px;
            background: rgba(255, 255, 255, .82);
            border: 1px solid rgba(116, 151, 182, .16);
            box-shadow: 0 16px 34px rgba(31, 83, 129, .12);
            min-height: 278px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .preview-image {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: linear-gradient(180deg, rgba(244, 248, 251, .95), rgba(231, 240, 246, .94));
        }

        .preview-meta {
            padding: 20px;
            border-radius: 22px;
            background: rgba(255, 255, 255, .78);
            border: 1px solid rgba(113, 146, 177, .16);
            display: grid;
            align-content: start;
            gap: 12px;
        }

        .preview-label {
            margin: 0;
            color: var(--text-muted);
            font-size: .82rem;
            letter-spacing: .08em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .preview-name {
            margin: 0;
            font-weight: 800;
            font-size: 1.02rem;
            line-height: 1.5;
            word-break: break-word;
        }

        .preview-tip {
            margin: 8px 0 0;
            font-size: .94rem;
            line-height: 1.7;
            color: var(--text-muted);
        }

        .preview-tip strong {
            color: var(--accent-deep);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
            align-items: center;
        }

        .file-input {
            display: none;
        }

        .action-button,
        .ghost-button {
            appearance: none;
            border: 0;
            cursor: pointer;
            border-radius: 16px;
            padding: 14px 20px;
            font-size: 1rem;
            font-weight: 700;
            transition: transform .18s ease, box-shadow .18s ease, background-color .18s ease;
        }

        .action-button {
            background: linear-gradient(135deg, #1f8ef1, #176fcb);
            color: #fff;
            box-shadow: 0 16px 30px rgba(28, 106, 184, .24);
        }

        .action-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 34px rgba(28, 106, 184, .3);
        }

        .action-button:disabled {
            cursor: not-allowed;
            opacity: .55;
            box-shadow: none;
        }

        .ghost-button {
            background: rgba(255, 255, 255, .82);
            color: var(--text-strong);
            border: 1px solid rgba(109, 145, 178, .18);
        }

        .ghost-button:hover {
            transform: translateY(-1px);
            background: rgba(255, 255, 255, .96);
        }

        .action-hint {
            color: var(--text-muted);
            font-size: .95rem;
        }

        .action-hint strong {
            color: var(--accent-deep);
        }

        .result-panel,
        .side-stack {
            display: grid;
            gap: 18px;
        }

        .message-card,
        .result-card,
        .info-card {
            padding: 24px;
        }

        .message-card {
            border-radius: 24px;
        }

        .message-card.error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
        }

        .message-card h3,
        .result-card h3,
        .info-card h3 {
            margin: 0 0 12px;
            font-size: 1.04rem;
        }

        .message-card p {
            margin: 0;
            line-height: 1.75;
            color: #7f3126;
        }

        .result-card {
            min-height: 350px;
        }

        .result-card pre {
            margin: 0;
            min-height: 260px;
            max-height: 560px;
            overflow: auto;
            padding: 18px;
            border-radius: 20px;
            background: rgba(240, 246, 250, .9);
            border: 1px solid rgba(111, 146, 177, .16);
            color: #22384c;
            white-space: pre-wrap;
            word-break: break-word;
            line-height: 1.7;
            font-family: Consolas, "SFMono-Regular", monospace;
            font-size: .94rem;
        }

        .empty-state {
            min-height: 302px;
            border-radius: 22px;
            display: grid;
            place-items: center;
            text-align: center;
            padding: 24px;
            background: linear-gradient(180deg, rgba(248, 251, 253, .9), rgba(237, 244, 248, .96));
            border: 1px dashed rgba(112, 145, 176, .2);
            color: var(--text-muted);
            line-height: 1.85;
        }

        .info-card {
            background: rgba(255, 255, 255, .74);
        }

        .info-card ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 12px;
        }

        .info-card li {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            color: #3f5d75;
            line-height: 1.7;
        }

        .info-card li::before {
            content: "";
            width: 9px;
            height: 9px;
            flex: 0 0 9px;
            margin-top: 9px;
            border-radius: 999px;
            background: linear-gradient(135deg, #2a9bf0, #6abed9);
        }

        .screen-reader {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        @media (max-width: 980px) {
            .hero,
            .stage {
                grid-template-columns: 1fr;
            }

            .dropzone.has-preview .preview-shell {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            body {
                padding: 16px 12px 32px;
            }

            .hero-panel,
            .composer,
            .message-card,
            .result-card,
            .info-card,
            .shortcut-card {
                padding: 22px;
            }

            .paste-target {
                min-height: 300px;
                padding: 20px;
            }

            .actions {
                align-items: stretch;
            }

            .action-button,
            .ghost-button {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<div class="workspace">
    <section class="hero">
        <div class="panel hero-panel">
            <span class="eyebrow">Vision OCR Workspace</span>
            <h1>貼上圖片，確認預覽，再送去辨識。</h1>
            <p class="hero-copy">
                這個頁面現在支援直接貼上剪貼簿圖片、拖曳圖片、或從本機選檔。
                貼上後只會先顯示預覽，不會立刻送出；按 <strong>Enter</strong> 或按下按鈕才會正式辨識。
            </p>
            <ul class="hero-points">
                <li>沿用你專案現有的圖片貼上互動模式</li>
                <li>預覽和結果分開，避免一貼上就誤送出</li>
                <li>保留傳統上傳按鈕，鍵盤操作也能直接用</li>
            </ul>
        </div>

        <aside class="panel shortcut-card">
            <div>
                <h2 class="shortcut-title">操作節奏</h2>
                <p class="shortcut-copy">比照影片頁的貼圖流程，但改成更適合單張 OCR 的版面。</p>
            </div>
            <div class="shortcut-list">
                <div class="shortcut-item">
                    <div>
                        <div class="shortcut-label">Step 1</div>
                        <div class="shortcut-value">Ctrl+V / 拖曳 / 選圖</div>
                    </div>
                </div>
                <div class="shortcut-item">
                    <div>
                        <div class="shortcut-label">Step 2</div>
                        <div class="shortcut-value">只先看預覽，不自動送出</div>
                    </div>
                </div>
                <div class="shortcut-item">
                    <div>
                        <div class="shortcut-label">Step 3</div>
                        <div class="shortcut-value">Enter 或按按鈕開始辨識</div>
                    </div>
                </div>
            </div>
        </aside>
    </section>

    <section class="stage">
        <div class="panel composer">
            <div class="composer-header">
                <div>
                    <h2 class="composer-title">圖片輸入</h2>
                    <p class="composer-subtitle">把焦點放進輸入區後直接貼上圖片。若已經有預覽，按 Enter 就會送出表單。</p>
                </div>
                <div class="status-chip" id="statusChip">等待圖片</div>
            </div>

            <form action="/ocr" method="post" enctype="multipart/form-data" id="ocrForm">
                @csrf
                <input class="file-input" type="file" name="image" id="imageInput" accept="image/*" required>

                <div class="dropzone" id="dropzone">
                    <div
                        class="paste-target"
                        id="pasteTarget"
                        contenteditable="true"
                        tabindex="0"
                        data-placeholder=""
                        aria-label="Paste image here"
                    >
                        <div class="dropzone-copy" id="dropzoneCopy">
                            <span class="dropzone-kicker">Paste Preview First</span>
                            <h2>直接貼圖到這裡，或把圖片拖進來。</h2>
                            <p>預覽準備好之後，按 <strong>Enter</strong> 才會送出。你也可以用下面的按鈕選擇檔案後再送出。</p>
                        </div>

                        <div class="preview-shell">
                            <div class="preview-card">
                                <img class="preview-image" id="previewImage" alt="OCR preview">
                            </div>
                            <div class="preview-meta">
                                <p class="preview-label">Preview</p>
                                <p class="preview-name" id="previewName">未選擇圖片</p>
                                <p class="preview-tip">
                                    圖片目前只在本頁預覽。<strong>按 Enter</strong> 或按右下按鈕後才會開始 OCR。
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="actions">
                    <label class="ghost-button" for="imageInput">選擇圖片</label>
                    <button type="button" class="ghost-button" id="clearButton">清除預覽</button>
                    <button type="submit" class="action-button" id="submitButton" disabled>Upload and Recognize Text</button>
                    <span class="action-hint" id="actionHint">先貼上或選一張圖，<strong>Enter</strong> 也能送出。</span>
                </div>
            </form>
        </div>

        <div class="side-stack">
            @if($errors->any())
                <div class="message-card error">
                    <h3>辨識失敗</h3>
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <div class="panel result-card">
                <h3>Recognized Text</h3>
                <pre id="resultText" @if(!session('text')) hidden @endif>{{ session('text') }}</pre>
                <div class="empty-state" id="resultEmpty" @if(session('text')) hidden @endif>
                    <div>
                        把圖片貼進左側區域後送出，這裡就會顯示 OCR 結果。<br>
                        若只是貼上但還沒按 Enter，這裡不會開始辨識。
                    </div>
                </div>
            </div>

            <div class="panel info-card">
                <h3>使用提示</h3>
                <ul>
                    <li>直接貼上時只會建立預覽，不會自動呼叫 OCR。</li>
                    <li>若你是用鍵盤操作，將焦點放在貼圖區後按 <strong>Ctrl+V</strong>，再按 <strong>Enter</strong> 即可送出。</li>
                    <li>如果改選別張圖，新的預覽會覆蓋舊的，不需要先重整頁面。</li>
                </ul>
            </div>
        </div>
    </section>
</div>

<script>
    (function () {
        const form = document.getElementById('ocrForm');
        const imageInput = document.getElementById('imageInput');
        const pasteTarget = document.getElementById('pasteTarget');
        const dropzone = document.getElementById('dropzone');
        const previewImage = document.getElementById('previewImage');
        const previewName = document.getElementById('previewName');
        const statusChip = document.getElementById('statusChip');
        const actionHint = document.getElementById('actionHint');
        const submitButton = document.getElementById('submitButton');
        const clearButton = document.getElementById('clearButton');
        const resultText = document.getElementById('resultText');
        const resultEmpty = document.getElementById('resultEmpty');
        const csrfTokenInput = form.querySelector('input[name="_token"]');

        let previewUrl = null;
        let pendingFile = null;
        let isSubmitting = false;

        function normalizeImageFile(file) {
            if (!file || !/^image\//.test(file.type || '')) {
                return null;
            }

            if (file.name && /\.[a-z0-9]+$/i.test(file.name)) {
                return file;
            }

            const ext = (file.type || 'image/png').split('/')[1] || 'png';

            return new File([file], `ocr-paste-${Date.now()}.${ext}`, {
                type: file.type || `image/${ext}`,
            });
        }

        function setPreview(file) {
            const normalized = normalizeImageFile(file);

            if (!normalized) {
                actionHint.innerHTML = '剪貼簿或拖曳內容裡沒有可用的圖片。';
                return false;
            }

            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            previewUrl = URL.createObjectURL(normalized);
            pendingFile = normalized;
            previewImage.src = previewUrl;
            previewName.textContent = normalized.name || 'clipboard-image.png';
            dropzone.classList.add('has-preview');
            submitButton.disabled = false;
            statusChip.textContent = '預覽已就緒';
            actionHint.innerHTML = '預覽完成。按 <strong>Enter</strong> 或按右側按鈕開始 OCR。';

            return true;
        }

        function clearPreview() {
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
                previewUrl = null;
            }

            pendingFile = null;
            imageInput.value = '';
            previewImage.removeAttribute('src');
            previewName.textContent = '未選擇圖片';
            dropzone.classList.remove('has-preview', 'is-active');
            submitButton.disabled = true;
            statusChip.textContent = '等待圖片';
            actionHint.innerHTML = '先貼上或選一張圖，<strong>Enter</strong> 也能送出。';
        }

        function submitIfReady() {
            if (!pendingFile || isSubmitting) {
                actionHint.innerHTML = '請先貼上、拖曳或選一張圖片，再送出。';
                return;
            }

            statusChip.textContent = '辨識中';
            form.dispatchEvent(new Event('submit', {cancelable: true, bubbles: true}));
        }

        function imageFilesFromItems(items) {
            return Array.from(items || [])
                .filter(item => item && item.kind === 'file' && /^image\//.test(item.type || ''))
                .map(item => item.getAsFile())
                .filter(Boolean);
        }

        imageInput.addEventListener('change', function () {
            if (imageInput.files && imageInput.files[0]) {
                setPreview(imageInput.files[0]);
                pasteTarget.focus();
            } else {
                clearPreview();
            }
        });

        clearButton.addEventListener('click', function () {
            clearPreview();
            pasteTarget.focus();
        });

        pasteTarget.addEventListener('click', function () {
            pasteTarget.focus();
        });

        pasteTarget.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                submitIfReady();
                return;
            }

            if (!event.ctrlKey && !event.metaKey && !['Tab', 'Shift', 'Control', 'Meta', 'Alt', 'Escape'].includes(event.key)) {
                event.preventDefault();
            }
        });

        pasteTarget.addEventListener('paste', function (event) {
            event.preventDefault();
            const files = imageFilesFromItems(event.clipboardData && event.clipboardData.items);
            setPreview(files[0]);
        });

        document.addEventListener('paste', function (event) {
            if (event.target === pasteTarget) {
                return;
            }

            const tagName = (event.target && event.target.tagName || '').toLowerCase();
            if (tagName === 'input' || tagName === 'textarea') {
                return;
            }

            const files = imageFilesFromItems(event.clipboardData && event.clipboardData.items);
            if (!files.length) {
                return;
            }

            event.preventDefault();
            setPreview(files[0]);
            pasteTarget.focus();
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-active');
            });
        });

        ['dragleave', 'dragend'].forEach(eventName => {
            dropzone.addEventListener(eventName, function () {
                dropzone.classList.remove('is-active');
            });
        });

        dropzone.addEventListener('drop', function (event) {
            event.preventDefault();
            dropzone.classList.remove('is-active');

            const file = Array.from(event.dataTransfer && event.dataTransfer.files || [])
                .find(item => /^image\//.test(item.type || ''));

            setPreview(file);
            pasteTarget.focus();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' || event.defaultPrevented) {
                return;
            }

            const tagName = (document.activeElement && document.activeElement.tagName || '').toLowerCase();
            if (tagName === 'button' || tagName === 'input' || tagName === 'textarea') {
                return;
            }

            if (pendingFile) {
                event.preventDefault();
                submitIfReady();
            }
        });

        form.addEventListener('submit', async function (event) {
            event.preventDefault();

            if (!pendingFile || isSubmitting) {
                actionHint.innerHTML = '請先貼上、拖曳或選一張圖片，再送出。';
                return;
            }

            isSubmitting = true;
            submitButton.disabled = true;
            submitButton.textContent = 'Recognizing...';
            statusChip.textContent = '辨識中';
            actionHint.innerHTML = '正在送出圖片並等待 OCR 結果...';

            const formData = new FormData();
            formData.append('_token', csrfTokenInput ? csrfTokenInput.value : '');
            formData.append('image', pendingFile, pendingFile.name || 'ocr-image.png');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfTokenInput ? csrfTokenInput.value : '',
                    },
                    body: formData,
                    credentials: 'same-origin',
                });

                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.error || payload.message || 'OCR request failed.');
                }

                resultText.hidden = false;
                resultEmpty.hidden = true;
                resultText.textContent = payload.text || 'No text found';
                statusChip.textContent = '辨識完成';
                actionHint.innerHTML = '辨識完成。你可以再貼一張新圖片覆蓋預覽。';
            } catch (error) {
                resultText.hidden = true;
                resultEmpty.hidden = false;
                statusChip.textContent = '辨識失敗';
                actionHint.innerHTML = error && error.message
                    ? error.message
                    : 'OCR 送出失敗，請稍後再試。';
            } finally {
                isSubmitting = false;
                submitButton.disabled = !pendingFile;
                submitButton.textContent = 'Upload and Recognize Text';
            }
        });
    }());
</script>
</body>
</html>
