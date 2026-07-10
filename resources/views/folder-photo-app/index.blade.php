<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
    <meta name="theme-color" content="#050812">
    <title>Folder Photo</title>
    <link rel="icon" href="/folder-photo-assets/icon-192.png">
    <style>
        :root {
            color-scheme: dark;
            --columns: 3;
            --rows: 4;
            --gap: 2px;
            --background: #050812;
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
            overscroll-behavior: none;
            background: var(--background);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body {
            position: fixed;
            inset: 0;
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
        }

        #photo-wall {
            position: absolute;
            inset: 0;
            display: grid;
            grid-template-columns: repeat(var(--columns), minmax(0, 1fr));
            grid-template-rows: repeat(var(--rows), minmax(0, 1fr));
            gap: var(--gap);
            padding: var(--gap);
            overflow: hidden;
            background: var(--background);
            cursor: grab;
        }

        #photo-wall.is-resizing {
            cursor: grabbing;
        }

        .photo-cell {
            position: relative;
            min-width: 0;
            min-height: 0;
            overflow: hidden;
            background:
                radial-gradient(circle at 50% 42%, rgba(83, 119, 194, .18), transparent 58%),
                #090d18;
        }

        .photo-cell::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .025);
        }

        .photo-cell img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
            object-position: center;
            opacity: 0;
            transform: scale(1.012);
            transition: opacity 480ms ease, transform 700ms ease;
            pointer-events: none;
        }

        .photo-cell img.is-visible {
            opacity: 1;
            transform: scale(1);
        }

        .overlay {
            position: fixed;
            left: 50%;
            z-index: 10;
            max-width: calc(100vw - 32px);
            padding: 9px 14px;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 999px;
            color: rgba(255, 255, 255, .92);
            background: rgba(5, 8, 18, .78);
            box-shadow: 0 10px 34px rgba(0, 0, 0, .34);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            font-size: 13px;
            line-height: 1.35;
            text-align: center;
            transform: translateX(-50%);
            transition: opacity 240ms ease, transform 240ms ease;
            pointer-events: none;
        }

        #gesture-hint {
            bottom: 22px;
        }

        #grid-size {
            top: 18px;
            min-width: 76px;
            font-size: 16px;
            font-weight: 750;
            letter-spacing: .04em;
            opacity: 0;
            transform: translate(-50%, -8px);
        }

        #grid-size.is-visible {
            opacity: 1;
            transform: translate(-50%, 0);
        }

        #connection-status {
            top: 50%;
            border-radius: 14px;
            opacity: 0;
            transform: translate(-50%, -50%);
        }

        #connection-status.is-visible {
            opacity: 1;
        }

        .is-hidden {
            opacity: 0 !important;
            transform: translate(-50%, 8px) !important;
        }

        @media (prefers-reduced-motion: reduce) {
            .photo-cell img,
            .overlay {
                transition-duration: 1ms;
            }
        }
    </style>
</head>
<body>
<main id="photo-wall" aria-label="隨機圖片牆"></main>
<div id="gesture-hint" class="overlay">左右拉改欄數・上下拉改列數・斜向同時調整</div>
<div id="grid-size" class="overlay" aria-live="polite">3 × 4</div>
<div id="connection-status" class="overlay" aria-live="polite"></div>

