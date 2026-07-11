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
            transform: none;
            backface-visibility: hidden;
            transform-origin: center;
            will-change: opacity, transform, filter;
            pointer-events: none;
        }

        .photo-cell img.is-visible {
            opacity: 1;
            transform: scale(1);
        }

        .photo-cell img.photo-enter-fade {
            animation: photo-fade-in 850ms ease both;
        }

        .photo-cell img.photo-exit-fade {
            animation: photo-fade-out 850ms ease both;
        }

        .photo-cell img.photo-enter-flip-x {
            animation: photo-flip-x-in 900ms cubic-bezier(.2, .72, .2, 1) both;
        }

        .photo-cell img.photo-exit-flip-x {
            animation: photo-flip-x-out 900ms cubic-bezier(.4, 0, .65, .3) both;
        }

        .photo-cell img.photo-enter-flip-y {
            animation: photo-flip-y-in 900ms cubic-bezier(.2, .72, .2, 1) both;
        }

        .photo-cell img.photo-exit-flip-y {
            animation: photo-flip-y-out 900ms cubic-bezier(.4, 0, .65, .3) both;
        }

        .photo-cell img.photo-enter-zoom {
            animation: photo-zoom-in 850ms cubic-bezier(.16, .84, .3, 1) both;
        }

        .photo-cell img.photo-exit-zoom {
            animation: photo-zoom-out 850ms ease both;
        }

        .photo-cell img.photo-enter-blur {
            animation: photo-blur-in 900ms ease both;
        }

        .photo-cell img.photo-exit-blur {
            animation: photo-blur-out 900ms ease both;
        }

        .photo-cell img.photo-enter-tilt {
            animation: photo-tilt-in 900ms cubic-bezier(.16, .84, .3, 1) both;
        }

        .photo-cell img.photo-exit-tilt {
            animation: photo-tilt-out 900ms ease both;
        }

        @keyframes photo-fade-in {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes photo-fade-out {
            from { opacity: 1; }
            to { opacity: 0; }
        }

        @keyframes photo-flip-x-in {
            from { opacity: 0; transform: perspective(700px) rotateY(-82deg) scale(.9); }
            to { opacity: 1; transform: perspective(700px) rotateY(0) scale(1); }
        }

        @keyframes photo-flip-x-out {
            from { opacity: 1; transform: perspective(700px) rotateY(0) scale(1); }
            to { opacity: 0; transform: perspective(700px) rotateY(82deg) scale(.9); }
        }

        @keyframes photo-flip-y-in {
            from { opacity: 0; transform: perspective(700px) rotateX(82deg) scale(.9); }
            to { opacity: 1; transform: perspective(700px) rotateX(0) scale(1); }
        }

        @keyframes photo-flip-y-out {
            from { opacity: 1; transform: perspective(700px) rotateX(0) scale(1); }
            to { opacity: 0; transform: perspective(700px) rotateX(-82deg) scale(.9); }
        }

        @keyframes photo-zoom-in {
            from { opacity: 0; transform: scale(.72); }
            to { opacity: 1; transform: scale(1); }
        }

        @keyframes photo-zoom-out {
            from { opacity: 1; transform: scale(1); }
            to { opacity: 0; transform: scale(1.22); }
        }

        @keyframes photo-blur-in {
            from { opacity: 0; filter: blur(18px) brightness(1.35); transform: scale(.92); }
            to { opacity: 1; filter: blur(0) brightness(1); transform: scale(1); }
        }

        @keyframes photo-blur-out {
            from { opacity: 1; filter: blur(0) brightness(1); transform: scale(1); }
            to { opacity: 0; filter: blur(18px) brightness(.65); transform: scale(1.08); }
        }

        @keyframes photo-tilt-in {
            from { opacity: 0; transform: rotate(-9deg) scale(.78); }
            to { opacity: 1; transform: rotate(0) scale(1); }
        }

        @keyframes photo-tilt-out {
            from { opacity: 1; transform: rotate(0) scale(1); }
            to { opacity: 0; transform: rotate(9deg) scale(.78); }
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
            .overlay {
                transition-duration: 1ms;
            }

            .photo-cell img {
                animation-duration: 1ms !important;
            }
        }
    </style>
</head>
<body>
<main id="photo-wall" aria-label="隨機圖片牆"></main>
<div id="gesture-hint" class="overlay">上滑 +1 列・下滑 -1 列・左滑 +1 欄・右滑 -1 欄</div>
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
    const transitionNames = ['fade', 'flip-x', 'flip-y', 'zoom', 'blur', 'tilt'];
    const transitionClasses = transitionNames.flatMap(name => [
        `photo-enter-${name}`,
        `photo-exit-${name}`,
    ]);
    const transitionDurationMs = 950;
    const swipeThresholdPx = 44;
    const swipeAxisToleranceRatio = 0.35;
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
        const min = Number(config.display_min_ms) || 7000;
        const max = Math.max(min, Number(config.display_max_ms) || 12000);
        return Math.round(min + Math.random() * (max - min));
    }

    function clearTransitionClasses(layer) {
        layer.classList.remove(...transitionClasses);
    }

    function randomTransitionName() {
        return transitionNames[Math.floor(Math.random() * transitionNames.length)];
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

    function directPhotoUrl(photo) {
        try {
            if (window.DirectNas?.ready?.() && photo?.relative_path) {
                return window.DirectNas.directUrl('photo', photo.relative_path) || '';
            }
        } catch (error) {
        }
        return '';
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

            clearTimeout(slot.transitionTimer);
            clearTransitionClasses(nextLayer);
            nextLayer.classList.remove('is-visible');
            nextLayer.onload = () => {
                if (token !== slot.token || !slot.element.isConnected) {
                    return;
                }

                const transitionName = randomTransitionName();
                slot.currentId = photo.id;
                clearTransitionClasses(nextLayer);
                clearTransitionClasses(oldLayer);
                void nextLayer.offsetWidth;
                nextLayer.classList.add('is-visible', `photo-enter-${transitionName}`);
                if (oldLayer.classList.contains('is-visible')) {
                    oldLayer.classList.add(`photo-exit-${transitionName}`);
                }
                slot.activeLayer = nextLayerIndex;
                nextLayer.onload = null;
                nextLayer.onerror = null;
                slot.transitionTimer = setTimeout(() => {
                    if (token !== slot.token || !slot.element.isConnected) {
                        return;
                    }

                    oldLayer.classList.remove('is-visible');
                    clearTransitionClasses(oldLayer);
                    clearTransitionClasses(nextLayer);
                    scheduleReplacement(slot);
                }, transitionDurationMs);
            };
            const directUrl = directPhotoUrl(photo);
            let fellBackToCaddy = false;
            nextLayer.onerror = () => {
                if (directUrl && !fellBackToCaddy) {
                    fellBackToCaddy = true;
                    nextLayer.src = photo.url;
                    return;
                }
                nextLayer.onload = null;
                nextLayer.onerror = null;
                scheduleReplacement(slot, 700);
            };
            nextLayer.src = directUrl || photo.url;
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
            transitionTimer: null,
            token: 0,
        };
        state.slots.push(slot);
        replacePhoto(slot);
    }

    function removeSlot(slot) {
        clearTimeout(slot.timer);
        clearTimeout(slot.transitionTimer);
        slot.token += 1;
        slot.layers.forEach(layer => {
            layer.onload = null;
            layer.onerror = null;
            clearTransitionClasses(layer);
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

    function applySwipe(startX, startY, endX, endY) {
        const deltaX = endX - startX;
        const deltaY = endY - startY;
        const absoluteX = Math.abs(deltaX);
        const absoluteY = Math.abs(deltaY);

        if (absoluteX < swipeThresholdPx && absoluteY < swipeThresholdPx) {
            return;
        }

        if (absoluteX >= swipeThresholdPx && absoluteY <= Math.max(28, absoluteX * swipeAxisToleranceRatio)) {
            setGrid(state.columns + (deltaX < 0 ? 1 : -1), state.rows);
            return;
        }

        if (absoluteY >= swipeThresholdPx && absoluteX <= Math.max(28, absoluteY * swipeAxisToleranceRatio)) {
            setGrid(state.columns, state.rows + (deltaY < 0 ? 1 : -1));
        }
    }

    function beginGesture(x, y) {
        state.gesture = {
            startX: x,
            startY: y,
            endX: x,
            endY: y,
        };
        wall.classList.add('is-resizing');
        gestureHint.classList.add('is-hidden');
    }

    function finishGesture() {
        if (state.gesture) {
            applySwipe(
                state.gesture.startX,
                state.gesture.startY,
                state.gesture.endX,
                state.gesture.endY
            );
        }
        state.gesture = null;
        wall.classList.remove('is-resizing');
    }

    wall.addEventListener('touchstart', event => {
        event.preventDefault();
        if (event.touches.length !== 1) {
            state.gesture = null;
            return;
        }

        const touch = event.touches[0];
        beginGesture(touch.clientX, touch.clientY);
    }, {passive: false});

    wall.addEventListener('touchmove', event => {
        event.preventDefault();
        if (!state.gesture || event.touches.length !== 1) {
            state.gesture = null;
            return;
        }

        const touch = event.touches[0];
        state.gesture.endX = touch.clientX;
        state.gesture.endY = touch.clientY;
    }, {passive: false});

    wall.addEventListener('touchend', event => {
        event.preventDefault();
        if (event.touches.length === 0) {
            const touch = event.changedTouches[0];
            if (state.gesture && touch) {
                state.gesture.endX = touch.clientX;
                state.gesture.endY = touch.clientY;
            }
            finishGesture();
        }
    }, {passive: false});

    wall.addEventListener('touchcancel', () => {
        state.gesture = null;
        wall.classList.remove('is-resizing');
    });

    wall.addEventListener('mousedown', event => {
        if (event.button !== 0) {
            return;
        }
        event.preventDefault();
        state.mouseActive = true;
        beginGesture(event.clientX, event.clientY);
    });

    window.addEventListener('mousemove', event => {
        if (!state.mouseActive || !state.gesture) {
            return;
        }
        event.preventDefault();
        state.gesture.endX = event.clientX;
        state.gesture.endY = event.clientY;
    });

    window.addEventListener('mouseup', () => {
        if (!state.mouseActive) {
            return;
        }
        state.mouseActive = false;
        finishGesture();
    });

    window.folderPhotoTvHandleKey = key => {
        if (key === 'up') {
            setGrid(state.columns, state.rows + 1);
            return true;
        }
        if (key === 'down') {
            setGrid(state.columns, state.rows - 1);
            return true;
        }
        if (key === 'left') {
            setGrid(state.columns + 1, state.rows);
            return true;
        }
        if (key === 'right') {
            setGrid(state.columns - 1, state.rows);
            return true;
        }
        return false;
    };

    window.addEventListener('resize', () => setGrid(state.columns, state.rows, false));
    setTimeout(() => gestureHint.classList.add('is-hidden'), 6000);
    setGrid(state.columns, state.rows, false);
})();
</script>
</body>
</html>
