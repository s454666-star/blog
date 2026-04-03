(() => {
    const THEMES = {
        wand: { icon: '\u{1FA84}', particles: ['\u2726', '\u2737', '\u22c6'], behavior: 'spark', trailStep: 16, burstCount: 10 },
        balloon: { icon: '\u{1F388}', particles: ['\u25cb', '\u25e6', '\u2022'], behavior: 'float', trailStep: 28, burstCount: 6 },
        flower: { icon: '\u{1F338}', particles: ['\u273f', '\u2740', '\u2741'], behavior: 'petal', trailStep: 18, burstCount: 8 },
        butterfly: { icon: '\u{1F98B}', particles: ['\u{1F98B}', '\u2726'], behavior: 'flutter', trailStep: 22, burstCount: 6 },
        bunny: { icon: '\u{1F407}', particles: ['\u{1F43E}', '\u2661'], behavior: 'paw', trailStep: 20, burstCount: 6 },
        cat: { icon: '\u{1F408}', particles: ['\u{1F43E}', '\u2665'], behavior: 'paw', trailStep: 20, burstCount: 6 },
        snake: { icon: '\u{1F40D}', particles: ['\u25c6', '\u25c7', '\u2022'], behavior: 'trail', trailStep: 16, burstCount: 8 },
    };

    const finePointerMedia = window.matchMedia('(pointer: fine)');
    const reduceMotionMedia = window.matchMedia('(prefers-reduced-motion: reduce)');
    const interactiveSelector = [
        'a', 'button', '[role="button"]', 'summary', 'label', 'select', 'option', 'video', 'canvas',
        'input:not([type="hidden"])', 'textarea', '[data-run-preset]', '[data-stop-run]',
        '[data-copy-output]', '[data-copy-preview]', '[data-close-output]',
    ].join(', ');
    const textSelector = [
        'input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="file"]):not([type="submit"]):not([type="button"])',
        'textarea',
        '[contenteditable="true"]',
    ].join(', ');
    const cardSelector = ['.panel', '.card', '.hero', '.command-card', '.comparison-card', '.runtime-panel', 'article', 'section', 'main'].join(', ');

    let layer = null;
    let ring = null;
    let core = null;
    let icon = null;
    let particles = null;
    let currentTheme = THEMES.wand;
    let frame = null;
    let enabled = false;
    let targetX = window.innerWidth / 2;
    let targetY = window.innerHeight / 2;
    let ringX = targetX;
    let ringY = targetY;
    let coreX = targetX;
    let coreY = targetY;
    let lastTrailX = targetX;
    let lastTrailY = targetY;
    let pressed = false;
    let cleanupFns = [];

    const pick = (list) => list[Math.floor(Math.random() * list.length)];
    const setBodyState = (name, active) => document.body.classList.toggle(name, Boolean(active));
    const setCoordinates = (element, x, y) => {
        element.style.setProperty('--fc-x', `${x}px`);
        element.style.setProperty('--fc-y', `${y}px`);
    };
    const isReducedMotion = () => reduceMotionMedia.matches;

    function themeFromDataset() {
        const variant = document.body.dataset.fancyCursor || 'wand';

        return {
            variant,
            spec: THEMES[variant] || THEMES.wand,
        };
    }

    function spawnParticle(x, y, emphasized = false) {
        if (!particles) {
            return;
        }

        const particle = document.createElement('span');
        const behavior = currentTheme.behavior;
        const direction = Math.random() * Math.PI * 2;
        const distance = emphasized ? 42 + Math.random() * 62 : 16 + Math.random() * 34;
        let dx = Math.cos(direction) * distance;
        let dy = Math.sin(direction) * distance;

        if (behavior === 'spark') {
            dy -= emphasized ? 28 + Math.random() * 36 : 10 + Math.random() * 18;
        } else if (behavior === 'float') {
            dx *= 0.55;
            dy = -(20 + Math.random() * (emphasized ? 76 : 42));
        } else if (behavior === 'petal') {
            dx *= 0.85;
            dy = 18 + Math.random() * (emphasized ? 72 : 38);
        } else if (behavior === 'flutter') {
            dx *= 1.15;
            dy = -8 + Math.random() * (emphasized ? 44 : 28);
        } else if (behavior === 'paw') {
            dx *= 0.68;
            dy = 10 + Math.random() * 26;
        } else {
            dx *= 1.05;
            dy = -10 + Math.random() * 22;
        }

        particle.className = `fc-particle fc-particle--${behavior}`;
        particle.textContent = pick(currentTheme.particles);
        particle.style.left = `${x}px`;
        particle.style.top = `${y}px`;
        particle.style.fontSize = `${emphasized ? 17 + Math.random() * 8 : 12 + Math.random() * 6}px`;
        particle.style.setProperty('--fc-dx', `${dx.toFixed(1)}px`);
        particle.style.setProperty('--fc-dy', `${dy.toFixed(1)}px`);
        particle.style.setProperty('--fc-rotate', `${Math.round(-80 + Math.random() * 160)}deg`);
        particle.style.setProperty('--fc-duration', `${(emphasized ? 760 : 560) + Math.round(Math.random() * 280)}ms`);
        particle.style.setProperty('--fc-start-scale', emphasized ? '1.12' : '0.92');
        particle.style.setProperty('--fc-end-scale', `${(0.3 + Math.random() * 0.8).toFixed(2)}`);
        particle.style.setProperty('--fc-opacity', emphasized ? '1' : '0.88');

        particles.appendChild(particle);
        particle.addEventListener('animationend', () => particle.remove(), { once: true });
    }

    function spawnBurst(x, y, count) {
        const total = isReducedMotion() ? Math.min(4, count) : count;

        for (let index = 0; index < total; index += 1) {
            spawnParticle(x, y, true);
        }
    }

    function updateInteraction(target) {
        const source = target instanceof Element ? target : document.body;
        const textTarget = source.closest(textSelector);
        const interactiveTarget = source.closest(interactiveSelector);
        const cardTarget = source.closest(cardSelector);

        setBodyState('fancy-cursor-text', Boolean(textTarget));
        setBodyState('fancy-cursor-hover', Boolean(interactiveTarget));
        setBodyState('fancy-cursor-card', !interactiveTarget && !textTarget && Boolean(cardTarget));
        setBodyState('fancy-cursor-hidden', false);
        setBodyState('fancy-cursor-press', pressed);
    }

    function animate() {
        ringX += (targetX - ringX) * 0.18;
        ringY += (targetY - ringY) * 0.18;
        coreX += (targetX - coreX) * 0.36;
        coreY += (targetY - coreY) * 0.36;

        setCoordinates(ring, ringX, ringY);
        setCoordinates(core, coreX, coreY);
        frame = window.requestAnimationFrame(animate);
    }

    function handlePointerMove(event) {
        if (!enabled || event.pointerType === 'touch') {
            return;
        }

        targetX = event.clientX;
        targetY = event.clientY;
        updateInteraction(event.target);

        const distance = Math.hypot(targetX - lastTrailX, targetY - lastTrailY);
        const trailStep = isReducedMotion() ? currentTheme.trailStep * 1.8 : currentTheme.trailStep;

        if (distance >= trailStep) {
            spawnParticle(targetX, targetY, false);
            lastTrailX = targetX;
            lastTrailY = targetY;
        }
    }

    function handlePointerDown(event) {
        if (!enabled || event.pointerType === 'touch') {
            return;
        }

        pressed = true;
        setBodyState('fancy-cursor-press', true);
        spawnBurst(event.clientX, event.clientY, currentTheme.burstCount);
    }

    function handlePointerUp(event) {
        if (!enabled || event.pointerType === 'touch') {
            return;
        }

        pressed = false;
        setBodyState('fancy-cursor-press', false);
        updateInteraction(event.target);
    }

    function handlePointerLeave() {
        if (enabled) {
            setBodyState('fancy-cursor-hidden', true);
        }
    }

    function handlePointerEnter(event) {
        if (enabled) {
            updateInteraction(event.target);
        }
    }

    function addCleanup(target, eventName, handler, options) {
        target.addEventListener(eventName, handler, options);
        cleanupFns.push(() => target.removeEventListener(eventName, handler, options));
    }

    function buildCursor() {
        const { variant, spec } = themeFromDataset();
        layer = document.createElement('div');
        layer.className = `fancy-cursor-layer is-${variant}`;
        layer.setAttribute('aria-hidden', 'true');
        layer.innerHTML = '<div class="fancy-cursor-ring"></div><div class="fancy-cursor-core"><span class="fancy-cursor-icon"></span></div><div class="fancy-cursor-particles"></div>';

        ring = layer.querySelector('.fancy-cursor-ring');
        core = layer.querySelector('.fancy-cursor-core');
        icon = layer.querySelector('.fancy-cursor-icon');
        particles = layer.querySelector('.fancy-cursor-particles');
        currentTheme = spec;
        icon.textContent = spec.icon;
        document.body.appendChild(layer);
    }

    function destroyCursor() {
        cleanupFns.forEach((cleanup) => cleanup());
        cleanupFns = [];

        if (frame !== null) {
            window.cancelAnimationFrame(frame);
            frame = null;
        }

        if (layer) {
            layer.remove();
        }

        layer = null;
        ring = null;
        core = null;
        icon = null;
        particles = null;
        enabled = false;
        pressed = false;
        document.body.classList.remove('fancy-cursor-ready', 'fancy-cursor-hover', 'fancy-cursor-text', 'fancy-cursor-card', 'fancy-cursor-hidden', 'fancy-cursor-press');
        document.body.classList.add('fancy-cursor-disabled');
    }

    function enableCursor() {
        if (enabled || !finePointerMedia.matches || !document.body.dataset.fancyCursor) {
            return;
        }

        buildCursor();
        enabled = true;
        document.body.classList.remove('fancy-cursor-disabled');
        document.body.classList.add('fancy-cursor-ready');
        setCoordinates(ring, ringX, ringY);
        setCoordinates(core, coreX, coreY);
        frame = window.requestAnimationFrame(animate);

        addCleanup(document, 'pointermove', handlePointerMove);
        addCleanup(document, 'pointerdown', handlePointerDown);
        addCleanup(document, 'pointerup', handlePointerUp);
        addCleanup(document, 'pointerleave', handlePointerLeave);
        addCleanup(document, 'pointerenter', handlePointerEnter);
    }

    function syncCapability() {
        if (finePointerMedia.matches) {
            enableCursor();
            return;
        }

        destroyCursor();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncCapability, { once: true });
    } else {
        syncCapability();
    }

    if (typeof finePointerMedia.addEventListener === 'function') {
        finePointerMedia.addEventListener('change', syncCapability);
        reduceMotionMedia.addEventListener('change', syncCapability);
    } else if (typeof finePointerMedia.addListener === 'function') {
        finePointerMedia.addListener(syncCapability);
        reduceMotionMedia.addListener(syncCapability);
    }
})();
