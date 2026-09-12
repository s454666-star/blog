/* Library presentation: solid surfaces avoid per-card blur and perpetual repainting. */
:root {
    --theme-text: #302b42;
    --theme-text-soft: #655d78;
    --theme-accent-strong: #7040b4;
    --theme-border: #e4deed;
    --theme-shadow: 0 3px 14px rgba(48, 32, 72, .04);
}
body { background: #f6f5f9; background-attachment: scroll; }
body::before { display: none; }
.library-header { display: flex; justify-content: space-between; align-items: center; gap: 24px; padding: 12px 0 22px; }
.library-eyebrow { color: #78658e; font-size: .7rem; font-weight: 700; letter-spacing: .16em; }
.library-header h1 { margin: 6px 0 8px; font-size: clamp(1.5rem, 2.4vw, 2rem); font-weight: 750; letter-spacing: -.03em; }
.library-header p { margin: 0; color: var(--theme-text-soft); font-size: .9rem; }
.library-actions { display: flex; gap: 8px; flex-shrink: 0; }
.library-button { display: inline-flex; align-items: center; justify-content: center; min-height: 44px; padding: 10px 16px; border: 1px solid #ddd5e9; border-radius: 12px; background: #fff; color: #514061; font: inherit; font-size: .88rem; font-weight: 650; cursor: pointer; }
.library-button:hover { background: #eee8f6; color: #514061; text-decoration: none; }
.library-button--primary { background: #7040b4; border-color: #7040b4; color: white; }
.library-button--primary:hover { background: #60359e; color: white; }
.library-summary { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; margin-bottom: 24px; padding: 14px 0; border-top: 1px solid var(--theme-border); border-bottom: 1px solid var(--theme-border); color: var(--theme-text-soft); font-size: .82rem; overflow-wrap: anywhere; }
.library-summary strong { color: var(--theme-text); }
.video-row { background: #fff; border-radius: 18px; padding: 18px; margin-bottom: 24px; backdrop-filter: none; box-shadow: var(--theme-shadow); transition: border-color .15s ease, background-color .15s ease; }
.video-row.focused { background: #fcfaff; border-color: #9567ca; box-shadow: inset 3px 0 #9567ca; }
.video-headline { gap: 8px; flex-direction: column; align-items: flex-start; }
.video-meta-chips { justify-content: flex-start; }
.video-title-stack { gap: 4px; }
.video-title-chip, .video-title-chip:hover { display: block; min-height: 0; padding: 0; border: 0; border-radius: 0; background: transparent; box-shadow: none; animation: none; opacity: 1; transform: none; filter: none; }
.video-title-chip--main { color: var(--theme-text); font-size: 1rem; line-height: 1.55; }
.video-title-chip--path { color: var(--theme-text-soft); font-size: .75rem; font-weight: 400; }
.video-title-chip::before, .video-title-chip::after, .face-paste-target::before { display: none; }
.video-chip { min-width: 0; padding: 4px 8px; background: #f5f2f9; border: 0; font-size: .72rem; letter-spacing: 0; }
.video-wrapper video { border-radius: 12px; box-shadow: none; background: #17141f; }
.images-container h5 { font-size: .8rem; font-weight: 700; color: var(--theme-text-soft); margin-bottom: 10px; }
.images-container h5::after { animation: none; background: #e5ddec; height: 1px; }
.face-paste-target { background: #faf8fd; box-shadow: none; border-width: 1px; }
.master-faces { background: #fff; box-shadow: 2px 0 16px rgba(48, 32, 72, .04); }
.master-face-item::before, .master-face-item::after,
.face-screenshot-container:has(> .face-screenshot.master)::before,
.face-screenshot-container:has(> .face-screenshot.master)::after { display: none; }
.master-face-item.focused { outline: 3px solid #9567ca; outline-offset: -3px; }
.face-screenshot.master { border: 3px solid #7040b4; box-shadow: none; }
#toggle-master-faces { left: 12px; transform: none; width: 44px; height: 44px; line-height: 44px; top: 12px !important; box-shadow: 0 2px 8px rgba(48, 32, 72, .12); }
#toggle-master-faces.inside { left: calc(30% - 54px); transform: none; opacity: 1; }
.controls { backdrop-filter: none; background: #fcfaff; }
.controls .control-group { background: #fff; box-shadow: none; }
.controls .control-group:hover { transform: none; box-shadow: none; }
.controls .control-action-btn { color: #983143; background: #fff1f2; border-color: #edc5cc; }
.library-empty { text-align: center; padding: 64px 20px; border: 1px dashed #d8cde6; border-radius: 18px; background: #fff; }
.library-empty-mark { display: block; font-size: 3rem; color: #9470bb; }
.library-empty h2 { font-size: 1.2rem; }
.library-empty p { color: var(--theme-text-soft); }
.library-load-retry { padding: 16px; background: #fff7ef; border: 1px solid #ead3b7; border-radius: 12px; text-align: center; }
.library-load-retry .library-button { margin: 8px; }
.library-skip-link { position: fixed; top: -100px; left: 16px; z-index: 9999; background: #fff; padding: 12px; border-radius: 8px; }
.library-skip-link:focus { top: 12px; }
button:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible, [contenteditable]:focus-visible { outline: 3px solid #7040b4; outline-offset: 3px; }
@media (min-width: 769px) {
    .container.expanded { width: 70%; max-width: 1750px; }
    .library-header { flex-wrap: wrap; }
}
@media (max-width: 768px) {
    .container, .container.expanded { width: 100%; max-width: none; margin-left: 0 !important; padding-left: 16px; padding-right: 16px; }
    .library-header { flex-direction: column; align-items: flex-start; padding-left: 28px; gap: 16px; }
    .library-actions { width: 100%; }
    .library-actions .library-button { flex: 1; }
    .video-row { flex-direction: column; gap: 20px; padding: 14px; }
    .video-container, .images-container { width: 100% !important; min-width: 0; padding: 0; }
    .video-meta-chips { min-width: 0; }
    .master-faces { position: fixed; top: 0; bottom: 0; width: min(85vw, 360px); height: 100dvh; z-index: 1050; }
    #toggle-master-faces.inside { left: calc(min(85vw, 360px) - 54px); }
    .controls, .controls.expanded { left: 0 !important; max-height: 55dvh; overflow-y: auto; padding-bottom: max(16px, env(safe-area-inset-bottom)); }
    .controls .control-group, .controls .control-group--action { flex-basis: calc(50% - 8px); }
    .controls .control-select, .controls .control-action-btn { min-height: 44px; }
    .controls-toggle.controls-open { bottom: calc(55dvh + 8px); }
    .master-search-shell.controls-open { bottom: calc(55dvh + 70px); }
    .container.controls-open { padding-bottom: calc(55dvh + 24px); }
    .master-search-panel { max-width: calc(100vw - 88px); }
    .master-search-input { font-size: 16px; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
}
