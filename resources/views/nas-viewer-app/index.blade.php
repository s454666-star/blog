<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#07111f">
    <title>NAS Viewer</title>
    <link rel="icon" href="/nas-viewer-assets/icon-192.png">
    <style>
        :root {
            color-scheme: dark;
            --top-inset: 0px;
            --bottom-inset: 0px;
            --bg: #06101d;
            --panel: #0c1929;
            --panel-strong: #12253a;
            --line: rgba(148, 184, 226, .14);
            --text: #eef7ff;
            --muted: #8fa8bf;
            --accent: #36d7ff;
            --accent-2: #7c6cff;
            --danger: #ff758f;
        }

        * {
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            margin: 0;
            overflow: hidden;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            position: fixed;
            inset: 0;
            overscroll-behavior: none;
        }

        button {
            color: inherit;
            font: inherit;
        }

        button:focus-visible {
            outline: 4px solid #54e8ff;
            outline-offset: 3px;
            box-shadow: 0 0 0 7px rgba(84, 232, 255, .22);
        }

        #app {
            position: absolute;
            inset: 0;
            display: grid;
            grid-template-rows: auto auto minmax(0, 1fr);
            padding-bottom: var(--bottom-inset);
            background:
                radial-gradient(circle at 16% 0%, rgba(52, 211, 255, .13), transparent 32%),
                radial-gradient(circle at 100% 4%, rgba(124, 108, 255, .14), transparent 35%),
                var(--bg);
        }

        .app-header {
            display: grid;
            grid-template-columns: 44px minmax(0, 1fr) 44px;
            align-items: center;
            min-height: 62px;
            padding: 8px 10px;
            border-bottom: 1px solid var(--line);
            background: rgba(6, 16, 29, .82);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            z-index: 3;
        }

        .icon-button {
            width: 42px;
            height: 42px;
            border: 0;
            border-radius: 13px;
            background: rgba(147, 186, 227, .08);
            display: grid;
            place-items: center;
            font-size: 23px;
            cursor: pointer;
        }

        .icon-button:disabled {
            opacity: .24;
            cursor: default;
        }

        .title-block {
            min-width: 0;
            padding: 0 10px;
            text-align: center;
        }

        .title-block h1 {
            margin: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 19px;
            line-height: 1.25;
        }

        .title-block p {
            margin: 3px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .breadcrumbs {
            display: flex;
            gap: 6px;
            min-height: 43px;
            padding: 7px 12px;
            overflow-x: auto;
            border-bottom: 1px solid var(--line);
            scrollbar-width: none;
        }

        .breadcrumbs::-webkit-scrollbar {
            display: none;
        }

        .crumb {
            flex: 0 0 auto;
            max-width: 190px;
            height: 28px;
            padding: 0 11px;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 999px;
            color: var(--muted);
            background: rgba(113, 158, 204, .06);
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
        }

        .crumb.current {
            color: #07111f;
            border-color: transparent;
            background: linear-gradient(135deg, var(--accent), #80ecff);
            font-weight: 750;
        }

        .list-shell {
            position: relative;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 10px 10px calc(86px + var(--bottom-inset));
        }

        .entry-list {
            display: grid;
            gap: 7px;
        }

        .entry {
            width: 100%;
            min-height: 67px;
            display: grid;
            grid-template-columns: 49px minmax(0, 1fr) auto;
            align-items: center;
            gap: 10px;
            padding: 8px 11px;
            border: 1px solid var(--line);
            border-radius: 16px;
            color: var(--text);
            background: linear-gradient(145deg, rgba(18, 37, 58, .93), rgba(9, 24, 40, .93));
            box-shadow: 0 9px 28px rgba(0, 0, 0, .13);
            text-align: left;
            cursor: pointer;
            transition: border-color 150ms ease, transform 150ms ease, background 150ms ease;
        }

        .entry:active {
            transform: scale(.988);
        }

        .entry.selected {
            border-color: rgba(54, 215, 255, .92);
            background: linear-gradient(145deg, rgba(23, 61, 84, .98), rgba(13, 38, 64, .98));
            box-shadow: 0 0 0 2px rgba(54, 215, 255, .12), 0 12px 34px rgba(0, 0, 0, .2);
        }

        .entry.offline {
            opacity: .48;
        }

        .entry-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: rgba(72, 124, 177, .12);
            font-size: 26px;
        }

        .entry.selected .entry-icon {
            background: rgba(54, 215, 255, .14);
        }

        .entry-copy {
            min-width: 0;
        }

        .entry-name {
            display: block;
            overflow: hidden;
            color: #f4f9ff;
            font-size: 15px;
            font-weight: 670;
            line-height: 1.32;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .entry-meta {
            display: block;
            margin-top: 5px;
            overflow: hidden;
            color: var(--muted);
            font-size: 12px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .entry-action {
            min-width: 24px;
            color: rgba(205, 231, 255, .62);
            font-size: 21px;
            text-align: center;
        }

        .empty-state,
        .loading-state {
            min-height: 52vh;
            display: grid;
            place-items: center;
            padding: 28px;
            color: var(--muted);
            text-align: center;
            line-height: 1.7;
        }

        .load-more {
            width: 100%;
            height: 48px;
            margin-top: 9px;
            border: 1px solid rgba(54, 215, 255, .26);
            border-radius: 14px;
            color: var(--accent);
            background: rgba(54, 215, 255, .07);
            cursor: pointer;
        }

        .toast {
            position: fixed;
            left: 50%;
            bottom: calc(20px + var(--bottom-inset));
            z-index: 80;
            max-width: calc(100vw - 30px);
            padding: 10px 15px;
            border: 1px solid rgba(159, 205, 242, .18);
            border-radius: 999px;
            color: #ecf8ff;
            background: rgba(7, 19, 33, .9);
            box-shadow: 0 12px 35px rgba(0, 0, 0, .34);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            opacity: 0;
            transform: translate(-50%, 12px);
            transition: opacity 180ms ease, transform 180ms ease;
            pointer-events: none;
            text-align: center;
        }

        .toast.visible {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        .viewer {
            position: fixed;
            inset: 0;
            z-index: 30;
            display: none;
            grid-template-rows: auto minmax(0, 1fr);
            padding-bottom: var(--bottom-inset);
            background: #03070d;
        }

        .viewer.open {
            display: grid;
        }

        .viewer.video-mode {
            grid-template-rows: minmax(0, 1fr);
            padding-bottom: 0;
            background: #000;
        }

        .viewer.video-mode .viewer-header {
            display: none;
        }

        .viewer-header {
            min-height: 58px;
            display: grid;
            grid-template-columns: 45px minmax(0, 1fr) 45px;
            align-items: center;
            gap: 8px;
            padding: 7px 10px;
            border-bottom: 1px solid rgba(255, 255, 255, .1);
            background: rgba(4, 10, 18, .94);
        }

        .viewer-title {
            overflow: hidden;
            font-size: 15px;
            font-weight: 700;
            text-align: center;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .viewer-content {
            position: relative;
            min-height: 0;
            display: grid;
            place-items: center;
            overflow: hidden;
            background: #02050a;
        }

        .viewer.video-mode .viewer-content {
            touch-action: none;
            background: #000;
        }

        .viewer.image-mode .viewer-content {
            touch-action: none;
        }

        html.nas-viewer-tv .viewer.image-mode {
            grid-template-rows: minmax(0, 1fr);
            width: 100vw;
            height: 100vh;
            padding: 0;
        }

        html.nas-viewer-tv .viewer.image-mode .viewer-header {
            display: none;
        }

        html.nas-viewer-tv .viewer.image-mode .viewer-content {
            width: 100vw;
            height: 100vh;
            min-width: 0;
            min-height: 0;
            max-width: 100vw;
            max-height: 100vh;
        }

        html.nas-viewer-tv .viewer.image-mode .image-viewer.active {
            position: absolute;
            inset: 0;
            width: 100vw;
            height: 100vh;
            min-width: 0;
            min-height: 0;
            max-width: 100vw;
            max-height: 100vh;
            margin: auto;
            object-fit: contain;
            object-position: center;
        }

        .viewer-content video,
        .viewer-content img {
            display: none;
            width: 100%;
            height: 100%;
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            background: #000;
        }

        .viewer-content video.active,
        .viewer-content img.active {
            display: block;
        }

        .video-overlay-controls {
            position: absolute;
            inset: 0;
            z-index: 4;
            display: none;
            pointer-events: none;
        }

        .viewer.video-mode .video-overlay-controls {
            display: block;
        }

        .video-back-button,
        .video-skip-button {
            position: absolute;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 999px;
            color: #fff;
            background: rgba(0, 0, 0, .42);
            box-shadow: 0 8px 26px rgba(0, 0, 0, .35);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            pointer-events: auto;
        }

        .video-back-button {
            top: calc(env(safe-area-inset-top) + 10px);
            left: 10px;
            width: 46px;
            height: 46px;
            font-size: 34px;
            line-height: 1;
        }

        .video-skip-button {
            top: 50%;
            width: 70px;
            height: 70px;
            padding: 0;
            transform: translateY(-50%);
            font-size: 15px;
            font-weight: 800;
        }

        .video-skip-button.rewind {
            left: max(18px, 10vw);
        }

        .video-skip-button.forward {
            right: max(18px, 10vw);
        }

        .video-skip-button span {
            display: block;
            font-size: 23px;
            line-height: .9;
        }

        .video-seek-flash {
            position: absolute;
            top: 50%;
            left: 50%;
            z-index: 6;
            display: grid;
            min-width: 94px;
            min-height: 94px;
            padding: 12px;
            place-items: center;
            border-radius: 999px;
            color: #fff;
            background: rgba(0, 0, 0, .6);
            font-size: 21px;
            font-weight: 850;
            opacity: 0;
            transform: translate(-50%, -50%) scale(.92);
            transition: opacity .14s ease, transform .14s ease;
            pointer-events: none;
        }

        .video-seek-flash.visible {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }

        .text-viewer {
            display: none;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 18px 16px calc(28px + var(--bottom-inset));
            overflow: auto;
            color: #dceeff;
            background: #07111d;
            font: 13px/1.62 ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            tab-size: 4;
            user-select: text;
            -webkit-user-select: text;
        }

        .text-viewer.active {
            display: block;
        }

        .viewer-message {
            max-width: 78%;
            color: var(--muted);
            line-height: 1.7;
            text-align: center;
        }

        @media (min-width: 760px) {
            .entry-list {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<div id="app">
    <header class="app-header">
        <button id="back-button" class="icon-button" type="button" aria-label="上一頁">‹</button>
        <div class="title-block">
            <h1 id="directory-title">NAS</h1>
            <p id="directory-summary">點一下即可開啟</p>
        </div>
        <button id="refresh-button" class="icon-button" type="button" aria-label="重新整理">↻</button>
    </header>
    <nav id="breadcrumbs" class="breadcrumbs" aria-label="目前路徑"></nav>
    <main id="list-shell" class="list-shell">
        <div id="entry-list" class="entry-list"></div>
        <button id="load-more" class="load-more" type="button" hidden>載入更多</button>
        <div id="empty-state" class="empty-state" hidden></div>
    </main>
</div>

<div id="viewer" class="viewer" aria-hidden="true">
    <header class="viewer-header">
        <button id="viewer-back" class="icon-button" type="button" aria-label="回清單">‹</button>
        <div id="viewer-title" class="viewer-title"></div>
        <button id="viewer-close" class="icon-button" type="button" aria-label="關閉">×</button>
    </header>
    <div class="viewer-content">
        <video id="video-viewer" controls playsinline preload="metadata"></video>
        <img id="image-viewer" alt="">
        <pre id="text-viewer" class="text-viewer"></pre>
        <div id="viewer-message" class="viewer-message"></div>
        <div id="video-overlay-controls" class="video-overlay-controls" aria-hidden="true">
            <button id="video-back" class="video-back-button" type="button" aria-label="回清單">‹</button>
            <button id="video-rewind" class="video-skip-button rewind" type="button" aria-label="倒退 10 秒"><span>↶</span>10 秒</button>
            <button id="video-forward" class="video-skip-button forward" type="button" aria-label="快進 10 秒"><span>↷</span>10 秒</button>
        </div>
        <div id="video-seek-flash" class="video-seek-flash"></div>
    </div>
</div>

<div id="toast" class="toast" role="status" aria-live="polite"></div>

<script>
(() => {
    'use strict';

    const isNasViewerTvApp = /Android/i.test(navigator.userAgent || '')
        && /NasViewerTvApp\//.test(navigator.userAgent || '');
    document.documentElement.classList.toggle('nas-viewer-tv', isNasViewerTvApp);

    const config = @json($appConfig);
    const VIDEO_HOLD_SEEK_MS = 260;
    const VIDEO_DRAG_SEEK_STEP_PX = 42;
    const VIDEO_DRAG_SEEK_RATIO = .05;
    const elements = {
        back: document.getElementById('back-button'),
        refresh: document.getElementById('refresh-button'),
        title: document.getElementById('directory-title'),
        summary: document.getElementById('directory-summary'),
        breadcrumbs: document.getElementById('breadcrumbs'),
        list: document.getElementById('entry-list'),
        listShell: document.getElementById('list-shell'),
        loadMore: document.getElementById('load-more'),
        empty: document.getElementById('empty-state'),
        viewer: document.getElementById('viewer'),
        viewerTitle: document.getElementById('viewer-title'),
        viewerBack: document.getElementById('viewer-back'),
        viewerClose: document.getElementById('viewer-close'),
        video: document.getElementById('video-viewer'),
        image: document.getElementById('image-viewer'),
        text: document.getElementById('text-viewer'),
        viewerMessage: document.getElementById('viewer-message'),
        viewerContent: document.querySelector('.viewer-content'),
        videoOverlayControls: document.getElementById('video-overlay-controls'),
        videoBack: document.getElementById('video-back'),
        videoRewind: document.getElementById('video-rewind'),
        videoForward: document.getElementById('video-forward'),
        videoSeekFlash: document.getElementById('video-seek-flash'),
        toast: document.getElementById('toast'),
    };
    const state = {
        directoryId: null,
        entries: [],
        meta: null,
        selectedId: null,
        navigationStack: [],
        loading: false,
        viewerEntry: null,
        viewerSwitching: false,
        requestToken: 0,
        toastTimer: null,
        videoSeekFlashTimer: null,
        tvFocusIndex: 0,
        lastViewerSwitchDelta: 1,
    };

    let stopVideoDragSeek = () => {};
    const failedViewerEntryIds = new Set();

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function iconFor(entry) {
        if (entry.kind === 'directory') return entry.available === false ? '☁' : '📁';
        if (entry.kind === 'video') return '🎬';
        if (entry.kind === 'image') return '🖼️';
        if (entry.kind === 'text') return '📄';
        if (entry.kind === 'apk') return '🤖';
        return '📦';
    }

    function actionFor(entry) {
        if (entry.kind === 'directory') return '›';
        if (entry.kind === 'video') return '▶';
        if (entry.kind === 'image' || entry.kind === 'text') return '⌕';
        if (entry.kind === 'apk') return '⇩';
        return '—';
    }

    function formatBytes(value) {
        if (value === null || value === undefined || value === '') return '';
        const bytes = Number(value);
        if (!Number.isFinite(bytes) || bytes < 0) return '';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let size = bytes;
        let unit = 0;
        while (size >= 1024 && unit < units.length - 1) {
            size /= 1024;
            unit += 1;
        }
        return `${size >= 100 || unit === 0 ? Math.round(size) : size.toFixed(1)} ${units[unit]}`;
    }

    function formatDate(value) {
        if (!value) return '';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleString('zh-TW', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', hour12: false,
        });
    }

    function entryMeta(entry) {
        if (entry.available === false) return '目前無法連線';
        const labels = {
            directory: '資料夾', video: '影片', image: '圖片', text: '文字', apk: 'Android 安裝檔', other: '不支援預覽',
        };
        const parts = [labels[entry.kind] || entry.kind];
        const size = formatBytes(entry.size_bytes);
        const date = formatDate(entry.modified_at);
        if (size) parts.push(size);
        if (date) parts.push(date);
        return parts.join('・');
    }

    function showToast(message, duration = 1800) {
        elements.toast.textContent = message;
        elements.toast.classList.add('visible');
        clearTimeout(state.toastTimer);
        state.toastTimer = setTimeout(() => elements.toast.classList.remove('visible'), duration);
    }

    function setLoading(message = '正在讀取 NAS…') {
        elements.list.innerHTML = '';
        elements.empty.hidden = false;
        elements.empty.className = 'loading-state';
        elements.empty.textContent = message;
        elements.loadMore.hidden = true;
    }

    function renderBreadcrumbs() {
        elements.breadcrumbs.innerHTML = '';
        const crumbs = [{id: null, label: 'NAS'}, ...(state.meta?.breadcrumbs || [])];
        crumbs.forEach((crumb, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `crumb${index === crumbs.length - 1 ? ' current' : ''}`;
            button.textContent = crumb.label;
            button.title = crumb.label;
            button.addEventListener('click', () => {
                if (index === crumbs.length - 1) return;
                navigateTo(crumb.id, true);
            });
            elements.breadcrumbs.appendChild(button);
        });
        elements.breadcrumbs.scrollLeft = elements.breadcrumbs.scrollWidth;
    }

    function renderList() {
        elements.list.innerHTML = '';
        elements.empty.hidden = state.entries.length > 0;
        elements.empty.className = 'empty-state';
        elements.empty.textContent = state.entries.length > 0 ? '' : '這個目錄目前沒有可顯示的項目。';

        for (const entry of state.entries) {
            const row = document.createElement('button');
            row.type = 'button';
            row.className = `entry${entry.id === state.selectedId ? ' selected' : ''}${entry.available === false ? ' offline' : ''}`;
            row.dataset.entryId = entry.id;
            row.dataset.entryKind = entry.kind;

            const icon = document.createElement('span');
            icon.className = 'entry-icon';
            icon.textContent = iconFor(entry);

            const copy = document.createElement('span');
            copy.className = 'entry-copy';
            const name = document.createElement('span');
            name.className = 'entry-name';
            name.textContent = entry.name;
            const meta = document.createElement('span');
            meta.className = 'entry-meta';
            meta.textContent = entryMeta(entry);
            copy.append(name, meta);

            const action = document.createElement('span');
            action.className = 'entry-action';
            action.textContent = actionFor(entry);
            row.append(icon, copy, action);
            row.addEventListener('click', () => handleEntryClick(entry));
            row.addEventListener('focus', () => {
                const rows = Array.from(elements.list.querySelectorAll('.entry'));
                state.tvFocusIndex = Math.max(0, rows.indexOf(row));
            });
            elements.list.appendChild(row);
        }

        elements.title.textContent = state.meta?.title || 'NAS';
        const total = Number(state.meta?.total || state.entries.length);
        elements.summary.textContent = `${total} 個項目・點一下即可開啟`;
        elements.loadMore.hidden = !state.meta?.has_more;
        elements.back.disabled = state.navigationStack.length === 0 && state.directoryId === null;
        renderBreadcrumbs();
        requestAnimationFrame(() => focusTvEntry(state.tvFocusIndex));
    }

    function focusTvEntry(index = state.tvFocusIndex) {
        const rows = Array.from(elements.list.querySelectorAll('.entry'));
        if (rows.length === 0) return false;
        state.tvFocusIndex = clamp(Number(index) || 0, 0, rows.length - 1);
        const row = rows[state.tvFocusIndex];
        row.focus({preventScroll: true});
        row.scrollIntoView({block: 'center', inline: 'nearest', behavior: 'smooth'});
        return true;
    }

    async function fetchDirectory(directoryId, offset = 0, append = false) {
        if (state.loading) return;
        state.loading = true;
        const token = ++state.requestToken;
        if (!append) setLoading();

        const params = new URLSearchParams({
            offset: String(offset),
            limit: String(Number(config.page_limit) || 300),
            t: String(Date.now()),
        });
        if (directoryId) params.set('directory', directoryId);

        try {
            const response = await fetch(`/api/nas-browser?${params}`, {
                cache: 'no-store', headers: {'Accept': 'application/json'},
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (token !== state.requestToken) return;
            state.directoryId = directoryId || null;
            state.entries = append
                ? [...state.entries, ...(Array.isArray(payload.data) ? payload.data : [])]
                : (Array.isArray(payload.data) ? payload.data : []);
            state.meta = payload.meta || null;
            state.selectedId = null;
            if (!append) {
                state.tvFocusIndex = 0;
                failedViewerEntryIds.clear();
            }
            renderList();
            if (!append) {
                elements.listShell.scrollTop = 0;
            }
        } catch (error) {
            if (token !== state.requestToken) return;
            elements.list.innerHTML = '';
            elements.empty.hidden = false;
            elements.empty.className = 'empty-state';
            elements.empty.textContent = '無法讀取這個 NAS 目錄。請確認 NAS 連線後再重新整理。';
            elements.loadMore.hidden = true;
        } finally {
            if (token === state.requestToken) state.loading = false;
        }
    }

    function navigateTo(directoryId, pushCurrent) {
        if (pushCurrent) {
            state.navigationStack.push(state.directoryId);
        }
        closeViewer(false);
        fetchDirectory(directoryId || null);
    }

    function handleEntryClick(entry) {
        state.selectedId = entry.id;
        elements.list.querySelectorAll('.entry').forEach(row => {
            row.classList.toggle('selected', row.dataset.entryId === entry.id);
        });

        if (entry.available === false) {
            showToast('這個 NAS 分享目前無法連線');
            return;
        }
        if (entry.kind === 'directory') {
            navigateTo(entry.id, true);
            return;
        }
        if (['video', 'image', 'text'].includes(entry.kind)) {
            openViewer(entry);
            return;
        }
        if (entry.kind === 'apk') {
            openApkInstaller(entry);
            return;
        }
        showToast('這個檔案格式目前不支援預覽');
    }

    function openApkInstaller(entry) {
        if (!entry.download_url) {
            showToast('這個 APK 安裝檔目前無法開啟');
            return;
        }
        showToast('正在開啟 APK 安裝檔…', 3000);
        window.location.assign(entry.download_url);
    }

    function resetViewerElements() {
        elements.video.pause();
        elements.video.removeAttribute('src');
        elements.video.load();
        elements.image.removeAttribute('src');
        elements.text.textContent = '';
        elements.video.classList.remove('active');
        elements.image.classList.remove('active');
        elements.text.classList.remove('active');
        elements.viewerMessage.textContent = '';
    }

    function stopNativeTvVideo() {
        try {
            if (
                window.NasViewerTvAndroid
                && typeof window.NasViewerTvAndroid.stopVideo === 'function'
            ) {
                window.NasViewerTvAndroid.stopVideo();
            }
        } catch (error) {
        }
    }

    function setMediaAutoOrientation(enabled) {
        try {
            if (
                window.NasViewerAndroid
                && typeof window.NasViewerAndroid.setMediaOrientationEnabled === 'function'
            ) {
                window.NasViewerAndroid.setMediaOrientationEnabled(Boolean(enabled));
            }
        } catch (error) {
        }
    }

    function setVideoFullscreen(enabled) {
        elements.viewer.classList.toggle('video-mode', enabled);
        elements.videoOverlayControls.setAttribute('aria-hidden', enabled ? 'false' : 'true');
        try {
            if (
                window.NasViewerAndroid
                && typeof window.NasViewerAndroid.setVideoFullscreenEnabled === 'function'
            ) {
                window.NasViewerAndroid.setVideoFullscreenEnabled(Boolean(enabled));
            }
        } catch (error) {
        }
    }

    function notifyNasViewerTv(open, kind = '') {
        try {
            if (
                window.NasViewerTvAndroid
                && typeof window.NasViewerTvAndroid.setViewerState === 'function'
            ) {
                window.NasViewerTvAndroid.setViewerState(Boolean(open), String(kind || ''));
            }
        } catch (error) {
        }
    }

    function showVideoSeekFlash(label) {
        elements.videoSeekFlash.textContent = label;
        elements.videoSeekFlash.classList.add('visible');
        clearTimeout(state.videoSeekFlashTimer);
        state.videoSeekFlashTimer = setTimeout(
            () => elements.videoSeekFlash.classList.remove('visible'),
            650
        );
    }

    function seekVideo(seconds, label = null) {
        const duration = Number(elements.video.duration || 0);
        if (!duration || !Number.isFinite(duration)) return;
        elements.video.currentTime = clamp(Number(elements.video.currentTime || 0) + seconds, 0, duration);
        showVideoSeekFlash(label || (seconds > 0 ? `+${seconds} 秒` : `${seconds} 秒`));
    }

    function seekVideoByRatio(stepCount) {
        const duration = Number(elements.video.duration || 0);
        if (!duration || !Number.isFinite(duration) || !stepCount) return;
        const percent = stepCount * VIDEO_DRAG_SEEK_RATIO;
        const labelPercent = Math.round(percent * 100);
        seekVideo(duration * percent, `${labelPercent > 0 ? '+' : ''}${labelPercent}%`);
    }

    function previewableEntries() {
        return state.entries.filter(entry => ['video', 'image', 'text'].includes(entry.kind));
    }

    function findEligibleAdjacent(queue, currentIndex, delta) {
        for (
            let index = currentIndex + delta;
            index >= 0 && index < queue.length;
            index += delta
        ) {
            const entry = queue[index];
            if (!failedViewerEntryIds.has(entry.id)) return entry;
        }
        return null;
    }

    async function findAdjacentEntry(delta) {
        let queue = previewableEntries();
        let currentIndex = queue.findIndex(entry => entry.id === state.viewerEntry?.id);
        if (currentIndex < 0) return null;

        let adjacent = findEligibleAdjacent(queue, currentIndex, delta);
        while (!adjacent && delta > 0 && state.meta?.has_more) {
            const previousEntryCount = state.entries.length;
            await fetchDirectory(
                state.directoryId,
                Number(state.meta?.next_offset || previousEntryCount),
                true
            );
            if (state.entries.length <= previousEntryCount) break;

            queue = previewableEntries();
            currentIndex = queue.findIndex(entry => entry.id === state.viewerEntry?.id);
            adjacent = currentIndex >= 0 ? findEligibleAdjacent(queue, currentIndex, delta) : null;
        }

        return adjacent;
    }

    async function switchViewerEntry(delta) {
        if (state.viewerSwitching || !state.viewerEntry || ![-1, 1].includes(delta)) return false;
        state.lastViewerSwitchDelta = delta;
        state.viewerSwitching = true;
        try {
            const adjacent = await findAdjacentEntry(delta);
            if (!adjacent) {
                showToast(delta > 0 ? '已經是最後一個檔案' : '已經是第一個檔案');
                return false;
            }

            await openViewer(adjacent, true);
            showToast(`${delta > 0 ? '下一個' : '上一個'}：${adjacent.name}`);
            return true;
        } finally {
            state.viewerSwitching = false;
        }
    }

    function bindViewerFileSwipes() {
        let startX = 0;
        let startY = 0;
        let startTime = 0;
        let tracking = false;

        const stopTracking = () => {
            tracking = false;
        };

        elements.viewerContent.addEventListener('pointerdown', event => {
            if (!state.viewerEntry || event.target.closest('button')) return;
            startX = event.clientX;
            startY = event.clientY;
            startTime = Date.now();
            tracking = true;
        });

        elements.viewerContent.addEventListener('pointerup', event => {
            if (!tracking || !state.viewerEntry) return;
            tracking = false;
            const dx = event.clientX - startX;
            const dy = event.clientY - startY;
            const absX = Math.abs(dx);
            const absY = Math.abs(dy);
            if (Date.now() - startTime > 900 || absY < 56 || absY <= absX * 1.15) return;

            const delta = dy < 0 ? 1 : -1;
            if (state.viewerEntry.kind === 'text') {
                const atTop = elements.text.scrollTop <= 2;
                const atBottom = elements.text.scrollTop + elements.text.clientHeight >= elements.text.scrollHeight - 2;
                if ((delta < 0 && !atTop) || (delta > 0 && !atBottom)) return;
            }

            event.preventDefault();
            switchViewerEntry(delta);
        });

        elements.viewerContent.addEventListener('pointercancel', stopTracking);
        elements.viewerContent.addEventListener('pointerleave', stopTracking);
    }

    function bindVideoSeekGestures() {
        let startX = 0;
        let startY = 0;
        let holdSeekTimer = null;
        let holdSeekArmed = false;
        let dragSeekApplied = false;
        let dragSeekStepIndex = 0;

        const clearDragSeek = () => {
            clearTimeout(holdSeekTimer);
            holdSeekTimer = null;
            holdSeekArmed = false;
            dragSeekApplied = false;
            dragSeekStepIndex = 0;
        };
        stopVideoDragSeek = clearDragSeek;

        elements.viewerContent.addEventListener('pointerdown', event => {
            if (!state.viewerEntry || state.viewerEntry.kind !== 'video' || event.target.closest('button')) return;
            startX = event.clientX;
            startY = event.clientY;
            holdSeekArmed = false;
            dragSeekApplied = false;
            dragSeekStepIndex = 0;
            clearTimeout(holdSeekTimer);
            holdSeekTimer = setTimeout(() => {
                holdSeekArmed = true;
            }, VIDEO_HOLD_SEEK_MS);
        });

        elements.viewerContent.addEventListener('pointermove', event => {
            if (!state.viewerEntry || state.viewerEntry.kind !== 'video') return;
            const dx = event.clientX - startX;
            const dy = event.clientY - startY;
            const absX = Math.abs(dx);
            const absY = Math.abs(dy);

            if (!holdSeekArmed && (absX > 16 || absY > 16)) {
                clearTimeout(holdSeekTimer);
                holdSeekTimer = null;
            }

            if (holdSeekArmed && ((absX > 28 && absX > absY * 1.15) || dragSeekApplied)) {
                const nextStepIndex = Math.round(dx / VIDEO_DRAG_SEEK_STEP_PX);
                const stepDelta = nextStepIndex - dragSeekStepIndex;
                if (stepDelta !== 0) {
                    seekVideoByRatio(stepDelta);
                    dragSeekStepIndex = nextStepIndex;
                    dragSeekApplied = true;
                }
                event.preventDefault();
            }
        });

        elements.viewerContent.addEventListener('pointerup', event => {
            clearTimeout(holdSeekTimer);
            const hadDragSeek = dragSeekApplied;
            clearDragSeek();
            if (hadDragSeek) event.preventDefault();
        });
        elements.viewerContent.addEventListener('pointercancel', clearDragSeek);
        elements.viewerContent.addEventListener('pointerleave', clearDragSeek);
    }

    async function openViewer(entry, switched = false) {
        stopNativeTvVideo();
        setMediaAutoOrientation(['video', 'image'].includes(entry.kind));
        setVideoFullscreen(entry.kind === 'video');
        elements.viewer.classList.toggle('image-mode', entry.kind === 'image');
        state.viewerEntry = entry;
        state.selectedId = null;
        resetViewerElements();
        elements.viewerTitle.textContent = entry.name;
        elements.viewer.classList.add('open');
        elements.viewer.setAttribute('aria-hidden', 'false');
        notifyNasViewerTv(true, entry.kind);

        if (!switched) {
            showToast('上滑下一個・下滑上一個');
        }

        if (entry.kind === 'video') {
            elements.video.classList.add('active');
            try {
                if (
                    window.NasViewerTvAndroid
                    && typeof window.NasViewerTvAndroid.playVideo === 'function'
                ) {
                    window.NasViewerTvAndroid.playVideo(entry.media_url, entry.id);
                    return;
                }
            } catch (error) {
            }
            elements.video.src = entry.media_url;
            elements.video.play().catch(() => {});
            return;
        }
        if (entry.kind === 'image') {
            try {
                if (
                    window.NasViewerTvAndroid
                    && typeof window.NasViewerTvAndroid.showImage === 'function'
                ) {
                    window.NasViewerTvAndroid.showImage(entry.media_url, entry.id);
                    return;
                }
            } catch (error) {
            }
            elements.image.classList.add('active');
            elements.image.src = entry.media_url;
            return;
        }

        elements.viewerMessage.textContent = '正在讀取文字…';
        try {
            const response = await fetch(entry.text_url, {cache: 'no-store', headers: {'Accept': 'application/json'}});
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (state.viewerEntry?.id !== entry.id) return;
            elements.viewerMessage.textContent = '';
            elements.text.textContent = payload.data?.content || '';
            elements.text.classList.add('active');
        } catch (error) {
            elements.viewerMessage.textContent = '這個文字檔無法讀取，或檔案超過顯示大小限制。';
        }
    }

    function closeViewer(render = true) {
        if (!state.viewerEntry && !elements.viewer.classList.contains('open')) return;
        stopVideoDragSeek();
        setVideoFullscreen(false);
        elements.viewer.classList.remove('image-mode');
        setMediaAutoOrientation(false);
        if (document.fullscreenElement && document.exitFullscreen) {
            document.exitFullscreen().catch(() => {});
        }
        state.viewerEntry = null;
        notifyNasViewerTv(false, '');
        stopNativeTvVideo();
        resetViewerElements();
        elements.viewer.classList.remove('open');
        elements.viewer.setAttribute('aria-hidden', 'true');
        if (render) renderList();
    }

    function handleBack() {
        if (state.viewerEntry || elements.viewer.classList.contains('open')) {
            closeViewer();
            return true;
        }
        if (state.navigationStack.length > 0) {
            const previous = state.navigationStack.pop();
            fetchDirectory(previous || null);
            return true;
        }
        if (state.directoryId !== null) {
            fetchDirectory(null);
            return true;
        }
        return false;
    }

    elements.back.addEventListener('click', () => handleBack());
    elements.refresh.addEventListener('click', () => fetchDirectory(state.directoryId));
    elements.loadMore.addEventListener('click', () => fetchDirectory(
        state.directoryId,
        Number(state.meta?.next_offset || state.entries.length),
        true
    ));
    elements.viewerBack.addEventListener('click', () => closeViewer());
    elements.viewerClose.addEventListener('click', () => closeViewer());
    elements.videoBack.addEventListener('click', () => closeViewer());
    elements.videoRewind.addEventListener('click', () => seekVideo(-10, '-10 秒'));
    elements.videoForward.addEventListener('click', () => seekVideo(10, '+10 秒'));
    elements.video.addEventListener('ended', () => closeViewer());
    elements.video.addEventListener('error', () => {
        if (!state.viewerEntry || state.viewerEntry.kind !== 'video') return;
        elements.video.classList.remove('active');
        elements.viewerMessage.textContent = '這個影片格式無法在目前的 Android 播放器中播放。';
    });
    elements.image.addEventListener('error', () => {
        if (!state.viewerEntry || state.viewerEntry.kind !== 'image') return;
        elements.image.classList.remove('active');
        elements.viewerMessage.textContent = '這張圖片無法顯示。';
    });

    window.nasViewerTvHandleKey = key => {
        if (!state.viewerEntry || !elements.viewer.classList.contains('open')) {
            if (key === 'center') {
                const rows = Array.from(elements.list.querySelectorAll('.entry'));
                const row = rows[clamp(state.tvFocusIndex, 0, Math.max(0, rows.length - 1))];
                const entry = row ? state.entries.find(item => item.id === row.dataset.entryId) : null;
                if (entry) handleEntryClick(entry);
                return Boolean(entry);
            }
            if (key === 'up' || key === 'left') return focusTvEntry(state.tvFocusIndex - 1);
            if (key === 'down' || key === 'right') return focusTvEntry(state.tvFocusIndex + 1);
            return false;
        }
        if (key === 'up') {
            switchViewerEntry(1);
            return true;
        }
        if (key === 'down') {
            switchViewerEntry(-1);
            return true;
        }
        if (state.viewerEntry.kind === 'video' && key === 'left') {
            seekVideo(-5, '-5 秒');
            return true;
        }
        if (state.viewerEntry.kind === 'video' && key === 'right') {
            seekVideo(5, '+5 秒');
            return true;
        }
        if (state.viewerEntry.kind === 'video' && key === 'center') {
            if (elements.video.paused) {
                elements.video.play().catch(() => {});
                showVideoSeekFlash('播放');
            } else {
                elements.video.pause();
                showVideoSeekFlash('暫停');
            }
            return true;
        }
        return false;
    };

    window.nasViewerTvNativePlaying = entryId => {
        if (state.viewerEntry?.id !== entryId) return;
        failedViewerEntryIds.delete(entryId);
    };
    window.nasViewerTvNativeEnded = entryId => {
        if (state.viewerEntry?.id !== entryId) return;
        closeViewer();
    };
    window.nasViewerTvNativeClosed = () => {
        if (state.viewerEntry || elements.viewer.classList.contains('open')) closeViewer();
    };
    window.nasViewerTvNativeError = async entryId => {
        if (!state.viewerEntry || state.viewerEntry.id !== entryId) return;
        failedViewerEntryIds.add(entryId);
        showToast('這支影片無法播放，正在尋找下一支…', 2600);
        const moved = await switchViewerEntry(state.lastViewerSwitchDelta || 1);
        if (!moved && state.viewerEntry?.id === entryId) closeViewer();
    };
    window.nasViewerTvNativeImageError = entryId => {
        if (state.viewerEntry?.id === entryId) showToast('這張圖片無法顯示');
    };

    window.nasViewerHandleBack = handleBack;
    window.nasViewerSetAndroidInsets = insets => {
        const bottom = Math.max(0, Number(insets?.bottom || 0));
        document.documentElement.style.setProperty('--bottom-inset', `${bottom}px`);
    };
    window.nasViewerCheckUpdates = async () => {
        try {
            const response = await fetch(`/nas-viewer-app/version.json?t=${Date.now()}`, {cache: 'no-store'});
            const payload = await response.json();
            if (payload.data?.version && payload.data.version !== config.version) location.reload();
        } catch (error) {
        }
    };

    bindVideoSeekGestures();
    bindViewerFileSwipes();
    notifyNasViewerTv(false, '');
    setVideoFullscreen(false);
    setMediaAutoOrientation(false);
    fetchDirectory(null);
})();
</script>
</body>
</html>