<script>
(() => {
    'use strict';

    const config = @json($appConfig);
    const wall = document.getElementById('photo-wall');
    const gestureHint = document.getElementById('gesture-hint');
    const gridSize = document.getElementById('grid-size');
    const connectionStatus = document.getElementById('connection-status');
    const state = {
        columns: clamp(Number(config.initial_columns) || 3, 1, Number(config.max_columns) || 6),
        rows: clamp(Number(config.initial_rows) || 4, 1, Number(config.max_rows) || 8),
        slots: [],
        pool: [],
        poolIds: new Set(),
        fetchPromise: null,
        gesture: null,
        mouseActive: false,
        gridBadgeTimer: null,
        generation: 0,
    };

    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    function randomDelay() {
        const min = Number(config.display_min_ms) || 3000;
        const max = Math.max(min, Number(config.display_max_ms) || 5000);
        return Math.round(min + Math.random() * (max - min));
    }

    function showConnectionStatus(message) {
        connectionStatus.textContent = message;
        connectionStatus.classList.toggle('is-visible', Boolean(message));
    }

    function showGridSize() {
        gridSize.textContent = `${state.columns} × ${state.rows}`;
        gridSize.classList.add('is-visible');
        clearTimeout(state.gridBadgeTimer);
        state.gridBadgeTimer = setTimeout(() => gridSize.classList.remove('is-visible'), 900);
    }

    function visibleIds(exceptSlot = null) {
        const ids = new Set();
        for (const slot of state.slots) {
            if (slot !== exceptSlot && slot.currentId) {
                ids.add(slot.currentId);
            }
        }
        return ids;
    }

    async function refillPool(minimum = 1) {
        if (state.pool.length >= minimum) {
            return;
        }

        if (state.fetchPromise) {
            await state.fetchPromise;
            return;
        }

        const wanted = clamp(
            Math.max(120, minimum * 6, state.columns * state.rows * 8),
            12,
            Number(config.random_pool_limit) || 500
        );

        state.fetchPromise = fetch(`/api/folder-photos/random?count=${wanted}&t=${Date.now()}`, {
            cache: 'no-store',
            headers: {'Accept': 'application/json'},
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                return response.json();
            })
            .then(payload => {
                const photos = Array.isArray(payload.data) ? payload.data : [];
                for (const photo of photos) {
                    if (!photo || !photo.id || !photo.url || state.poolIds.has(photo.id)) {
                        continue;
                    }
                    state.pool.push(photo);
                    state.poolIds.add(photo.id);
                }
                showConnectionStatus('');
            })
            .catch(() => {
                showConnectionStatus('圖片來源暫時無法連線，正在重試…');
                throw new Error('Folder Photo pool unavailable');
            })
            .finally(() => {
                state.fetchPromise = null;
            });

        await state.fetchPromise;
    }

    async function takePhoto(slot) {
        await refillPool(Math.max(1, state.columns * state.rows));
        const inUse = visibleIds(slot);

        while (state.pool.length > 0) {
            const photo = state.pool.shift();
            state.poolIds.delete(photo.id);
            if (!inUse.has(photo.id) && photo.id !== slot.currentId) {
                if (state.pool.length < Math.max(24, state.columns * state.rows * 2)) {
                    refillPool(Math.max(120, state.columns * state.rows * 6)).catch(() => {});
                }
                return photo;
            }
        }

        return null;
    }

    function scheduleReplacement(slot, delay = randomDelay()) {
        clearTimeout(slot.timer);
        slot.timer = setTimeout(() => replacePhoto(slot), delay);
    }

    async function replacePhoto(slot) {
        const token = ++slot.token;

        try {
            const photo = await takePhoto(slot);
            if (!photo || token !== slot.token || !slot.element.isConnected) {
                scheduleReplacement(slot, 900);
                return;
            }

            const nextLayerIndex = slot.activeLayer === 0 ? 1 : 0;
            const nextLayer = slot.layers[nextLayerIndex];
            const oldLayer = slot.layers[slot.activeLayer];

            nextLayer.classList.remove('is-visible');
            nextLayer.onload = () => {
                if (token !== slot.token || !slot.element.isConnected) {
                    return;
                }

                slot.currentId = photo.id;
                nextLayer.classList.add('is-visible');
                oldLayer.classList.remove('is-visible');
                slot.activeLayer = nextLayerIndex;
                nextLayer.onload = null;
                nextLayer.onerror = null;
                scheduleReplacement(slot);
            };
            nextLayer.onerror = () => {
                nextLayer.onload = null;
                nextLayer.onerror = null;
                scheduleReplacement(slot, 700);
            };
            nextLayer.src = photo.url;
        } catch (error) {
            scheduleReplacement(slot, 1600);
        }
    }

    function createSlot() {
        const element = document.createElement('div');
        element.className = 'photo-cell';

        const firstLayer = document.createElement('img');
        const secondLayer = document.createElement('img');
        firstLayer.alt = '';
        secondLayer.alt = '';
        firstLayer.decoding = 'async';
        secondLayer.decoding = 'async';
        element.append(firstLayer, secondLayer);
        wall.appendChild(element);

        const slot = {
            element,
            layers: [firstLayer, secondLayer],
            activeLayer: 0,
            currentId: null,
            timer: null,
            token: 0,
        };
        state.slots.push(slot);
        replacePhoto(slot);
    }

    function removeSlot(slot) {
        clearTimeout(slot.timer);
        slot.token += 1;
        slot.layers.forEach(layer => {
            layer.onload = null;
            layer.onerror = null;
            layer.src = '';
        });
        slot.element.remove();
    }

    function setGrid(columns, rows, announce = true) {
        const nextColumns = clamp(Math.round(columns), 1, Number(config.max_columns) || 6);
        const nextRows = clamp(Math.round(rows), 1, Number(config.max_rows) || 8);
        if (nextColumns === state.columns && nextRows === state.rows && state.slots.length > 0) {
            return;
        }

        state.columns = nextColumns;
        state.rows = nextRows;
        document.documentElement.style.setProperty('--columns', String(nextColumns));
        document.documentElement.style.setProperty('--rows', String(nextRows));

        const targetCount = nextColumns * nextRows;
        while (state.slots.length > targetCount) {
            const slot = state.slots.pop();
            removeSlot(slot);
        }
        while (state.slots.length < targetCount) {
            createSlot();
        }

        if (announce) {
            showGridSize();
        }
    }

    function touchDistance(touches) {
        return {
            x: Math.abs(touches[0].clientX - touches[1].clientX),
            y: Math.abs(touches[0].clientY - touches[1].clientY),
        };
    }

    wall.addEventListener('touchstart', event => {
        event.preventDefault();
        gestureHint.classList.add('is-hidden');
        wall.classList.add('is-resizing');

        if (event.touches.length >= 2) {
            const distance = touchDistance(event.touches);
            state.gesture = {
                mode: 'pinch',
                columns: state.columns,
                rows: state.rows,
                distanceX: Math.max(24, distance.x),
                distanceY: Math.max(24, distance.y),
            };
            return;
        }

        const touch = event.touches[0];
        state.gesture = {
            mode: 'drag',
            columns: state.columns,
            rows: state.rows,
            x: touch.clientX,
            y: touch.clientY,
        };
    }, {passive: false});

    wall.addEventListener('touchmove', event => {
        event.preventDefault();
        if (!state.gesture) {
            return;
        }

        if (event.touches.length >= 2) {
            if (state.gesture.mode !== 'pinch') {
                const distance = touchDistance(event.touches);
                state.gesture = {
                    mode: 'pinch',
                    columns: state.columns,
                    rows: state.rows,
                    distanceX: Math.max(24, distance.x),
                    distanceY: Math.max(24, distance.y),
                };
                return;
            }

            const distance = touchDistance(event.touches);
            const scaleX = Math.max(.25, distance.x / state.gesture.distanceX);
            const scaleY = Math.max(.25, distance.y / state.gesture.distanceY);
            setGrid(state.gesture.columns / scaleX, state.gesture.rows / scaleY);
            return;
        }

        if (state.gesture.mode !== 'drag' || event.touches.length !== 1) {
            return;
        }

        const touch = event.touches[0];
        const horizontalStep = Math.max(54, window.innerWidth / 5);
        const verticalStep = Math.max(54, window.innerHeight / 7);
        const columns = state.gesture.columns + Math.round((touch.clientX - state.gesture.x) / horizontalStep);
        const rows = state.gesture.rows + Math.round((touch.clientY - state.gesture.y) / verticalStep);
        setGrid(columns, rows);
    }, {passive: false});

    wall.addEventListener('touchend', event => {
        event.preventDefault();
        if (event.touches.length === 1) {
            const touch = event.touches[0];
            state.gesture = {
                mode: 'drag',
                columns: state.columns,
                rows: state.rows,
                x: touch.clientX,
                y: touch.clientY,
            };
            return;
        }

        state.gesture = null;
        wall.classList.remove('is-resizing');
    }, {passive: false});

    wall.addEventListener('wheel', event => {
        event.preventDefault();
        const direction = event.deltaY > 0 ? 1 : -1;
        const columns = event.altKey ? state.columns : state.columns + direction;
        const rows = event.shiftKey ? state.rows : state.rows + direction;
        setGrid(columns, rows);
        gestureHint.classList.add('is-hidden');
    }, {passive: false});

    wall.addEventListener('mousedown', event => {
        if (event.button !== 0) {
            return;
        }
        event.preventDefault();
        state.mouseActive = true;
        state.gesture = {
            mode: 'drag',
            columns: state.columns,
            rows: state.rows,
            x: event.clientX,
            y: event.clientY,
        };
        wall.classList.add('is-resizing');
        gestureHint.classList.add('is-hidden');
    });

    window.addEventListener('mousemove', event => {
        if (!state.mouseActive || !state.gesture) {
            return;
        }
        event.preventDefault();
        const horizontalStep = Math.max(54, window.innerWidth / 5);
        const verticalStep = Math.max(54, window.innerHeight / 7);
        setGrid(
            state.gesture.columns + Math.round((event.clientX - state.gesture.x) / horizontalStep),
            state.gesture.rows + Math.round((event.clientY - state.gesture.y) / verticalStep)
        );
    });

    window.addEventListener('mouseup', () => {
        state.mouseActive = false;
        state.gesture = null;
        wall.classList.remove('is-resizing');
    });

    window.addEventListener('resize', () => setGrid(state.columns, state.rows, false));
    setTimeout(() => gestureHint.classList.add('is-hidden'), 6000);
    setGrid(state.columns, state.rows, false);
})();
</script>
</body>
</html>
