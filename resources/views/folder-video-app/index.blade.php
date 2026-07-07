<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#090b0f">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>Folder Video</title>
    <link rel="manifest" href="{{ route('folder-video-app.manifest', [], false) }}">
    <link rel="apple-touch-icon" href="/folder-video-app/icon-192.png">
    <link rel="icon" href="/folder-video-app/icon-192.png">
    <style>
        :root {
            color-scheme: dark;
            --bg: #090b0f;
            --panel: rgba(13, 18, 25, .88);
            --line: rgba(255, 255, 255, .14);
            --text: #f4f7fb;
            --muted: #a8b1c2;
            --teal: #44d7c4;
            --coral: #ff7d67;
            --gold: #ffd36a;
            --columns: 3;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: 0;
        }

        body {
            overscroll-behavior-y: contain;
            -webkit-tap-highlight-color: transparent;
        }

        button {
            border: 0;
            color: inherit;
            font: inherit;
        }

        .app-shell {
            min-height: 100svh;
            background:
                linear-gradient(140deg, rgba(68, 215, 196, .08), transparent 28%),
                linear-gradient(30deg, rgba(255, 125, 103, .06), transparent 32%),
                var(--bg);
        }

        .topbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 8px;
            align-items: center;
            min-height: 54px;
            padding: calc(env(safe-area-inset-top) + 7px) 8px 7px;
            border-bottom: 1px solid var(--line);
            background: rgba(9, 11, 15, .86);
            backdrop-filter: blur(18px);
        }

        .brand {
            display: grid;
            grid-template-columns: 34px minmax(0, 1fr);
            gap: 8px;
            align-items: center;
            min-width: 0;
        }

        .brand img {
            width: 34px;
            height: 34px;
            border-radius: 8px;
        }

        .brand-title {
            overflow: hidden;
            font-size: 14px;
            font-weight: 700;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .brand-subtitle {
            overflow: hidden;
            color: var(--muted);
            font-size: 11px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .status-pill {
            justify-self: center;
            max-width: 36vw;
            overflow: hidden;
            padding: 6px 9px;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 999px;
            background: rgba(255, 255, 255, .07);
            color: var(--muted);
            font-size: 11px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .toolbar-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            justify-content: flex-end;
        }

        .icon-button {
            display: inline-grid;
            width: 36px;
            height: 36px;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 8px;
            background: rgba(255, 255, 255, .08);
            font-size: 20px;
            line-height: 1;
        }

        .icon-button:active {
            transform: scale(.96);
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(var(--columns), minmax(0, 1fr));
            gap: 3px;
            padding: 3px;
            touch-action: pan-y pinch-zoom;
        }

        .video-card {
            position: relative;
            min-width: 0;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 6px;
            aspect-ratio: 3 / 4;
            background: #121820;
            cursor: pointer;
            isolation: isolate;
        }

        .video-card video {
            display: block;
            width: 100%;
            height: 100%;
            background: #080a0d;
            object-fit: cover;
        }

        .video-card.is-current {
            outline: 2px solid var(--teal);
            outline-offset: -2px;
        }

        .video-card.is-liked::after {
            position: absolute;
            top: 8px;
            right: 8px;
            content: "♥";
            color: var(--coral);
            font-size: 20px;
            text-shadow: 0 2px 12px rgba(0, 0, 0, .6);
        }

        .card-meta {
            position: absolute;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 2;
            display: grid;
            gap: 3px;
            padding: 28px 7px 7px;
            background: linear-gradient(transparent, rgba(0, 0, 0, .7));
            pointer-events: none;
        }

        .card-name {
            overflow: hidden;
            font-size: clamp(10px, 3vw, 13px);
            font-weight: 650;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .card-row {
            display: flex;
            gap: 6px;
            align-items: center;
            color: rgba(255, 255, 255, .84);
            font-size: 11px;
        }

        .progress-shell {
            flex: 1;
            height: 3px;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, .22);
        }

        .progress-fill {
            width: 0%;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--teal), var(--coral));
        }

        .empty-state {
            display: none;
            padding: 32px 16px;
            color: var(--muted);
            text-align: center;
        }

        .empty-state.show {
            display: block;
        }

        .sentinel {
            height: 72px;
        }

        .player {
            position: fixed;
            inset: 0;
            z-index: 50;
            display: none;
            background: #000;
            touch-action: none;
        }

        .player.open {
            display: block;
        }

        .player video {
            width: 100%;
            height: 100%;
            background: #000;
            object-fit: contain;
        }

        .player-top {
            position: absolute;
            top: 0;
            right: 0;
            left: 0;
            z-index: 4;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 8px;
            align-items: center;
            padding: calc(env(safe-area-inset-top) + 8px) 8px 8px;
            background: linear-gradient(rgba(0, 0, 0, .72), transparent);
            pointer-events: none;
        }

        .player-top .icon-button,
        .player-actions .icon-button {
            background: rgba(0, 0, 0, .38);
            pointer-events: auto;
        }

        .player-title {
            overflow: hidden;
            font-size: 13px;
            font-weight: 650;
            text-align: center;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .player-actions {
            position: absolute;
            right: 8px;
            bottom: calc(env(safe-area-inset-bottom) + 22px);
            z-index: 4;
            display: grid;
            gap: 10px;
        }

        .player-progress {
            position: absolute;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 5;
            height: calc(env(safe-area-inset-bottom) + 4px);
            background: rgba(255, 255, 255, .13);
        }

        .player-progress span {
            display: block;
            width: 0%;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), var(--gold), var(--coral));
        }

        .toast {
            position: fixed;
            right: 16px;
            bottom: calc(env(safe-area-inset-bottom) + 22px);
            z-index: 80;
            max-width: calc(100vw - 32px);
            padding: 10px 12px;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 8px;
            background: rgba(13, 18, 25, .92);
            box-shadow: 0 14px 36px rgba(0, 0, 0, .32);
            color: var(--text);
            font-size: 13px;
            opacity: 0;
            transform: translateY(12px);
            transition: opacity .18s ease, transform .18s ease;
            pointer-events: none;
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }

        .gesture-flash {
            position: fixed;
            top: 50%;
            left: 50%;
            z-index: 90;
            display: grid;
            min-width: 88px;
            min-height: 88px;
            place-items: center;
            border-radius: 999px;
            background: rgba(0, 0, 0, .52);
            color: #fff;
            font-size: 22px;
            font-weight: 800;
            opacity: 0;
            transform: translate(-50%, -50%) scale(.92);
            transition: opacity .14s ease, transform .14s ease;
            pointer-events: none;
        }

        .gesture-flash.show {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }

        @media (min-width: 760px) {
            :root {
                --columns: 4;
            }

            .app-shell {
                max-width: 980px;
                margin: 0 auto;
            }
        }
    </style>
