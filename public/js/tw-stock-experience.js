(() => {
    'use strict';
    if (!document.body.classList.contains('tw-stock-experience')) return;

    const nav = document.querySelector('nav[aria-label="台股頁面"]');
    if (!nav) return;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const storageKey = 'tw-stock:table-density:v1';
    const make = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    };
    const activeLink = nav.querySelector('a.active');
    activeLink?.setAttribute('aria-current', 'page');
    if (activeLink && nav.scrollWidth > nav.clientWidth) {
        nav.scrollLeft = activeLink.offsetLeft - nav.offsetLeft - (nav.clientWidth - activeLink.offsetWidth) / 2;
    }

    const content = document.querySelector('.shell, .page-shell');
    if (content) {
        if (!content.id) content.id = 'sx-main';
        content.tabIndex = -1;
        const skip = make('a', 'sx-skip', '跳至主要內容');
        skip.href = '#' + content.id;
        document.body.prepend(skip);
    }
    const utilities = make('div', 'sx-utilities');
    utilities.setAttribute('role', 'group');
    utilities.setAttribute('aria-label', '閱讀工具');
    const quick = make('button', '', '快速切頁');
    quick.type = 'button';
    quick.setAttribute('aria-haspopup', 'dialog');
    quick.append(make('kbd', '', 'Ctrl K'));
    const density = make('button');
    density.type = 'button';
    density.title = '記住桌面表格的閱讀密度';
    const note = make('span', 'sx-utility-note', '專注數據，從容閱讀');
    utilities.append(quick, density, note);
    nav.after(utilities);

    const toast = make('div', 'sx-toast');
    toast.setAttribute('role', 'status');
    toast.hidden = true;
    document.body.append(toast);
    let toastTimer;
    const announce = message => {
        clearTimeout(toastTimer);
        toast.textContent = message;
        toast.hidden = false;
        toastTimer = setTimeout(() => { toast.hidden = true; }, 2400);
    };
    const setCompact = compact => {
        document.body.classList.toggle('sx-compact', compact);
        density.textContent = compact ? '緊湊閱讀' : '舒適閱讀';
        density.setAttribute('aria-pressed', String(compact));
        // Existing chart/sticky-header code already listens for resize.
        window.dispatchEvent(new Event('resize'));
    };
    let compact = false;
    try { compact = localStorage.getItem(storageKey) === 'compact'; } catch { /* Storage may be unavailable. */ }
    setCompact(compact);
    density.addEventListener('click', () => {
        compact = !compact;
        setCompact(compact);
        try { localStorage.setItem(storageKey, compact ? 'compact' : 'comfortable'); } catch { /* Keep session state. */ }
        announce((compact ? '已切換緊湊閱讀' : '已切換舒適閱讀') + (innerWidth <= 900 ? '，桌面寬度時套用' : ''));
    });

    const dialog = make('dialog', 'sx-dialog');
    dialog.id = 'sx-quick-pages';
    dialog.setAttribute('aria-labelledby', 'sx-dialog-title');
    quick.setAttribute('aria-controls', dialog.id);
    const dialogHead = make('div', 'sx-dialog-head');
    const title = make('h2', '', '前往哪個看板？');
    title.id = 'sx-dialog-title';
    const close = make('button', 'sx-dialog-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', '關閉快速切頁');
    dialogHead.append(title, close);
    const label = make('label', '', '搜尋頁面名稱');
    label.htmlFor = 'sx-page-query';
    const input = make('input');
    input.id = 'sx-page-query';
    input.type = 'search';
    input.placeholder = '例如：營收、EPS、ETF';
    input.autocomplete = 'off';
    const destinations = make('ul', 'sx-destinations');
    const links = Array.from(nav.querySelectorAll('a[href]')).map(source => {
        const item = make('li');
        const link = make('a', '', source.textContent.trim());
        link.href = source.href;
        if (source === activeLink) link.setAttribute('aria-current', 'page');
        item.append(link);
        destinations.append(item);
        return { item, link, text: link.textContent.toLocaleLowerCase().replace(/\s/g, '') };
    });
    const status = make('div', 'sx-dialog-status');
    status.setAttribute('role', 'status');
    const filter = () => {
        const query = input.value.trim().toLocaleLowerCase().replace(/\s/g, '');
        let count = 0;
        links.forEach(entry => {
            entry.item.hidden = !entry.text.includes(query);
            if (!entry.item.hidden) count++;
        });
        status.textContent = count ? `${count} 個頁面 · ↓ 選擇 · Enter 開啟 · Esc 關閉` : '找不到符合的頁面，試試其他關鍵字。';
    };
    dialog.append(dialogHead, label, input, destinations, status);
    document.body.append(dialog);
    let returnFocus;
    const openDialog = () => {
        if (dialog.open) return;
        returnFocus = document.activeElement;
        input.value = '';
        filter();
        dialog.showModal();
        input.focus();
    };
    quick.addEventListener('click', openDialog);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => returnFocus?.focus({ preventScroll: true }));
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
    input.addEventListener('input', filter);
    dialog.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !event.isComposing) {
            event.preventDefault();
            event.stopPropagation();
            dialog.close();
            return;
        }
        const visible = links.filter(entry => !entry.item.hidden).map(entry => entry.link);
        if (!visible.length) return;
        const index = visible.indexOf(document.activeElement);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = event.key === 'ArrowDown' ? (index + 1) % visible.length : (index <= 0 ? visible.length - 1 : index - 1);
            visible[next].focus();
        } else if (event.key === 'Enter' && event.target === input) {
            event.preventDefault();
            visible[0].click();
        }
    });
    document.addEventListener('keydown', event => {
        if (event.isComposing || event.repeat || event.altKey || event.shiftKey || dialog.open) return;
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openDialog();
        }
    });

    const progress = make('div', 'sx-progress');
    progress.setAttribute('aria-hidden', 'true');
    const top = make('button', 'sx-top', '↑ 回到頂端');
    top.type = 'button';
    top.hidden = true;
    top.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth' });
        content?.focus({ preventScroll: true });
    });
    document.body.append(progress, top);
    let scrollPending = false;
    const updateScroll = () => {
        scrollPending = false;
        const max = document.documentElement.scrollHeight - innerHeight;
        progress.style.transform = `scaleX(${max > 0 ? Math.min(1, Math.max(0, scrollY / max)) : 0})`;
        top.hidden = scrollY < 500;
    };
    const scheduleScroll = () => {
        if (scrollPending) return;
        scrollPending = true;
        requestAnimationFrame(updateScroll);
    };
    window.addEventListener('scroll', scheduleScroll, { passive: true });
    window.addEventListener('resize', scheduleScroll);
    window.addEventListener('pageshow', scheduleScroll);
    updateScroll();

    // Observe existing scrolling wrappers only; never move tables or chart elements.
    const wrappers = Array.from(document.querySelectorAll('.table-wrap, .table-scroll, .table-panel'))
        .filter(node => node.querySelector('table') && !node.querySelector('.table-wrap, .table-scroll'));
    const overflowNotes = wrappers.map(wrapper => {
        const hint = make('div', 'sx-scroll-note', '↔ 左右捲動查看完整欄位 · 聚焦後可用方向鍵');
        hint.hidden = true;
        wrapper.before(hint);
        return { wrapper, hint, originalTabIndex: wrapper.getAttribute('tabindex') };
    });
    const updateOverflow = () => {
        overflowNotes.forEach(({ wrapper, hint, originalTabIndex }) => {
            const overflow = wrapper.clientWidth > 0 && wrapper.scrollWidth > wrapper.clientWidth + 2;
            const scrollable = ['auto', 'scroll'].includes(getComputedStyle(wrapper).overflowX);
            hint.hidden = !overflow || !scrollable;
            wrapper.classList.toggle('sx-table-scroll', overflow && scrollable);
            if (overflow && scrollable) {
                if (originalTabIndex === null) wrapper.tabIndex = 0;
            } else if (originalTabIndex === null) wrapper.removeAttribute('tabindex');
        });
    };
    let overflowFrame;
    const scheduleOverflow = () => {
        cancelAnimationFrame(overflowFrame);
        overflowFrame = requestAnimationFrame(updateOverflow);
    };
    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(scheduleOverflow);
        overflowNotes.forEach(({ wrapper }) => { observer.observe(wrapper); observer.observe(wrapper.querySelector('table')); });
    }
    window.addEventListener('resize', scheduleOverflow);
    updateOverflow();
})();
