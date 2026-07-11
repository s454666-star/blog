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
            --grid-gap: 3px;
            --card-height: 180px;
            --android-nav-inset: 0px;
            --visual-bottom-inset: 0px;
            --bottom-safe: max(env(safe-area-inset-bottom), var(--android-nav-inset), var(--visual-bottom-inset));
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

        button:focus-visible,
        .video-card:focus-visible {
            outline: 4px solid #5fe7ff;
            outline-offset: 3px;
            box-shadow: 0 0 0 7px rgba(95, 231, 255, .24);
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

        .icon-button.is-active {
            border-color: rgba(255, 125, 103, .78);
            background: rgba(255, 125, 103, .22);
            color: var(--coral);
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(var(--columns), minmax(0, 1fr));
            grid-auto-rows: var(--card-height);
            gap: var(--grid-gap);
            padding: var(--grid-gap) var(--grid-gap) calc(var(--grid-gap) + var(--bottom-safe));
            touch-action: pan-y;
        }

        .video-card {
            position: relative;
            min-width: 0;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 6px;
            height: 100%;
            background: #121820;
            cursor: pointer;
            isolation: isolate;
        }

        .video-card .preview-poster,
        .video-card video {
            position: absolute;
            inset: 0;
            display: block;
            width: 100%;
            height: 100%;
            background: #080a0d;
            object-fit: cover;
        }

        .video-card .preview-poster {
            z-index: 0;
        }

        .video-card video {
            z-index: 1;
            opacity: 0;
            transition: opacity 120ms ease;
        }

        .video-card video.has-frame {
            opacity: 1;
        }

        .preview-motion {
            position: absolute;
            inset: 0;
            z-index: 1;
            display: none;
            background-position: 0 50%;
            background-repeat: no-repeat;
            background-size: 800% 100%;
        }

        .preview-motion.active {
            display: block;
            animation: tv-preview-strip 8s steps(7, end) infinite;
        }

        @keyframes tv-preview-strip {
            from { background-position: 0 50%; }
            to { background-position: 100% 50%; }
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
            height: calc(180px + var(--bottom-safe));
        }

        .player {
            position: fixed;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 50;
            display: none;
            background: #000;
            overflow: hidden;
            touch-action: none;
        }

        .player.open {
            display: block;
        }

        .player video {
            position: absolute;
            top: 0;
            right: 0;
            bottom: var(--bottom-safe);
            left: 0;
            width: 100%;
            height: calc(100% - var(--bottom-safe));
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
            bottom: calc(var(--bottom-safe) + 22px);
            z-index: 4;
            display: grid;
            gap: 10px;
        }

        .player-progress {
            position: absolute;
            right: 0;
            bottom: var(--bottom-safe);
            left: 0;
            z-index: 5;
            height: 4px;
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
            bottom: calc(var(--bottom-safe) + 22px);
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
            <button class="icon-button" id="watchedOnlyButton" type="button" title="只看已觀看">✓</button>
            <button class="icon-button" id="likedOnlyButton" type="button" title="只看 LIKE">♥</button>
            <button class="icon-button" id="zoomOutButton" type="button" title="縮小">−</button>
            <button class="icon-button" id="zoomResetButton" type="button" title="回到 3x3">3</button>
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
        <button class="icon-button" id="playerRateButton" type="button" title="播放速度">1x</button>
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
    const MIN_GRID_COLUMNS = 2;
    const DEFAULT_GRID_COLUMNS = 3;
    const MAX_GRID_COLUMNS = 5;
    const GRID_PRESET_VERSION = '3x3-20260707-11';
    const DOUBLE_TAP_MS = 280;
    const DOUBLE_TAP_DISTANCE = 30;
    const PLAYER_HOLD_SEEK_MS = 260;
    const PLAYER_DRAG_SEEK_STEP_PX = 42;
    const PLAYER_DRAG_SEEK_RATIO = .05;
    const PLAYBACK_RATES = [1, 1.25, 1.5, 1.75, 2];
    const MODE_ALL = 'all';
    const MODE_WATCHED = 'watched';
    const MODE_LIKED = 'liked';

    const elements = {
        shell: document.getElementById('appShell'),
        grid: document.getElementById('videoGrid'),
        sentinel: document.getElementById('sentinel'),
        empty: document.getElementById('emptyState'),
        subtitle: document.getElementById('subtitle'),
        status: document.getElementById('statusPill'),
        watchedOnly: document.getElementById('watchedOnlyButton'),
        likedOnly: document.getElementById('likedOnlyButton'),
        zoomIn: document.getElementById('zoomInButton'),
        zoomOut: document.getElementById('zoomOutButton'),
        zoomReset: document.getElementById('zoomResetButton'),
        refresh: document.getElementById('refreshButton'),
        player: document.getElementById('playerOverlay'),
        playerVideo: document.getElementById('playerVideo'),
        playerTitle: document.getElementById('playerTitle'),
        playerProgress: document.getElementById('playerProgress'),
        closePlayer: document.getElementById('closePlayerButton'),
        playerLike: document.getElementById('playerLikeButton'),
        playerPrev: document.getElementById('playerPrevButton'),
        playerRate: document.getElementById('playerRateButton'),
        playerNext: document.getElementById('playerNextButton'),
        toast: document.getElementById('toast'),
        flash: document.getElementById('gestureFlash'),
    };

    let appConfig = Object.assign({
        version: '2026.07.07.16',
        page_limit: 36,
        preview_max_connections: 36,
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
    let tvFocusIndex = 0;
    let saveProgressTimer = null;
    let toastTimer = null;
    let flashTimer = null;
    let previewPaused = false;
    let previewRefreshQueued = false;
    let gridMetricsRefreshQueued = false;
    let loadMoreQueued = false;
    let tvPreviewOffset = 0;
    let stopPlayerDragSeek = () => {};
    const likeInFlight = new Set();
    const previewPreparation = new Map();
    const tvPreviewPreparation = new Map();

    const observer = new IntersectionObserver(handlePreviewIntersections, {
        root: null,
        rootMargin: '300px 0px',
        threshold: [0, .2, .6],
    });
    const visiblePreviewCards = new Map();

    function loadState() {
        try {
            const parsed = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            return Object.assign({
                gridColumns: DEFAULT_GRID_COLUMNS,
                layoutPresetVersion: '',
                progress: {},
                completed: {},
                liked: {},
                pendingLikeActions: {},
                viewMode: parsed.showLikedOnly ? MODE_LIKED : MODE_ALL,
                showLikedOnly: false,
                known: {},
                lastLibraryScanAt: 0,
                currentId: null,
                currentVideo: null,
                playbackRate: 1,
            }, parsed);
        } catch (error) {
            return {
                gridColumns: DEFAULT_GRID_COLUMNS,
                layoutPresetVersion: '',
                progress: {},
                completed: {},
                liked: {},
                pendingLikeActions: {},
                viewMode: MODE_ALL,
                showLikedOnly: false,
                known: {},
                lastLibraryScanAt: 0,
                currentId: null,
                currentVideo: null,
                playbackRate: 1,
            };
        }
    }

    function saveState() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
    }

    function currentMode() {
        if ([MODE_ALL, MODE_WATCHED, MODE_LIKED].includes(state.viewMode)) {
            return state.viewMode;
        }

        return state.showLikedOnly ? MODE_LIKED : MODE_ALL;
    }

    function setMode(mode) {
        state.viewMode = [MODE_ALL, MODE_WATCHED, MODE_LIKED].includes(mode) ? mode : MODE_ALL;
        state.showLikedOnly = state.viewMode === MODE_LIKED;
        saveState();
    }

    function isWatchedMode() {
        return currentMode() === MODE_WATCHED;
    }

    function isLikedMode() {
        return currentMode() === MODE_LIKED;
    }

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function sleep(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    function normalizePlaybackRate(value) {
        const numeric = Number(value || 1);
        const closest = PLAYBACK_RATES.reduce((best, rate) => (
            Math.abs(rate - numeric) < Math.abs(best - numeric) ? rate : best
        ), 1);

        return PLAYBACK_RATES.includes(closest) ? closest : 1;
    }

    function playbackRateLabel(rate = normalizePlaybackRate(state.playbackRate)) {
        return `${Number(rate).toFixed(rate % 1 === 0 ? 0 : 2).replace(/0$/, '')}x`;
    }

    function applyPlaybackRate() {
        const rate = normalizePlaybackRate(state.playbackRate);
        state.playbackRate = rate;
        elements.playerVideo.playbackRate = rate;
        elements.playerRate.textContent = playbackRateLabel(rate);
        elements.playerRate.title = `播放速度 ${playbackRateLabel(rate)}`;
    }

    function cyclePlaybackRate() {
        const current = normalizePlaybackRate(state.playbackRate);
        const index = PLAYBACK_RATES.indexOf(current);
        state.playbackRate = PLAYBACK_RATES[(index + 1) % PLAYBACK_RATES.length];
        saveState();
        applyPlaybackRate();
        showFlash(playbackRateLabel(state.playbackRate));
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

        return `${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
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
            preview_url: toPlayableUrl(video.preview_url || `${API_BASE}/${video.id}/preview`),
            thumbnail_url: toPlayableUrl(video.thumbnail_url || `${API_BASE}/${video.id}/thumbnail`),
            preview_cached: Boolean(video.preview_cached),
            thumbnail_cached: Boolean(video.thumbnail_cached),
            liked: Boolean(video.liked),
        };
    }

    function isCompleted(id) {
        return Boolean(state.completed[id]);
    }

    function videoFromStoredRecord(id, record, fallbackLiked = false) {
        const stored = record?.video || {};

        return normalizeVideo({
            id,
            filename: stored.filename || record?.filename || id,
            duration_seconds: stored.duration_seconds || record?.duration || 0,
            duration_label: stored.duration_label,
            size_bytes: stored.size_bytes || 0,
            modified_at: stored.modified_at || 0,
            created_at: stored.created_at || 0,
            stream_url: stored.stream_url || `${API_BASE}/${id}/stream`,
            preview_url: stored.preview_url || `${API_BASE}/${id}/preview`,
            thumbnail_url: stored.thumbnail_url || `${API_BASE}/${id}/thumbnail`,
            preview_cached: Boolean(stored.preview_cached),
            thumbnail_cached: Boolean(stored.thumbnail_cached),
            liked: fallbackLiked || Boolean(stored.liked),
        });
    }

    function completedVideos() {
        return Object.entries(state.completed || {})
            .map(([id, record]) => videoFromStoredRecord(id, record, isLiked(id)))
            .sort((left, right) => {
                const leftAt = Number(state.completed?.[left.id]?.completedAt || 0);
                const rightAt = Number(state.completed?.[right.id]?.completedAt || 0);

                return rightAt - leftAt;
            });
    }

    function isLiked(id) {
        return Boolean(state.liked?.[id]);
    }

    function rememberLikedVideo(video, likedAt = Date.now()) {
        const normalized = normalizeVideo(video);
        if (!normalized.id) {
            return;
        }

        state.liked = state.liked || {};
        state.liked[normalized.id] = {
            filename: normalized.filename,
            likedAt,
            video: normalized,
        };
    }

    function forgetLikedVideo(id) {
        if (!state.liked) {
            return;
        }

        delete state.liked[id];
    }

    function pendingLikeAction(id) {
        return state.pendingLikeActions?.[id] || null;
    }

    function rememberPendingLikeAction(video, liked) {
        const normalized = normalizeVideo(video);
        if (!normalized.id) {
            return;
        }

        state.pendingLikeActions = state.pendingLikeActions || {};
        state.pendingLikeActions[normalized.id] = {
            action: liked ? 'like' : 'unlike',
            queuedAt: Date.now(),
            mode: currentMode(),
            video: Object.assign({}, normalized, {liked}),
        };
    }

    function forgetPendingLikeAction(id) {
        if (!state.pendingLikeActions) {
            return;
        }

        delete state.pendingLikeActions[id];
    }

    function upsertVideo(video, placeFirst = false) {
        const normalized = normalizeVideo(video);
        if (!normalized.id) {
            return;
        }

        if (!state.known?.[normalized.id]) {
            freshVideoIds.add(normalized.id);
        }

        if (normalized.liked) {
            rememberLikedVideo(normalized);
        } else if (isLikedMode() && !isLiked(normalized.id)) {
            normalized.liked = true;
            rememberLikedVideo(normalized);
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

    function displayVideos() {
        return videos.filter((video) => {
            if (isWatchedMode()) {
                return isCompleted(video.id);
            }

            if (isLikedMode()) {
                return isLiked(video.id);
            }

            return !isCompleted(video.id);
        })
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

    function normalizeGridColumns(value) {
        return clamp(Math.round(Number(value || DEFAULT_GRID_COLUMNS)), MIN_GRID_COLUMNS, MAX_GRID_COLUMNS);
    }

    function setGridColumns(value) {
        const next = normalizeGridColumns(value);
        if (Number(state.gridColumns) === next) {
            updateGridMetrics();
            return;
        }

        state.gridColumns = next;
        state.layoutPresetVersion = GRID_PRESET_VERSION;
        applyGridColumns();
    }

    function ensureDefaultGridPreset() {
        if (state.layoutPresetVersion === GRID_PRESET_VERSION) {
            return;
        }

        state.gridColumns = DEFAULT_GRID_COLUMNS;
        state.layoutPresetVersion = GRID_PRESET_VERSION;
        saveState();
    }

    function rowsForGridColumns(columns) {
        const normalized = normalizeGridColumns(columns);
        if (normalized <= 2) {
            return 3;
        }
        if (normalized === 3) {
            return 3;
        }
        if (normalized === 4) {
            return 5;
        }

        return 7;
    }

    function setCssPxVariable(name, value) {
        const pixels = Math.max(0, Math.round(Number(value || 0)));
        document.documentElement.style.setProperty(name, `${pixels}px`);
    }

    function cssPxVariable(name) {
        const value = getComputedStyle(document.documentElement).getPropertyValue(name);
        const parsed = Number.parseFloat(value);

        return Number.isFinite(parsed) ? Math.max(0, parsed) : 0;
    }

    function isFolderVideoAndroidApp() {
        const userAgent = navigator.userAgent || '';

        return /Android/i.test(userAgent) && /FolderVideoApp\//.test(userAgent);
    }

    function isFolderVideoTvApp() {
        return /Android/i.test(navigator.userAgent || '') && /FolderVideoTvApp\//.test(navigator.userAgent || '');
    }

    function rawViewportHeight() {
        return Number(window.visualViewport?.height || window.innerHeight || 0);
    }

    function androidNavigationFallbackInset(value = 0) {
        const bottom = Math.max(0, Number(value || 0));
        if (bottom > 0 || !isFolderVideoAndroidApp()) {
            return bottom;
        }

        return clamp(Math.round(rawViewportHeight() * .065), 48, 76);
    }

    function refreshViewportInsets() {
        const viewport = window.visualViewport;
        const visualBottom = viewport
            ? Math.max(0, (window.innerHeight || 0) - viewport.height - viewport.offsetTop)
            : 0;

        setCssPxVariable('--visual-bottom-inset', visualBottom);
    }

    function bottomSafeInset() {
        refreshViewportInsets();

        return Math.max(
            cssPxVariable('--android-nav-inset'),
            cssPxVariable('--visual-bottom-inset')
        );
    }

    window.folderVideoSetAndroidInsets = (payload = {}) => {
        const bottom = typeof payload === 'number' ? payload : payload.bottom;
        setCssPxVariable('--android-nav-inset', androidNavigationFallbackInset(bottom));
        updateGridMetrics();
        schedulePreviewRefresh();
    };

    function updateGridMetrics() {
        const rows = rowsForGridColumns(state.gridColumns);
        const viewportHeight = rawViewportHeight();
        const viewportOffsetTop = Number(window.visualViewport?.offsetTop || 0);
        const gridTop = Math.max(0, (elements.grid.getBoundingClientRect().top || 0) - viewportOffsetTop);
        const gap = 3;
        const available = Math.max(240, viewportHeight - gridTop - gap - rows * gap);
        const rowHeight = Math.max(76, Math.ceil(available / rows));

        document.documentElement.style.setProperty('--card-height', `${rowHeight}px`);
    }

    function scheduleGridMetricsRefresh() {
        if (gridMetricsRefreshQueued) {
            return;
        }

        gridMetricsRefreshQueued = true;
        window.requestAnimationFrame(() => {
            gridMetricsRefreshQueued = false;
            updateGridMetrics();
            schedulePreviewRefresh();
        });
    }

    function applyGridColumns() {
        state.gridColumns = normalizeGridColumns(state.gridColumns);
        state.layoutPresetVersion = GRID_PRESET_VERSION;
        document.documentElement.style.setProperty('--columns', String(state.gridColumns));
        updateGridMetrics();
        saveState();
        schedulePreviewRefresh();
    }

    function updateStatus() {
        const visible = displayVideos().length;
        const liked = Object.keys(state.liked || {}).length;
        const completed = Object.keys(state.completed || {}).length;
        if (isWatchedMode()) {
            elements.subtitle.textContent = `${visible} 已觀看 · ${liked} LIKE`;
        } else if (isLikedMode()) {
            elements.subtitle.textContent = `${visible} LIKE · ${completed} 已看`;
        } else {
            elements.subtitle.textContent = `${visible} 可播放 · ${liked} LIKE · ${completed} 已看`;
        }
        elements.status.textContent = `v${appConfig.version}`;
        elements.watchedOnly.classList.toggle('is-active', isWatchedMode());
        elements.likedOnly.classList.toggle('is-active', isLikedMode());
    }

    function emptyMessage() {
        if (isWatchedMode()) {
            return '沒有已觀看影片';
        }

        if (isLikedMode()) {
            return '沒有 LIKE 影片';
        }

        return '沒有可播放的影片';
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

        const items = displayVideos();
        const fragment = document.createDocumentFragment();

        items.forEach((video) => {
            const card = document.createElement('article');
            card.className = 'video-card';
            card.dataset.id = video.id;
            card.tabIndex = 0;
            card.setAttribute('role', 'button');
            card.setAttribute('aria-label', `播放 ${video.filename}`);
            const previewSrc = video.preview_cached ? video.preview_url : '';
            const posterSrc = video.thumbnail_url || '';
            card.innerHTML = `
                <img class="preview-poster" src="${escapeHtml(posterSrc)}" alt="" loading="lazy" decoding="async">
                <div class="preview-motion" aria-hidden="true"></div>
                <video class="preview-video" muted loop playsinline preload="none" poster="${escapeHtml(posterSrc)}" data-src="${escapeHtml(previewSrc)}" data-preview-src="${escapeHtml(video.preview_url || '')}" data-stream-src="${escapeHtml(video.stream_url || '')}"></video>
                <div class="card-meta">
                    <div class="card-name">${escapeHtml(video.filename)}</div>
                    <div class="card-row">
                        <span class="preview-time">${escapeHtml(progressPercent(video) > 0 ? formatDuration(Number(state.progress?.[video.id]?.time || 0)) : '00:00')}</span>
                        <span class="progress-shell"><span class="progress-fill" style="width:${progressPercent(video)}%"></span></span>
                    </div>
                </div>
            `;

            if (state.currentId === video.id) {
                card.classList.add('is-current');
            }

            if (isLiked(video.id)) {
                card.classList.add('is-liked');
            }

            bindCardGestures(card, video);
            card.addEventListener('keydown', event => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                openPlayer(video);
            });
            card.addEventListener('focus', () => {
                const cards = Array.from(elements.grid.querySelectorAll('.video-card'));
                tvFocusIndex = Math.max(0, cards.indexOf(card));
            });
            observer.observe(card);
            fragment.appendChild(card);
        });

        elements.grid.appendChild(fragment);
        elements.empty.textContent = emptyMessage();
        elements.empty.classList.toggle('show', items.length === 0 && !cursor.hasMore && !isLoading);
        updateStatus();
        schedulePreviewRefresh();
        maybeLoadMoreSoon();
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

        if (isFolderVideoTvApp()) {
            ensureTvSpritePreview(card);
            return;
        }

        const video = card.querySelector('video');
        if (!video) {
            return;
        }
        if (!video.dataset.src) {
            ensureCardPreview(card);
            return;
        }
        if (video.dataset.activePreview === '1') {
            return;
        }

        bindPreviewVideoEvents(video);
        video.dataset.activePreview = '1';
        video.preload = 'auto';
        if (!video.getAttribute('src')) {
            video.setAttribute('src', video.dataset.src);
            video.load();
        }

        video.muted = true;
        video.defaultMuted = true;
        video.disableRemotePlayback = true;
        video.play().catch(() => {});
    }

    async function ensureTvSpritePreview(card) {
        const id = String(card?.dataset?.id || '');
        const motion = card?.querySelector('.preview-motion');
        if (!id || !motion || motion.dataset.preparing === '1') return;
        if (motion.dataset.src) {
            motion.style.backgroundImage = `url("${motion.dataset.src}")`;
            motion.classList.add('active');
            return;
        }
        motion.dataset.preparing = '1';
        let preparation = tvPreviewPreparation.get(id);
        if (!preparation) {
            preparation = prepareTvPreview(id);
            tvPreviewPreparation.set(id, preparation);
        }
        const result = await preparation.catch(() => null);
        if (!result) tvPreviewPreparation.delete(id);
        if (!card.isConnected) return;
        delete motion.dataset.preparing;
        if (!result?.preview_url) return;
        motion.dataset.src = result.preview_url;
        motion.style.backgroundImage = `url("${result.preview_url}")`;
        if (visiblePreviewCards.has(card)) motion.classList.add('active');
    }

    async function prepareTvPreview(id) {
        const encodedId = encodeURIComponent(id);
        let response = await fetch(`${API_BASE}/${encodedId}/tv-preview-queue`, {
            method: 'POST', cache: 'no-store', headers: {'Accept': 'application/json'},
        });
        if (!response.ok) return null;
        let payload = await response.json();
        if (payload.data?.ready) return payload.data;
        for (let attempt = 0; attempt < 90; attempt++) {
            await sleep(1000);
            response = await fetch(`${API_BASE}/${encodedId}/tv-preview-status?t=${Date.now()}`, {
                cache: 'no-store', headers: {'Accept': 'application/json'},
            });
            if (!response.ok) continue;
            payload = await response.json();
            if (payload.data?.ready) return payload.data;
        }
        return null;
    }

    async function ensureCardPreview(card) {
        const id = String(card?.dataset?.id || '');
        const video = card?.querySelector('video');
        if (!id || !video || video.dataset.previewPreparing === '1') return;
        video.dataset.previewPreparing = '1';

        let preparation = previewPreparation.get(id);
        if (!preparation) {
            preparation = preparePreview(id);
            previewPreparation.set(id, preparation);
        }

        const ready = await preparation.catch(() => false);
        if (!ready) previewPreparation.delete(id);
        if (!card.isConnected) return;
        delete video.dataset.previewPreparing;
        if (!ready) return;

        video.dataset.src = video.dataset.previewSrc || `${API_BASE}/${encodeURIComponent(id)}/preview`;
        if (video.dataset.previewWanted === '1' && visiblePreviewCards.has(card)) {
            startPreview(card);
        }
    }

    async function preparePreview(id) {
        const encodedId = encodeURIComponent(id);
        const queueResponse = await fetch(`${API_BASE}/${encodedId}/preview-queue`, {
            method: 'POST',
            cache: 'no-store',
            headers: {'Accept': 'application/json'},
        });
        if (!queueResponse.ok) return false;
        let payload = await queueResponse.json();
        if (payload.data?.ready) return true;

        for (let attempt = 0; attempt < 90; attempt++) {
            await sleep(1500);
            const statusResponse = await fetch(`${API_BASE}/${encodedId}/preview-status?t=${Date.now()}`, {
                cache: 'no-store',
                headers: {'Accept': 'application/json'},
            });
            if (!statusResponse.ok) continue;
            payload = await statusResponse.json();
            if (payload.data?.ready) return true;
        }
        return false;
    }

    function scheduleStartPreview(card, order = 0) {
        if (previewPaused) {
            return;
        }

        const video = card.querySelector('video');
        if (!video) {
            return;
        }

        video.dataset.previewWanted = '1';
        if (video.dataset.activePreview === '1') {
            startPreview(card);
            return;
        }

        if (video.dataset.previewStartQueued === '1') {
            return;
        }

        const delay = Math.min(120, Math.max(0, order) * 12);
        if (delay <= 0) {
            startPreview(card);
            return;
        }

        video.dataset.previewStartQueued = '1';
        window.setTimeout(() => {
            delete video.dataset.previewStartQueued;
            if (video.dataset.previewWanted === '1' && visiblePreviewCards.has(card)) {
                startPreview(card);
            }
        }, delay);
    }

    function warmPreview(card) {
        const video = card.querySelector('video');
        if (!video || !video.dataset.src || video.getAttribute('src')) {
            return;
        }

        bindPreviewVideoEvents(video);
        video.preload = 'metadata';
        video.muted = true;
        video.defaultMuted = true;
        video.disableRemotePlayback = true;
        video.setAttribute('src', video.dataset.src);
        video.load();
    }

    function bindPreviewVideoEvents(video) {
        if (!video || video.dataset.previewEventsBound === '1') {
            return;
        }

        video.dataset.previewEventsBound = '1';
        video.addEventListener('loadedmetadata', () => updatePreviewProgress(video));
        video.addEventListener('loadeddata', () => video.classList.add('has-frame'));
        video.addEventListener('durationchange', () => updatePreviewProgress(video));
        video.addEventListener('timeupdate', () => updatePreviewProgress(video));
        video.addEventListener('playing', () => {
            video.classList.add('has-frame');
            updatePreviewProgress(video);
        });
        video.addEventListener('seeked', () => updatePreviewProgress(video));
        video.addEventListener('error', () => {
            video.classList.remove('has-frame');
            video.removeAttribute('src');
            video.load();
        });
    }

    function updatePreviewProgress(video) {
        const card = video.closest('.video-card');
        if (!card) {
            return;
        }

        const current = Math.max(0, Number(video.currentTime || 0));
        const duration = Number(video.duration || 0);
        const time = card.querySelector('.preview-time');
        const fill = card.querySelector('.progress-fill');

        if (time) {
            time.textContent = formatDuration(current);
        }

        if (fill && Number.isFinite(duration) && duration > 0) {
            fill.style.width = `${clamp((current / duration) * 100, 0, 100)}%`;
        }
    }

    function pausePreview(card) {
        card.querySelector('.preview-motion')?.classList.remove('active');
        const video = card.querySelector('video');
        if (!video) {
            return;
        }

        video.dataset.previewWanted = '0';
        video.dataset.activePreview = '0';
        try {
            video.pause();
        } catch (error) {
        }
    }

    function stopPreview(card) {
        card.querySelector('.preview-motion')?.classList.remove('active');
        const video = card.querySelector('video');
        if (!video) {
            return;
        }

        pausePreview(card);
        video.classList.remove('has-frame');
        video.removeAttribute('src');
        video.load();
    }

    function stopAllPreviews() {
        document.querySelectorAll('.video-card').forEach(stopPreview);
    }

    function schedulePreviewRefresh() {
        if (previewRefreshQueued) {
            return;
        }

        previewRefreshQueued = true;
        window.requestAnimationFrame(() => {
            previewRefreshQueued = false;
            refreshPreviews();
        });
    }

    function collectPreviewEntries() {
        const viewportHeight = Math.max(160, rawViewportHeight());
        const preloadBand = Math.max(160, Math.min(360, viewportHeight * .45));

        return Array.from(document.querySelectorAll('.video-card'))
            .map((card) => {
                const rect = card.getBoundingClientRect();
                const visiblePixels = Math.min(rect.bottom, viewportHeight) - Math.max(rect.top, 0);
                const visible = visiblePixels > 2;
                const near = rect.bottom > -preloadBand && rect.top < viewportHeight + preloadBand;

                if (!near) {
                    visiblePreviewCards.delete(card);

                    return null;
                }

                const ratio = visible
                    ? clamp(visiblePixels / Math.max(1, rect.height || 1), 0, 1)
                    : .01;
                const below = rect.top >= viewportHeight;
                const band = visible ? 0 : (below ? 1 : 2);
                const distance = visible
                    ? Math.max(0, rect.top)
                    : (below ? rect.top - viewportHeight : Math.abs(rect.bottom));

                visiblePreviewCards.set(card, ratio);

                return {card, band, distance, left: rect.left};
            })
            .filter(Boolean)
            .sort((left, right) => (left.band - right.band) || (left.distance - right.distance) || (left.left - right.left));
    }

    function refreshPreviews() {
        if (previewPaused || document.hidden) {
            stopAllPreviews();
            return;
        }

        const cards = collectPreviewEntries().map((entry) => entry.card);

        if (cards.length === 0) {
            stopAllPreviews();
            return;
        }

        const desiredActive = desiredVisibleCount();
        const decoderLimit = isFolderVideoTvApp() ? 4 : 8;
        const maxActive = clamp(Math.min(Number(appConfig.preview_max_connections || 36), desiredActive, cards.length, decoderLimit), 1, decoderLimit);
        const activeCards = new Set();

        for (let offset = 0; offset < Math.min(maxActive, cards.length); offset++) {
            const index = isFolderVideoTvApp()
                ? (tvPreviewOffset + offset) % cards.length
                : offset;
            activeCards.add(cards[index]);
        }

        document.querySelectorAll('.video-card').forEach((card) => {
            if (!activeCards.has(card) && !visiblePreviewCards.has(card)) {
                stopPreview(card);
            }
        });

        cards.forEach((card, index) => {
            if (activeCards.has(card)) {
                scheduleStartPreview(card, index);
            } else {
                pausePreview(card);
            }
        });
    }

    function desiredVisibleCount() {
        return normalizeGridColumns(state.gridColumns) * rowsForGridColumns(state.gridColumns);
    }

    function bufferedTargetCount() {
        return Math.min(72, Math.max(Number(appConfig.page_limit || 36), desiredVisibleCount() * 2));
    }

    function maybeLoadMoreSoon() {
        if (isLoading || isWatchedMode() || isLikedMode() || !cursor.hasMore || loadMoreQueued) {
            return;
        }

        const viewportHeight = Math.max(160, rawViewportHeight());
        const distanceToBottom = document.documentElement.scrollHeight - (window.scrollY + viewportHeight);

        if (displayVideos().length < bufferedTargetCount() || distanceToBottom < viewportHeight * 2.5) {
            loadMoreQueued = true;
            window.setTimeout(async () => {
                loadMoreQueued = false;
                await loadMoreVideos();
            }, 80);
        }
    }

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

        if (isWatchedMode()) {
            cursor = {offset: 0, hasMore: false};
            videos = completedVideos();
            videoById = new Map(videos.map((video) => [video.id, video]));
            renderGrid();
            updateStatus();
            elements.empty.textContent = emptyMessage();
            elements.empty.classList.toggle('show', displayVideos().length === 0);
            return;
        }

        isLoading = true;
        elements.subtitle.textContent = '載入中';

        if (reset) {
            cursor = {offset: 0, hasMore: true};
            videos = [];
            videoById = new Map();
            if (state.currentVideo && (isLikedMode() ? isLiked(state.currentVideo.id) : !isCompleted(state.currentVideo.id))) {
                upsertVideo(state.currentVideo, true);
            }
        }

        const params = new URLSearchParams({
            limit: String(appConfig.page_limit || 36),
            offset: String(cursor.offset || 0),
            order: 'random_new_first',
            seed: sessionSeed,
            new_first_after: String(sessionNewFirstAfter),
            liked: isLikedMode() ? '1' : '0',
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

            if (!isLikedMode() && displayVideos().length < bufferedTargetCount() && cursor.hasMore) {
                await loadMoreVideos();
            }
        } catch (error) {
            showToast('載入失敗');
        } finally {
            isLoading = false;
            updateStatus();
            elements.empty.textContent = emptyMessage();
            elements.empty.classList.toggle('show', displayVideos().length === 0 && !cursor.hasMore);
            maybeLoadMoreSoon();
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

    function notifyFolderVideoTvPlayer(open) {
        try {
            if (
                window.FolderVideoTvAndroid
                && typeof window.FolderVideoTvAndroid.setPlayerOpen === 'function'
            ) {
                window.FolderVideoTvAndroid.setPlayerOpen(Boolean(open));
            }
        } catch (error) {
        }
    }

    function openPlayer(video) {
        const normalized = normalizeVideo(video);
        upsertVideo(normalized);
        playerItem = normalized;
        playerIndex = displayVideos().findIndex((item) => item.id === normalized.id);
        rememberCurrent(normalized);
        previewPaused = true;
        stopAllPreviews();

        elements.player.classList.add('open');
        elements.player.setAttribute('aria-hidden', 'false');
        notifyFolderVideoTvPlayer(true);
        elements.playerTitle.textContent = normalized.filename;
        elements.playerLike.classList.toggle('is-active', isLiked(normalized.id));
        elements.playerLike.title = isLiked(normalized.id) ? '取消 LIKE' : '喜歡';
        elements.playerVideo.pause();
        elements.playerVideo.removeAttribute('src');
        elements.playerVideo.load();
        elements.playerVideo.preload = 'auto';
        elements.playerVideo.disableRemotePlayback = true;
        const saved = Number(state.progress?.[normalized.id]?.time || 0);
        try {
            if (
                window.FolderVideoTvAndroid
                && typeof window.FolderVideoTvAndroid.playVideo === 'function'
            ) {
                if (isFolderVideoTvApp()) {
                    playTvOptimizedStream(normalized, saved);
                } else {
                    window.FolderVideoTvAndroid.playVideo(normalized.stream_url, saved);
                }
                return;
            }
        } catch (error) {
        }
        elements.playerVideo.src = normalized.stream_url;
        elements.playerVideo.loop = false;
        applyPlaybackRate();
        elements.playerVideo.onloadedmetadata = () => {
            applyPlaybackRate();
            const duration = Number(elements.playerVideo.duration || normalized.duration_seconds || 0);
            if (saved > 0 && (!duration || saved < duration - 2)) {
                elements.playerVideo.currentTime = saved;
            }
            elements.playerVideo.play().catch(() => {});
        };
        elements.playerVideo.load();
    }

    async function playTvOptimizedStream(video, saved) {
        showToast('正在準備流暢播放…', 5000);
        const encodedId = encodeURIComponent(video.id);
        try {
            let response = await fetch(`${API_BASE}/${encodedId}/tv-hls-queue`, {
                method: 'POST', cache: 'no-store', headers: {'Accept': 'application/json'},
            });
            let payload = response.ok ? await response.json() : null;
            for (let attempt = 0; !payload?.data?.ready && attempt < 120; attempt++) {
                await sleep(1000);
                if (playerItem?.id !== video.id) return;
                response = await fetch(`${API_BASE}/${encodedId}/tv-hls-status?t=${Date.now()}`, {
                    cache: 'no-store', headers: {'Accept': 'application/json'},
                });
                if (response.ok) payload = await response.json();
            }
            if (playerItem?.id !== video.id) return;
            const streamUrl = payload?.data?.ready ? payload.data.stream_url : video.stream_url;
            const available = Number(payload?.data?.available_seconds || 0);
            const startAt = available > 8 ? Math.min(saved, available - 8) : 0;
            window.FolderVideoTvAndroid.playVideo(streamUrl, startAt);
        } catch (error) {
            if (playerItem?.id === video.id) {
                window.FolderVideoTvAndroid.playVideo(video.stream_url, saved);
            }
        }
    }

    async function closePlayer() {
        const closingId = playerItem?.id || null;
        recordPlayerProgress();
        stopPlayerDragSeek();
        elements.player.classList.remove('open');
        elements.player.setAttribute('aria-hidden', 'true');
        notifyFolderVideoTvPlayer(false);
        try {
            if (
                window.FolderVideoTvAndroid
                && typeof window.FolderVideoTvAndroid.stopVideo === 'function'
            ) {
                window.FolderVideoTvAndroid.stopVideo();
            }
        } catch (error) {
        }
        elements.playerVideo.pause();
        elements.playerVideo.removeAttribute('src');
        elements.playerVideo.load();
        if (closingId) {
            await flushPendingLikeAction(closingId);
        }
        previewPaused = false;
        schedulePreviewRefresh();
    }

    window.folderVideoHandleBack = () => {
        if (elements.player.classList.contains('open')) {
            closePlayer();
            return true;
        }

        return false;
    };

    function focusTvVideoCard(index = tvFocusIndex) {
        const cards = Array.from(elements.grid.querySelectorAll('.video-card'));
        if (cards.length === 0) return false;
        tvFocusIndex = clamp(Number(index) || 0, 0, cards.length - 1);
        const card = cards[tvFocusIndex];
        card.focus({preventScroll: true});
        card.scrollIntoView({block: 'center', inline: 'center', behavior: 'smooth'});
        return true;
    }

    function tvGridColumnCount() {
        const columns = getComputedStyle(elements.grid).gridTemplateColumns
            .split(' ')
            .filter(Boolean).length;
        return Math.max(1, columns || 1);
    }

    window.folderVideoTvHandleKey = key => {
        if (!elements.player.classList.contains('open')) {
            if (key === 'center') {
                const cards = Array.from(elements.grid.querySelectorAll('.video-card'));
                const card = cards[clamp(tvFocusIndex, 0, Math.max(0, cards.length - 1))];
                const video = card ? videoById.get(card.dataset.id) : null;
                if (video) openPlayer(video);
                return Boolean(video);
            }
            const columns = tvGridColumnCount();
            if (key === 'left') return focusTvVideoCard(tvFocusIndex - 1);
            if (key === 'right') return focusTvVideoCard(tvFocusIndex + 1);
            if (key === 'up') return focusTvVideoCard(tvFocusIndex - columns);
            if (key === 'down') return focusTvVideoCard(tvFocusIndex + columns);
            return false;
        }
        if (key === 'left') {
            seekPlayer(-5, '-5s');
            return true;
        }
        if (key === 'right') {
            seekPlayer(5, '+5s');
            return true;
        }
        if (key === 'up') {
            playAdjacent(1);
            return true;
        }
        if (key === 'down') {
            playAdjacent(-1);
            return true;
        }
        if (key === 'center') {
            if (elements.playerVideo.paused) {
                elements.playerVideo.play().catch(() => {});
                showFlash('播放');
            } else {
                elements.playerVideo.pause();
                showFlash('暫停');
            }
            return true;
        }
        return false;
    };

    window.folderVideoTvNativeEnded = async () => {
        if (!playerItem) return;
        markCompleted(playerItem);
        renderGrid();
        await playAdjacent(1);
    };

    window.folderVideoTvNativeClosed = () => {
        if (elements.player.classList.contains('open')) closePlayer();
    };

    window.folderVideoTvNativeError = () => showToast('原生播放器無法播放這支影片');

    window.folderVideoTvNativeProgress = (seconds, duration) => {
        if (!playerItem) return;
        const safeSeconds = Math.max(0, Number(seconds) || 0);
        const safeDuration = Math.max(0, Number(duration) || Number(playerItem.duration_seconds) || 0);
        state.progress[playerItem.id] = {
            time: safeSeconds,
            duration: safeDuration,
            updatedAt: Date.now(),
        };
        saveState();
        updateCardProgress(playerItem.id);
    };

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
        const normalized = normalizeVideo(video);
        state.completed[normalized.id] = {
            filename: normalized.filename,
            completedAt: Date.now(),
            video: normalized,
        };
        delete state.progress[video.id];
        saveState();
    }

    async function findNextPlayable(delta) {
        let queue = displayVideos();
        let current = playerItem ? queue.findIndex((video) => video.id === playerItem.id) : playerIndex;
        if (current < 0) {
            current = delta > 0 ? Math.max(-1, playerIndex) : queue.length;
        }

        for (let index = current + delta; index >= 0 && index < queue.length; index += delta) {
            return queue[index];
        }

        if (delta > 0 && cursor.hasMore) {
            await loadMoreVideos();
            queue = displayVideos();
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
        const leavingId = playerItem?.id || null;
        recordPlayerProgress();
        const next = await findNextPlayable(delta);
        if (!next) {
            showToast(delta > 0 ? '已經到底' : '已經到最前面');
            return;
        }

        if (leavingId) {
            await flushPendingLikeAction(leavingId);
        }
        openPlayer(next);
    }

    function updateLikeVisuals(id) {
        const liked = isLiked(id);
        const card = elements.grid.querySelector(`.video-card[data-id="${CSS.escape(id)}"]`);
        if (card) {
            card.classList.toggle('is-liked', liked);
        }

        if (playerItem && playerItem.id === id) {
            elements.playerLike.classList.toggle('is-active', liked);
            elements.playerLike.title = liked ? '取消 LIKE' : '喜歡';
        }
    }

    async function releaseVideoBeforeMove(id) {
        const card = elements.grid.querySelector(`.video-card[data-id="${CSS.escape(id)}"]`);
        if (card) {
            stopPreview(card);
        }

        if (!playerItem || playerItem.id !== id) {
            await sleep(180);
            return null;
        }

        const snapshot = {
            time: Number(elements.playerVideo.currentTime || 0),
            shouldResume: !elements.playerVideo.paused,
            wasOpen: elements.player.classList.contains('open'),
        };

        try {
            elements.playerVideo.pause();
            elements.playerVideo.removeAttribute('src');
            elements.playerVideo.load();
        } catch (error) {
        }

        await sleep(320);

        return snapshot;
    }

    function mergeMovedVideoRecords(originalId, returned) {
        if (state.completed?.[originalId]) {
            const completedRecord = state.completed[originalId];
            delete state.completed[originalId];
            state.completed[returned.id] = Object.assign({}, completedRecord, {
                filename: returned.filename,
                video: returned,
            });
        }

        if (state.progress?.[originalId] && originalId !== returned.id) {
            const progressRecord = state.progress[originalId];
            delete state.progress[originalId];
            state.progress[returned.id] = progressRecord;
        }
    }

    function syncCollectionAfterLike(target, returned, liked, modeAtStart, previousPlayerIndex) {
        const shouldKeep = (
            (modeAtStart === MODE_WATCHED && isCompleted(returned.id))
            || (modeAtStart === MODE_LIKED && liked)
            || (modeAtStart === MODE_ALL && !liked)
        );

        videos = videos.filter((item) => item.id !== target.id && item.id !== returned.id);
        videoById.delete(target.id);
        videoById.delete(returned.id);

        if (shouldKeep) {
            videos.push(returned);
            videoById.set(returned.id, returned);
        }

        const removedFromCurrentQueue = (modeAtStart === MODE_ALL && liked) || (modeAtStart === MODE_LIKED && !liked);
        if (removedFromCurrentQueue && playerItem && (playerItem.id === target.id || playerItem.id === returned.id)) {
            playerIndex = previousPlayerIndex - 1;
        }
    }

    function updateCurrentPlayerRecord(target, returned, liked) {
        if (!playerItem || playerItem.id !== target.id) {
            return;
        }

        playerItem = Object.assign({}, playerItem, returned, {liked});
        state.currentId = playerItem.id;
        state.currentVideo = playerItem;
        elements.playerTitle.textContent = playerItem.filename;
    }

    function applyDeferredLikeVisualState(target, liked) {
        const optimistic = Object.assign({}, target, {liked});
        if (liked) {
            rememberLikedVideo(optimistic);
        } else {
            forgetLikedVideo(target.id);
        }

        rememberPendingLikeAction(optimistic, liked);

        if (playerItem && playerItem.id === target.id) {
            playerItem = Object.assign({}, playerItem, {liked});
            state.currentVideo = playerItem;
        }

        if (videoById.has(target.id)) {
            Object.assign(videoById.get(target.id), {liked});
        }

        saveState();
        updateLikeVisuals(target.id);
        updateStatus();
        showFlash(liked ? '♥' : '♡');
        showToast(liked ? '已加入 LIKE' : '已取消 LIKE');
    }

    function togglePlayerLikeDeferred(video) {
        const target = normalizeVideo(video || playerItem);
        if (!target.id || likeInFlight.has(target.id)) {
            return;
        }

        applyDeferredLikeVisualState(target, !isLiked(target.id));
    }

    async function flushPendingLikeAction(id) {
        const pending = pendingLikeAction(id);
        if (!pending || likeInFlight.has(id)) {
            return null;
        }

        const liked = pending.action !== 'unlike';
        const target = normalizeVideo(pending.video || videoById.get(id) || playerItem || {id});
        const modeAtStart = currentMode();
        const previousPlayerIndex = playerIndex;
        const endpoint = `${API_BASE}/${encodeURIComponent(id)}/like`;

        try {
            likeInFlight.add(id);
            await releaseVideoBeforeMove(id);
            const response = await fetch(endpoint, {method: liked ? 'POST' : 'DELETE'});
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            const returned = normalizeVideo(Object.assign({}, target, payload.data || {}, {
                liked,
                duration_seconds: target.duration_seconds,
                duration_label: target.duration_label,
            }));

            mergeMovedVideoRecords(id, returned);
            if (liked) {
                forgetLikedVideo(id);
                rememberLikedVideo(returned, pending.queuedAt || Date.now());
            } else {
                forgetLikedVideo(id);
                forgetLikedVideo(returned.id);
            }

            updateCurrentPlayerRecord(target, returned, liked);
            syncCollectionAfterLike(target, returned, liked, modeAtStart, previousPlayerIndex);
            forgetPendingLikeAction(id);
            if (returned.id !== id) {
                forgetPendingLikeAction(returned.id);
            }

            saveState();
            renderGrid();
            updateLikeVisuals(id);
            updateLikeVisuals(returned.id);

            return returned;
        } catch (error) {
            showToast(liked ? 'LIKE 儲存失敗' : '取消 LIKE 儲存失敗');
            return null;
        } finally {
            likeInFlight.delete(id);
        }
    }

    async function flushPendingLikeActions() {
        const ids = Object.keys(state.pendingLikeActions || {});
        for (const id of ids) {
            await flushPendingLikeAction(id);
        }
    }

    async function toggleLike(video) {
        const target = normalizeVideo(video || playerItem);
        if (!target.id) {
            return;
        }

        if (playerItem && playerItem.id === target.id && elements.player.classList.contains('open')) {
            togglePlayerLikeDeferred(target);
            return;
        }

        if (likeInFlight.has(target.id)) {
            return;
        }

        const wasLiked = isLiked(target.id);
        const previousPlayerIndex = playerIndex;
        const modeAtStart = currentMode();
        const removedFromCurrentQueue = (modeAtStart === MODE_ALL && !wasLiked) || (modeAtStart === MODE_LIKED && wasLiked);
        const endpoint = `${API_BASE}/${encodeURIComponent(target.id)}/like`;
        let releasedPlayer = null;

        try {
            likeInFlight.add(target.id);
            releasedPlayer = await releaseVideoBeforeMove(target.id);
            const response = await fetch(endpoint, {method: wasLiked ? 'DELETE' : 'POST'});
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            const returned = normalizeVideo(Object.assign({}, target, payload.data || {}, {
                liked: !wasLiked,
                duration_seconds: target.duration_seconds,
                duration_label: target.duration_label,
            }));
            if (state.completed?.[target.id]) {
                const completedRecord = state.completed[target.id];
                delete state.completed[target.id];
                state.completed[returned.id] = Object.assign({}, completedRecord, {
                    filename: returned.filename,
                    video: returned,
                });
            }

            if (wasLiked) {
                forgetLikedVideo(target.id);
                showFlash('♡');
                showToast('已取消 LIKE');
            } else {
                rememberLikedVideo(returned);
                showFlash('♥');
                showToast('已加入 LIKE');
            }

            if (playerItem && playerItem.id === target.id) {
                const previousStreamUrl = playerItem.stream_url;
                const previousTime = Number(releasedPlayer?.time || elements.playerVideo.currentTime || 0);
                const shouldResume = Boolean(releasedPlayer?.shouldResume);
                playerItem = Object.assign({}, playerItem, returned, {liked: !wasLiked});
                state.currentId = playerItem.id;
                state.currentVideo = playerItem;
                elements.playerTitle.textContent = playerItem.filename;
                if (releasedPlayer?.wasOpen || (returned.stream_url && returned.stream_url !== previousStreamUrl)) {
                    elements.playerVideo.src = returned.stream_url;
                    applyPlaybackRate();
                    elements.playerVideo.onloadedmetadata = () => {
                        applyPlaybackRate();
                        if (previousTime > 0 && elements.playerVideo.duration && previousTime < elements.playerVideo.duration - 1) {
                            elements.playerVideo.currentTime = previousTime;
                        }
                        if (shouldResume) {
                            elements.playerVideo.play().catch(() => {});
                        }
                    };
                    elements.playerVideo.load();
                }
            }

            videos = videos.filter((item) => item.id !== target.id);
            if (
                (modeAtStart === MODE_WATCHED && isCompleted(returned.id))
                || (modeAtStart === MODE_LIKED && !wasLiked)
                || (modeAtStart === MODE_ALL && wasLiked)
            ) {
                videos.push(returned);
            }
            videoById.delete(target.id);
            if (
                (modeAtStart === MODE_WATCHED && isCompleted(returned.id))
                || (modeAtStart === MODE_LIKED && !wasLiked)
                || (modeAtStart === MODE_ALL && wasLiked)
            ) {
                videoById.set(returned.id, returned);
            }
            if (removedFromCurrentQueue && playerItem && playerItem.id === returned.id) {
                playerIndex = previousPlayerIndex - 1;
            }

            saveState();
            renderGrid();
            updateLikeVisuals(target.id);
            updateLikeVisuals(returned.id);
        } catch (error) {
            showToast(wasLiked ? '取消 LIKE 失敗' : '按讚失敗');
            if (releasedPlayer?.wasOpen && playerItem && playerItem.id === target.id && target.stream_url) {
                elements.playerVideo.src = target.stream_url;
                applyPlaybackRate();
                elements.playerVideo.onloadedmetadata = () => {
                    applyPlaybackRate();
                    if (releasedPlayer.time > 0 && elements.playerVideo.duration && releasedPlayer.time < elements.playerVideo.duration - 1) {
                        elements.playerVideo.currentTime = releasedPlayer.time;
                    }
                    if (releasedPlayer.shouldResume) {
                        elements.playerVideo.play().catch(() => {});
                    }
                };
                elements.playerVideo.load();
            }
        } finally {
            likeInFlight.delete(target.id);
        }
    }

    function toggleCurrentLike() {
        return toggleLike(playerItem);
    }

    function seekPlayer(seconds, label = null) {
        const video = elements.playerVideo;
        const duration = Number(video.duration || playerItem?.duration_seconds || 0);
        if (!duration) {
            return;
        }

        video.currentTime = clamp(video.currentTime + seconds, 0, duration);
        recordPlayerProgress();
        showFlash(label || (seconds > 0 ? `+${seconds}s` : `${seconds}s`));
    }

    function seekPlayerByRatio(stepCount) {
        const video = elements.playerVideo;
        const duration = Number(video.duration || playerItem?.duration_seconds || 0);
        if (!duration || !stepCount) {
            return;
        }

        const percent = stepCount * PLAYER_DRAG_SEEK_RATIO;
        const labelPercent = Math.round(percent * 100);
        seekPlayer(duration * percent, `${labelPercent > 0 ? '+' : ''}${labelPercent}%`);
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

    function bindCardGestures(card, video) {
        let startX = 0;
        let startY = 0;
        let startTime = 0;
        let moved = false;
        let singleTapTimer = null;
        let lastTap = {time: 0, x: 0, y: 0};

        const clearSingleTap = () => {
            clearTimeout(singleTapTimer);
            singleTapTimer = null;
        };

        card.addEventListener('pointerdown', (event) => {
            if (event.button !== undefined && event.button !== 0) {
                return;
            }

            startX = event.clientX;
            startY = event.clientY;
            startTime = Date.now();
            moved = false;
        });

        card.addEventListener('pointermove', (event) => {
            if (Math.abs(event.clientX - startX) > 16 || Math.abs(event.clientY - startY) > 16) {
                moved = true;
                clearLongPress();
            }
        });

        card.addEventListener('pointerup', (event) => {
            if (Date.now() - startTime > 950) {
                clearSingleTap();
                event.preventDefault();
                event.stopPropagation();
                return;
            }

            if (!moved) {
                const now = Date.now();
                const distance = Math.hypot(event.clientX - lastTap.x, event.clientY - lastTap.y);
                if (now - lastTap.time <= DOUBLE_TAP_MS && distance <= DOUBLE_TAP_DISTANCE) {
                    clearSingleTap();
                    lastTap = {time: 0, x: 0, y: 0};
                    event.preventDefault();
                    event.stopPropagation();
                    toggleLike(video);
                    return;
                }

                lastTap = {time: now, x: event.clientX, y: event.clientY};
                clearSingleTap();
                singleTapTimer = window.setTimeout(() => {
                    singleTapTimer = null;
                    openPlayer(video);
                }, DOUBLE_TAP_MS);
            }
        });

        card.addEventListener('pointercancel', () => {
            clearSingleTap();
        });
    }

    function bindPlayerGestures() {
        let startX = 0;
        let startY = 0;
        let startTime = 0;
        let moved = false;
        let holdSeekTimer = null;
        let holdSeekArmed = false;
        let dragSeekApplied = false;
        let dragSeekStepIndex = 0;
        let lastTap = {time: 0, x: 0, y: 0};

        const clearDragSeek = () => {
            clearTimeout(holdSeekTimer);
            holdSeekTimer = null;
            holdSeekArmed = false;
            dragSeekApplied = false;
            dragSeekStepIndex = 0;
        };

        stopPlayerDragSeek = clearDragSeek;

        elements.player.addEventListener('pointerdown', (event) => {
            if (event.target.closest('button')) {
                return;
            }

            startX = event.clientX;
            startY = event.clientY;
            startTime = Date.now();
            moved = false;
            holdSeekArmed = false;
            dragSeekApplied = false;
            dragSeekStepIndex = 0;
            clearTimeout(holdSeekTimer);
            holdSeekTimer = setTimeout(() => {
                if (!moved) {
                    holdSeekArmed = true;
                }
            }, PLAYER_HOLD_SEEK_MS);
        });

        elements.player.addEventListener('pointermove', (event) => {
            const dx = event.clientX - startX;
            const dy = event.clientY - startY;
            const absX = Math.abs(dx);
            const absY = Math.abs(dy);

            if (absX > 16 || absY > 16) {
                moved = true;
                if (!holdSeekArmed) {
                    clearTimeout(holdSeekTimer);
                    holdSeekTimer = null;
                }
            }

            if (holdSeekArmed && ((absX > 28 && absX > absY * 1.15) || dragSeekApplied)) {
                const nextStepIndex = Math.round(dx / PLAYER_DRAG_SEEK_STEP_PX);
                const stepDelta = nextStepIndex - dragSeekStepIndex;
                if (stepDelta !== 0) {
                    seekPlayerByRatio(stepDelta);
                    dragSeekStepIndex = nextStepIndex;
                    dragSeekApplied = true;
                }

                event.preventDefault();
                return;
            }

            if (absY > 28 && absY > absX && !dragSeekApplied) {
                clearDragSeek();
            }
        });

        elements.player.addEventListener('pointerup', (event) => {
            clearTimeout(holdSeekTimer);
            const hadDragSeek = dragSeekApplied;
            clearDragSeek();
            if (hadDragSeek) {
                event.preventDefault();
                return;
            }

            if (Date.now() - startTime > 900) {
                return;
            }

            const dx = event.clientX - startX;
            const dy = event.clientY - startY;

            if (Math.abs(dy) > 56 && Math.abs(dy) > Math.abs(dx)) {
                playAdjacent(dy < 0 ? 1 : -1);
                return;
            }

            if (Math.abs(dx) <= 18 && Math.abs(dy) <= 18) {
                const now = Date.now();
                const distance = Math.hypot(event.clientX - lastTap.x, event.clientY - lastTap.y);
                if (now - lastTap.time <= DOUBLE_TAP_MS && distance <= DOUBLE_TAP_DISTANCE) {
                    lastTap = {time: 0, x: 0, y: 0};
                    toggleCurrentLike();
                    return;
                }

                lastTap = {time: now, x: event.clientX, y: event.clientY};
            }
        });

        elements.player.addEventListener('pointercancel', () => {
            clearDragSeek();
        });

        elements.player.addEventListener('pointerleave', () => {
            clearDragSeek();
        });
    }

    function bindGridPinch() {
        let initialDistance = 0;
        let initialColumns = DEFAULT_GRID_COLUMNS;

        elements.grid.addEventListener('touchstart', (event) => {
            if (event.touches.length !== 2) {
                return;
            }

            initialDistance = touchDistance(event.touches[0], event.touches[1]);
            initialColumns = normalizeGridColumns(state.gridColumns);
        }, {passive: true});

        elements.grid.addEventListener('touchmove', (event) => {
            if (event.touches.length !== 2 || initialDistance <= 0) {
                return;
            }

            event.preventDefault();
            const distance = touchDistance(event.touches[0], event.touches[1]);
            const ratio = distance / initialDistance;
            if (!Number.isFinite(ratio) || ratio <= 0) {
                return;
            }

            const steps = Math.round(Math.log(ratio) / Math.log(1.18));
            if (steps !== 0) {
                setGridColumns(initialColumns - steps);
            }
        }, {passive: false});

        elements.grid.addEventListener('touchend', () => {
            initialDistance = 0;
        }, {passive: true});

        elements.grid.addEventListener('touchcancel', () => {
            initialDistance = 0;
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
        setGridColumns(state.gridColumns - 1);
    });

    elements.zoomOut.addEventListener('click', () => {
        setGridColumns(state.gridColumns + 1);
    });

    elements.zoomReset.addEventListener('click', () => {
        setGridColumns(DEFAULT_GRID_COLUMNS);
    });

    elements.watchedOnly.addEventListener('click', async () => {
        setMode(isWatchedMode() ? MODE_ALL : MODE_WATCHED);
        updateStatus();
        await loadMoreVideos(true);
    });

    elements.likedOnly.addEventListener('click', async () => {
        setMode(isLikedMode() ? MODE_ALL : MODE_LIKED);
        updateStatus();
        await loadMoreVideos(true);
    });

    elements.refresh.addEventListener('click', async () => {
        await fetchAppConfig();
        await loadMoreVideos(true);
        checkForUpdates();
    });

    elements.closePlayer.addEventListener('click', closePlayer);
    elements.playerLike.addEventListener('click', toggleCurrentLike);
    elements.playerNext.addEventListener('click', () => playAdjacent(1));
    elements.playerPrev.addEventListener('click', () => playAdjacent(-1));
    elements.playerRate.addEventListener('click', cyclePlaybackRate);

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            recordPlayerProgress();
            stopAllPreviews();
        } else {
            updateGridMetrics();
            schedulePreviewRefresh();
            checkForUpdates();
            maybeLoadMoreSoon();
        }
    });

    window.addEventListener('resize', () => {
        updateGridMetrics();
        schedulePreviewRefresh();
        maybeLoadMoreSoon();
    });

    window.addEventListener('scroll', () => {
        scheduleGridMetricsRefresh();
        maybeLoadMoreSoon();
    }, {passive: true});

    document.addEventListener('scroll', () => {
        scheduleGridMetricsRefresh();
        maybeLoadMoreSoon();
    }, {passive: true});

    elements.grid.addEventListener('scroll', () => {
        scheduleGridMetricsRefresh();
        maybeLoadMoreSoon();
    }, {passive: true});

    elements.shell.addEventListener('scroll', () => {
        scheduleGridMetricsRefresh();
        maybeLoadMoreSoon();
    }, {passive: true});

    document.body.addEventListener('touchmove', () => {
        scheduleGridMetricsRefresh();
        maybeLoadMoreSoon();
    }, {passive: true});

    document.body.addEventListener('wheel', () => {
        scheduleGridMetricsRefresh();
        maybeLoadMoreSoon();
    }, {passive: true});

    document.body.addEventListener('pointerup', () => {
        scheduleGridMetricsRefresh();
        maybeLoadMoreSoon();
    });

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', () => {
            updateGridMetrics();
            schedulePreviewRefresh();
            maybeLoadMoreSoon();
        });
        window.visualViewport.addEventListener('scroll', () => {
            updateGridMetrics();
            schedulePreviewRefresh();
            maybeLoadMoreSoon();
        });
    }

    window.addEventListener('orientationchange', () => {
        setTimeout(() => {
            scheduleGridMetricsRefresh();
            maybeLoadMoreSoon();
        }, 250);
    });

    window.addEventListener('beforeunload', recordPlayerProgress);

    new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            loadMoreVideos();
        }
    }, {rootMargin: '1800px 0px'}).observe(elements.sentinel);

    async function boot() {
        notifyFolderVideoTvPlayer(false);
        if (isFolderVideoAndroidApp() && cssPxVariable('--android-nav-inset') === 0) {
            setCssPxVariable('--android-nav-inset', androidNavigationFallbackInset(0));
        }
        ensureDefaultGridPreset();
        applyGridColumns();
        bindPlayerGestures();
        bindGridPinch();
        if (state.currentVideo && (
            isWatchedMode()
                ? isCompleted(state.currentVideo.id)
                : (isLikedMode() ? isLiked(state.currentVideo.id) : !isCompleted(state.currentVideo.id))
        )) {
            upsertVideo(state.currentVideo, true);
            renderGrid();
        }
        await fetchAppConfig();
        await loadMoreVideos(true);
        flushPendingLikeActions().catch(() => {});
        if (isFolderVideoTvApp()) {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.getRegistrations()
                    .then(registrations => Promise.all(registrations.map(registration => registration.unregister())))
                    .catch(() => {});
            }
            if ('caches' in window) {
                caches.keys()
                    .then(keys => Promise.all(keys.filter(key => key.startsWith('folder-video-app-')).map(key => caches.delete(key))))
                    .catch(() => {});
            }
        } else {
            registerServiceWorker();
        }
        if (isFolderVideoTvApp()) {
            window.setInterval(() => {
                tvPreviewOffset += 4;
                schedulePreviewRefresh();
            }, 6000);
        }
    }

    boot();
})();
</script>
</body>
</html>