</head>
<body>
<div class="app-shell" id="appShell">
    <header class="topbar">
        <div class="brand">
            <img src="/folder-video-app/icon-192.png" alt="">
            <div>
                <div class="brand-title">Folder Video</div>
                <div class="brand-subtitle" id="subtitle">準備中</div>
            </div>
        </div>
        <div class="status-pill" id="statusPill">v{{ $appConfig['version'] }}</div>
        <div class="toolbar-actions">
            <button class="icon-button" id="zoomOutButton" type="button" title="縮小">−</button>
            <button class="icon-button" id="zoomInButton" type="button" title="放大">＋</button>
            <button class="icon-button" id="refreshButton" type="button" title="重新整理">↻</button>
        </div>
    </header>

    <main>
        <div class="video-grid" id="videoGrid"></div>
        <div class="empty-state" id="emptyState">沒有可播放的影片</div>
        <div class="sentinel" id="sentinel"></div>
    </main>
</div>

<section class="player" id="playerOverlay" aria-hidden="true">
    <video id="playerVideo" playsinline preload="metadata"></video>
    <div class="player-top">
        <button class="icon-button" id="closePlayerButton" type="button" title="返回">‹</button>
        <div class="player-title" id="playerTitle"></div>
        <button class="icon-button" id="playerLikeButton" type="button" title="喜歡">♥</button>
    </div>
    <div class="player-actions">
        <button class="icon-button" id="playerPrevButton" type="button" title="上一支">↑</button>
        <button class="icon-button" id="playerNextButton" type="button" title="下一支">↓</button>
    </div>
    <div class="player-progress"><span id="playerProgress"></span></div>
