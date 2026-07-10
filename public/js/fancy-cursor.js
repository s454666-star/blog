(() => {
    const enabledClass = 'kittydog-cursor-enabled';
    const finePointerMedia = window.matchMedia('(pointer: fine)');

    function syncCursorCapability() {
        const enabled = finePointerMedia.matches && Boolean(document.body?.dataset.fancyCursor);

        document.documentElement.classList.toggle(enabledClass, enabled);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', syncCursorCapability, { once: true });
    } else {
        syncCursorCapability();
    }

    if (typeof finePointerMedia.addEventListener === 'function') {
        finePointerMedia.addEventListener('change', syncCursorCapability);
    } else if (typeof finePointerMedia.addListener === 'function') {
        finePointerMedia.addListener(syncCursorCapability);
    }
})();