</section>

<div class="toast" id="toast"></div>
<div class="gesture-flash" id="gestureFlash"></div>

<script>
(() => {
    const BOOT_CONFIG = @json($appConfig);
    const API_BASE = '/api/folder-videos';
    const APP_CONFIG_URL = '/api/folder-videos/app-config';
    const VERSION_URL = '/folder-video-app/version.json';
    const SW_URL = '/folder-video-app/sw.js';
    const STORAGE_KEY = 'folder-video-app-state-v1';

    const elements = {
        shell: document.getElementById('appShell'),
        grid: document.getElementById('videoGrid'),
        sentinel: document.getElementById('sentinel'),
        empty: document.getElementById('emptyState'),
        subtitle: document.getElementById('subtitle'),
        status: document.getElementById('statusPill'),
        zoomIn: document.getElementById('zoomInButton'),
        zoomOut: document.getElementById('zoomOutButton'),
        refresh: document.getElementById('refreshButton'),
        player: document.getElementById('playerOverlay'),
        playerVideo: document.getElementById('playerVideo'),
        playerTitle: document.getElementById('playerTitle'),
        playerProgress: document.getElementById('playerProgress'),
        closePlayer: document.getElementById('closePlayerButton'),
        playerLike: document.getElementById('playerLikeButton'),
        playerPrev: document.getElementById('playerPrevButton'),
        playerNext: document.getElementById('playerNextButton'),
        toast: document.getElementById('toast'),
        flash: document.getElementById('gestureFlash'),
    };

    let appConfig = Object.assign({
        version: '2026.07.07.3',
        page_limit: 36,
        preview_max_connections: 6,
    }, BOOT_CONFIG || {});
    const sessionSeed = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`;
    const freshVideoIds = new Set();
    let state = loadState();
    const sessionNewFirstAfter = Number(state.lastLibraryScanAt || 0);
    let videos = [];
    let videoById = new Map();
    let cursor = {offset: 0, hasMore: true};
    let isLoading = false;
    let playerItem = null;
    let playerIndex = -1;
    let saveProgressTimer = null;
    let toastTimer = null;
    let flashTimer = null;
    let previewPaused = false;

    const observer = new IntersectionObserver(handlePreviewIntersections, {
        root: null,
        rootMargin: '120px 0px',
        threshold: [0, .2, .6],
    });
    const visiblePreviewCards = new Map();
    let previewRotation = 0;

    function loadState() {
        try {
            const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            return Object.assign({
                gridColumns: 3,
                progress: {},
                completed: {},
                liked: {},
                known: {},
                lastLibraryScanAt: 0,
                currentId: null,
                currentVideo: null,
            }, parsed);
        } catch (error) {
            return {
                gridColumns: 3,
                progress: {},
                completed: {},
                liked: {},
                known: {},
                lastLibraryScanAt: 0,
                currentId: null,
                currentVideo: null,
            };
        }
    }

    function saveState() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, (match) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        })[match]);
    }

    function toPlayableUrl(value) {
        try {
            const parsed = new URL(String(value || ''), window.location.origin);
            if (parsed.hostname === 'blog.test' || parsed.origin === window.location.origin) {
                return `${parsed.pathname}${parsed.search}`;
            }
        } catch (error) {
        }

        return String(value || '');
    }

    function formatDuration(seconds) {
        const safe = Number.isFinite(seconds) ? Math.max(0, Math.round(seconds)) : 0;
        const hours = Math.floor(safe / 3600);
        const minutes = Math.floor((safe % 3600) / 60);
        const rest = safe % 60;

        if (hours > 0) {
            return `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
        }

        return `${minutes}:${String(rest).padStart(2, '0')}`;
    }

    function normalizeVideo(video) {
        return {
            id: String(video.id || ''),
            filename: String(video.filename || ''),
            duration_seconds: Number(video.duration_seconds || 0),
            duration_label: String(video.duration_label || formatDuration(Number(video.duration_seconds || 0))),
            size_bytes: Number(video.size_bytes || 0),
            modified_at: Number(video.modified_at || 0),
            created_at: Number(video.created_at || 0),
            stream_url: toPlayableUrl(video.stream_url || `${API_BASE}/${video.id}/stream`),
        };
    }

    function isCompleted(id) {
        return Boolean(state.completed[id]);
    }

    function upsertVideo(video, placeFirst = false) {
        const normalized = normalizeVideo(video);
        if (!normalized.id) {
            return;
        }

        if (!state.known?.[normalized.id]) {
            freshVideoIds.add(normalized.id);
        }

        if (videoById.has(normalized.id)) {
            Object.assign(videoById.get(normalized.id), normalized);
            return;
        }

        videoById.set(normalized.id, normalized);
        if (placeFirst) {
            videos.unshift(normalized);
            return;
        }

        videos.push(normalized);
    }

    function playableVideos() {
        return videos.filter((video) => !isCompleted(video.id))
            .sort((left, right) => {
                const leftFresh = freshVideoIds.has(left.id) ? 0 : 1;
                const rightFresh = freshVideoIds.has(right.id) ? 0 : 1;

                return leftFresh - rightFresh;
            });
    }

    function rememberKnownVideos(items) {
        const now = Math.floor(Date.now() / 1000);
        state.known = state.known || {};

        (items || []).forEach((item) => {
            const video = normalizeVideo(item);
            if (!video.id || state.known[video.id]) {
                return;
            }

            state.known[video.id] = {
                filename: video.filename,
                firstSeenAt: now,
            };
        });

        state.lastLibraryScanAt = now;
        saveState();
    }

    function applyGridColumns() {
        state.gridColumns = clamp(Number(state.gridColumns || 3), 1, 5);
        document.documentElement.style.setProperty('--columns', String(state.gridColumns));
        saveState();
    }

    function updateStatus() {
        const remaining = playableVideos().length;
        const completed = Object.keys(state.completed || {}).length;
        elements.subtitle.textContent = `${remaining} 可播放 · ${completed} 已看`;
        elements.status.textContent = `v${appConfig.version}`;
    }

    function progressPercent(video) {
        const progress = state.progress?.[video.id];
        const seconds = Number(progress?.time || 0);
        const duration = Number(progress?.duration || video.duration_seconds || 0);

        if (!duration || duration <= 0) {
            return 0;
        }

        return clamp((seconds / duration) * 100, 0, 100);
    }

    function renderGrid() {
        observer.disconnect();
        visiblePreviewCards.clear();
        elements.grid.textContent = '';

        const items = playableVideos();
        const fragment = document.createDocumentFragment();

        items.forEach((video) => {
            const card = document.createElement('article');
            card.className = 'video-card';
            card.dataset.id = video.id;
            card.innerHTML = `
                <video class="preview-video" muted loop playsinline preload="none" data-src="${escapeHtml(video.stream_url)}"></video>
                <div class="card-meta">
                    <div class="card-name">${escapeHtml(video.filename)}</div>
                    <div class="card-row">
                        <span>${escapeHtml(video.duration_label)}</span>
                        <span class="progress-shell"><span class="progress-fill" style="width:${progressPercent(video)}%"></span></span>
                    </div>
                </div>
            `;

            if (state.currentId === video.id) {
                card.classList.add('is-current');
            }

            if (state.liked?.[video.id]) {
                card.classList.add('is-liked');
            }

            card.addEventListener('click', () => openPlayer(video));
            observer.observe(card);
            fragment.appendChild(card);
        });

        elements.grid.appendChild(fragment);
        elements.empty.classList.toggle('show', items.length === 0 && !cursor.hasMore && !isLoading);
        updateStatus();
        schedulePreviewRefresh();
    }

    function handlePreviewIntersections(entries) {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                visiblePreviewCards.set(entry.target, entry.intersectionRatio || .1);
            } else {
                visiblePreviewCards.delete(entry.target);
                stopPreview(entry.target);
            }
        });
        schedulePreviewRefresh();
    }

    function startPreview(card) {
        if (previewPaused) {
            return;
        }

        const video = card.querySelector('video');
        if (!video || video.dataset.activePreview === '1') {
            return;
        }

        video.dataset.activePreview = '1';
        if (video.dataset.previewBound !== '1') {
            video.dataset.previewBound = '1';
            video.addEventListener('loadedmetadata', () => {
                const duration = Number(video.duration || 0);
                if (!duration || duration < 8 || video.dataset.previewSeeked === '1') {
                    return;
                }

                video.dataset.previewSeeked = '1';
                const hash = Array.from(card.dataset.id || '').reduce((sum, char) => sum + char.charCodeAt(0), 0);
                video.currentTime = Math.min(duration - 2, Math.max(1, duration * (.18 + (hash % 42) / 100)));
            });
        }

        if (!video.getAttribute('src')) {
            video.setAttribute('src', video.dataset.src);
            video.load();
        }

        video.play().catch(() => {});
    }

    function stopPreview(card) {
        const video = card.querySelector('video');
        if (!video) {
            return;
        }

        video.dataset.activePreview = '0';
        try {
            video.pause();
        } catch (error) {
        }
        video.removeAttribute('src');
        video.load();
    }

    function stopAllPreviews() {
        document.querySelectorAll('.video-card').forEach(stopPreview);
    }

    function schedulePreviewRefresh() {
        window.requestAnimationFrame(refreshPreviews);
    }

    function refreshPreviews() {
        if (previewPaused || document.hidden) {
            stopAllPreviews();
            return;
        }

        const cards = Array.from(visiblePreviewCards.entries())
            .sort((left, right) => {
                const rectLeft = left[0].getBoundingClientRect();
                const rectRight = right[0].getBoundingClientRect();
                return (rectLeft.top - rectRight.top) || (rectLeft.left - rectRight.left);
            })
            .map(([card]) => card);

        if (cards.length === 0) {
            stopAllPreviews();
            return;
        }

        const maxActive = clamp(Number(appConfig.preview_max_connections || 6), 1, 12);
        const activeCards = new Set();
        const start = cards.length > maxActive ? previewRotation % cards.length : 0;

        for (let offset = 0; offset < Math.min(maxActive, cards.length); offset++) {
            activeCards.add(cards[(start + offset) % cards.length]);
        }

        cards.forEach((card) => {
            if (activeCards.has(card)) {
                startPreview(card);
            } else {
                stopPreview(card);
            }
        });
    }

    setInterval(() => {
        previewRotation += clamp(Number(appConfig.preview_max_connections || 6), 1, 12);
        schedulePreviewRefresh();
    }, 4200);

    async function fetchAppConfig() {
        try {
            const response = await fetch(APP_CONFIG_URL, {cache: 'no-store'});
            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            appConfig = Object.assign(appConfig, payload.data || {});
            updateStatus();
        } catch (error) {
        }
    }

    async function loadMoreVideos(reset = false) {
        if (isLoading || (!cursor.hasMore && !reset)) {
            return;
        }

        isLoading = true;
        elements.subtitle.textContent = '載入中';

        if (reset) {
            cursor = {offset: 0, hasMore: true};
            videos = [];
            videoById = new Map();
            if (state.currentVideo && !isCompleted(state.currentVideo.id)) {
                upsertVideo(state.currentVideo, true);
            }
        }

        const params = new URLSearchParams({
            limit: String(appConfig.page_limit || 36),
            offset: String(cursor.offset || 0),
            order: 'random_new_first',
            seed: sessionSeed,
            new_first_after: String(sessionNewFirstAfter),
        });

        try {
            const response = await fetch(`${API_BASE}?${params.toString()}`, {cache: 'no-store'});
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            const items = payload.data || [];
            items.forEach((video) => upsertVideo(video));
            rememberKnownVideos(items);
            cursor = {
                offset: Number(payload.meta?.next_offset ?? ((cursor.offset || 0) + items.length)),
                hasMore: Boolean(payload.meta?.has_more),
            };
            renderGrid();

            if (playableVideos().length < 12 && cursor.hasMore) {
                await loadMoreVideos();
            }
        } catch (error) {
            showToast('載入失敗');
        } finally {
            isLoading = false;
            updateStatus();
            elements.empty.classList.toggle('show', playableVideos().length === 0 && !cursor.hasMore);
        }
    }

    function rememberCurrent(video) {
        state.currentId = video.id;
        state.currentVideo = video;
        saveState();
        renderCurrentMarker();
    }

    function renderCurrentMarker() {
        document.querySelectorAll('.video-card').forEach((card) => {
            card.classList.toggle('is-current', card.dataset.id === state.currentId);
        });
    }

    function openPlayer(video) {
        const normalized = normalizeVideo(video);
        upsertVideo(normalized);
        playerItem = normalized;
        playerIndex = playableVideos().findIndex((item) => item.id === normalized.id);
        rememberCurrent(normalized);
        previewPaused = true;
        stopAllPreviews();

        elements.player.classList.add('open');
        elements.player.setAttribute('aria-hidden', 'false');
        elements.playerTitle.textContent = normalized.filename;
        elements.playerVideo.pause();
        elements.playerVideo.removeAttribute('src');
        elements.playerVideo.load();
        elements.playerVideo.src = normalized.stream_url;
        elements.playerVideo.loop = false;
        elements.playerVideo.onloadedmetadata = () => {
            const saved = Number(state.progress?.[normalized.id]?.time || 0);
            const duration = Number(elements.playerVideo.duration || normalized.duration_seconds || 0);
            if (saved > 0 && (!duration || saved < duration - 2)) {
                elements.playerVideo.currentTime = saved;
            }
            elements.playerVideo.play().catch(() => {});
        };
        elements.playerVideo.load();
    }

    function closePlayer() {
        recordPlayerProgress();
        elements.player.classList.remove('open');
        elements.player.setAttribute('aria-hidden', 'true');
        elements.playerVideo.pause();
        elements.playerVideo.removeAttribute('src');
        elements.playerVideo.load();
        previewPaused = false;
        schedulePreviewRefresh();
    }

    function recordPlayerProgress() {
        if (!playerItem || !elements.playerVideo.duration) {
            return;
        }

        state.progress[playerItem.id] = {
            time: Math.max(0, elements.playerVideo.currentTime || 0),
            duration: Math.max(0, elements.playerVideo.duration || playerItem.duration_seconds || 0),
            updatedAt: Date.now(),
        };
        saveState();
        updateCardProgress(playerItem.id);
    }

    function updateCardProgress(id) {
        const card = elements.grid.querySelector(`.video-card[data-id="${CSS.escape(id)}"]`);
        const video = videoById.get(id);
        if (!card || !video) {
            return;
        }

        const fill = card.querySelector('.progress-fill');
        if (fill) {
            fill.style.width = `${progressPercent(video)}%`;
        }
    }

    function markCompleted(video) {
        state.completed[video.id] = {
            filename: video.filename,
            completedAt: Date.now(),
        };
        delete state.progress[video.id];
        saveState();
    }

    async function findNextPlayable(delta) {
        let queue = playableVideos();
        let current = playerItem ? queue.findIndex((video) => video.id === playerItem.id) : playerIndex;
        if (current < 0) {
            current = delta > 0 ? Math.max(-1, playerIndex) : queue.length;
        }

        for (let index = current + delta; index >= 0 && index < queue.length; index += delta) {
            if (!isCompleted(queue[index].id)) {
                return queue[index];
            }
        }

        if (delta > 0 && cursor.hasMore) {
            await loadMoreVideos();
            queue = playableVideos();
            current = playerItem ? queue.findIndex((video) => video.id === playerItem.id) : current;
            if (current < 0) {
                current = Math.max(-1, playerIndex);
            }

            for (let index = current + 1; index < queue.length; index++) {
                return queue[index];
            }
        }

        return null;
    }

    async function playAdjacent(delta) {
        recordPlayerProgress();
        const next = await findNextPlayable(delta);
        if (!next) {
            showToast(delta > 0 ? '已經到底' : '已經到最前面');
            return;
        }

        openPlayer(next);
    }

    async function likeCurrent() {
        if (!playerItem || state.liked[playerItem.id]) {
            return;
        }

        const likedVideo = playerItem;
        state.liked[likedVideo.id] = {
            filename: likedVideo.filename,
            likedAt: Date.now(),
        };
        saveState();
        showFlash('♥');

        try {
            const likedIndex = playerIndex;
            const response = await fetch(`${API_BASE}/${encodeURIComponent(likedVideo.id)}/like`, {method: 'POST'});
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            markCompleted(likedVideo);
            videos = videos.filter((video) => video.id !== likedVideo.id);
            videoById.delete(likedVideo.id);
            playerIndex = likedIndex - 1;
            renderGrid();
            await playAdjacent(1);
        } catch (error) {
            delete state.liked[likedVideo.id];
            saveState();
            showToast('按讚失敗');
        }
    }

    function seekPlayer(seconds) {
        const video = elements.playerVideo;
        if (!video.duration) {
            return;
        }

        video.currentTime = clamp(video.currentTime + seconds, 0, video.duration);
        recordPlayerProgress();
        showFlash(seconds > 0 ? `+${seconds}s` : `${seconds}s`);
    }

    elements.playerVideo.addEventListener('timeupdate', () => {
        if (!playerItem || !elements.playerVideo.duration) {
            return;
        }

        const percent = clamp((elements.playerVideo.currentTime / elements.playerVideo.duration) * 100, 0, 100);
        elements.playerProgress.style.width = `${percent}%`;

        clearTimeout(saveProgressTimer);
        saveProgressTimer = setTimeout(recordPlayerProgress, 450);
    });

    elements.playerVideo.addEventListener('ended', async () => {
        if (!playerItem) {
            return;
        }

        markCompleted(playerItem);
        renderGrid();
        await playAdjacent(1);
    });

    elements.playerVideo.addEventListener('error', () => {
        showToast('影片無法播放');
    });

    function showToast(text) {
        elements.toast.textContent = text;
        elements.toast.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => elements.toast.classList.remove('show'), 1800);
    }

    function showFlash(text) {
        elements.flash.textContent = text;
        elements.flash.classList.add('show');
        clearTimeout(flashTimer);
        flashTimer = setTimeout(() => elements.flash.classList.remove('show'), 650);
    }

    function bindPlayerGestures() {
        let startX = 0;
        let startY = 0;
        let startTime = 0;
        let moved = false;
        let longPressTimer = null;
        let longPressFired = false;

        elements.player.addEventListener('pointerdown', (event) => {
            if (event.target.closest('button')) {
                return;
            }

            startX = event.clientX;
            startY = event.clientY;
            startTime = Date.now();
            moved = false;
            longPressFired = false;
            clearTimeout(longPressTimer);
            longPressTimer = setTimeout(() => {
                if (!moved) {
                    longPressFired = true;
                    likeCurrent();
                }
            }, 720);
        });

        elements.player.addEventListener('pointermove', (event) => {
            if (Math.abs(event.clientX - startX) > 16 || Math.abs(event.clientY - startY) > 16) {
                moved = true;
                clearTimeout(longPressTimer);
            }
        });

        elements.player.addEventListener('pointerup', (event) => {
            clearTimeout(longPressTimer);
            if (longPressFired || Date.now() - startTime > 900) {
                return;
            }

            const dx = event.clientX - startX;
            const dy = event.clientY - startY;

            if (Math.abs(dx) > 56 && Math.abs(dx) > Math.abs(dy)) {
                seekPlayer(dx > 0 ? 10 : -10);
                return;
            }

            if (Math.abs(dy) > 56 && Math.abs(dy) > Math.abs(dx)) {
                playAdjacent(dy < 0 ? 1 : -1);
            }
        });

        elements.player.addEventListener('pointercancel', () => {
            clearTimeout(longPressTimer);
        });
    }

    function bindGridPinch() {
        let initialDistance = 0;
        let initialColumns = 3;
        let applied = false;

        elements.grid.addEventListener('touchstart', (event) => {
            if (event.touches.length !== 2) {
                return;
            }

            initialDistance = touchDistance(event.touches[0], event.touches[1]);
            initialColumns = state.gridColumns;
            applied = false;
        }, {passive: true});

        elements.grid.addEventListener('touchmove', (event) => {
            if (event.touches.length !== 2 || initialDistance <= 0) {
                return;
            }

            const distance = touchDistance(event.touches[0], event.touches[1]);
            const ratio = distance / initialDistance;

            if (!applied && ratio > 1.18) {
                state.gridColumns = clamp(initialColumns - 1, 1, 5);
                applied = true;
                applyGridColumns();
            } else if (!applied && ratio < .84) {
                state.gridColumns = clamp(initialColumns + 1, 1, 5);
                applied = true;
                applyGridColumns();
            }
        }, {passive: true});
    }

    function touchDistance(a, b) {
        return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
    }

    async function checkForUpdates(registration = null) {
        try {
            const response = await fetch(`${VERSION_URL}?t=${Date.now()}`, {cache: 'no-store'});
            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            const latest = payload.data?.version;
            if (latest && latest !== appConfig.version) {
                showToast('更新中');
                if (registration) {
                    await registration.update();
                    if (registration.waiting) {
                        registration.waiting.postMessage({type: 'SKIP_WAITING'});
                    }
                }
                setTimeout(() => window.location.reload(), 700);
            }
        } catch (error) {
        }
    }

    window.folderVideoCheckUpdates = () => checkForUpdates();

    async function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        try {
            const registration = await navigator.serviceWorker.register(SW_URL);
            await registration.update();
            checkForUpdates(registration);

            registration.addEventListener('updatefound', () => {
                const worker = registration.installing;
                if (!worker) {
                    return;
                }

                worker.addEventListener('statechange', () => {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        worker.postMessage({type: 'SKIP_WAITING'});
                    }
                });
            });

            navigator.serviceWorker.addEventListener('controllerchange', () => {
                window.location.reload();
            });
        } catch (error) {
        }
    }

    elements.zoomIn.addEventListener('click', () => {
        state.gridColumns = clamp(state.gridColumns - 1, 1, 5);
        applyGridColumns();
    });

    elements.zoomOut.addEventListener('click', () => {
        state.gridColumns = clamp(state.gridColumns + 1, 1, 5);
        applyGridColumns();
    });

    elements.refresh.addEventListener('click', async () => {
        await fetchAppConfig();
        await loadMoreVideos(true);
        checkForUpdates();
    });

    elements.closePlayer.addEventListener('click', closePlayer);
    elements.playerLike.addEventListener('click', likeCurrent);
    elements.playerNext.addEventListener('click', () => playAdjacent(1));
    elements.playerPrev.addEventListener('click', () => playAdjacent(-1));

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            recordPlayerProgress();
            stopAllPreviews();
        } else {
            schedulePreviewRefresh();
            checkForUpdates();
        }
    });

    window.addEventListener('beforeunload', recordPlayerProgress);

    new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            loadMoreVideos();
        }
    }, {rootMargin: '600px 0px'}).observe(elements.sentinel);

    async function boot() {
        applyGridColumns();
        bindPlayerGestures();
        bindGridPinch();
        if (state.currentVideo && !isCompleted(state.currentVideo.id)) {
            upsertVideo(state.currentVideo, true);
            renderGrid();
        }
        await fetchAppConfig();
        await loadMoreVideos(true);
        registerServiceWorker();
    }

    boot();
})();
</script>
</body>
</html>
