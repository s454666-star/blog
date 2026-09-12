<script>
(() => {
const make = (tag, className, text) => { const node = document.createElement(tag); node.className = className; if (text) node.textContent = text; return node; };
    // Observe existing scrolling wrappers only; never move tables or chart elements.
    const wrappers = Array.from(document.querySelectorAll('.table-wrap'))
        .filter(node => node.querySelector('table') && !node.querySelector('.table-wrap, .table-scroll'));
    const overflowNotes = wrappers.map(wrapper => {
        const hint = make('div', 'crm-scroll-hint', '↔ 左右捲動查看完整欄位 · 聚焦後可用方向鍵');
        hint.hidden = true;
        wrapper.before(hint);
        return { wrapper, hint, originalTabIndex: wrapper.getAttribute('tabindex') };
    });
    const updateOverflow = () => {
        overflowNotes.forEach(({ wrapper, hint, originalTabIndex }) => {
            const overflow = wrapper.clientWidth > 0 && wrapper.scrollWidth > wrapper.clientWidth + 2;
            const scrollable = ['auto', 'scroll'].includes(getComputedStyle(wrapper).overflowX);
            hint.hidden = !overflow || !scrollable;
            wrapper.classList.toggle('crm-table-scroll', overflow && scrollable);
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

    // Keep headers attached to the viewport even when a horizontal wrapper prevents CSS sticky.
    // Observe visibility once; scroll work measures only the tables currently on screen.
    const sourceTables = Array.from(document.querySelectorAll('.content table'))
        .filter(table => table.tHead && !table.closest('.q1-sticky-table-head'));
    const visibleTables = new Set();
    const floating = make('div', 'crm-floating-head');
    floating.hidden = true;
    floating.setAttribute('aria-hidden', 'true');
    document.body.append(floating);
    let currentTable = null;
    let floatingTable = null;
    let frozenColumns = [];
    let headerDirty = true;
    let headerFrame = null;
    const headerObserver = new MutationObserver(() => {
        headerDirty = true;
        scheduleHeader();
    });
    const rebuildHeader = table => {
        headerObserver.disconnect();
        floatingTable = make('table', table.className);
        floatingTable.setAttribute('role', 'presentation');
        const clone = table.tHead.cloneNode(true);
        clone.removeAttribute('id');
        clone.querySelectorAll('[id]').forEach(node => node.removeAttribute('id'));
        clone.querySelectorAll('a, button, input, select, [tabindex]').forEach(node => { node.tabIndex = -1; });
        const originals = Array.from(table.tHead.querySelectorAll('th'));
        frozenColumns = originals.map((cell, index) => {
            const style = getComputedStyle(cell);
            return style.position === 'sticky' && style.left !== 'auto' ? index : -1;
        }).filter(index => index !== -1);
        clone.querySelectorAll('th').forEach((cell, index) => {
            const source = originals[index];
            const style = getComputedStyle(source);
            cell.dataset.crmColumn = String(index);
            cell.style.width = source.getBoundingClientRect().width + 'px';
            for (const property of ['padding', 'font-size', 'font-weight', 'line-height', 'text-align', 'color', 'background-color', 'border-bottom']) {
                cell.style.setProperty(property, style.getPropertyValue(property));
            }
        });
        floatingTable.append(clone);
        floating.replaceChildren(floatingTable);
        headerObserver.observe(table.tHead, { childList: true, characterData: true, subtree: true, attributes: true });
        headerDirty = false;
    };
    const updateHeader = () => {
        headerFrame = null;
        let selected = null;
        for (const table of visibleTables) {
            const rect = table.getBoundingClientRect();
            if (rect.width && rect.top < 0 && rect.bottom > 0 && getComputedStyle(table.tHead).display !== 'none') {
                selected = table;
                break;
            }
        }
        if (!selected) { floating.hidden = true; return; }
        const rect = selected.getBoundingClientRect();
        const wrapper = selected.closest('.table-wrap') || selected.parentElement;
        const wrap = wrapper.getBoundingClientRect();
        const left = Math.max(0, wrap.left + wrapper.clientLeft);
        const right = Math.min(innerWidth, wrap.left + wrapper.clientLeft + wrapper.clientWidth);
        if (right <= left) { floating.hidden = true; return; }
        if (currentTable !== selected || headerDirty) {
            currentTable = selected;
            rebuildHeader(selected);
        }
        const height = selected.tHead.getBoundingClientRect().height;
        floating.style.left = left + 'px';
        floating.style.width = right - left + 'px';
        floating.style.top = Math.min(0, rect.bottom - height) + 'px';
        floatingTable.style.width = rect.width + 'px';
        floatingTable.style.transform = `translateX(${rect.left - left}px)`;
        floating.hidden = false;
        // Match frozen stock/rank columns when the underlying table scrolls sideways.
        const originalCells = Array.from(selected.tHead.querySelectorAll('th'));
        const floatingCells = Array.from(floatingTable.querySelectorAll('th'));
        frozenColumns.forEach(index => { floatingCells[index].style.transform = ''; });
        const frozen = frozenColumns.map(index => ({
            index, offset: originalCells[index].getBoundingClientRect().left - floatingCells[index].getBoundingClientRect().left,
        }));
        frozen.forEach(({ index, offset }) => {
            floatingCells[index].style.transform = `translateX(${offset}px)`;
            floatingCells[index].style.zIndex = '3';
        });
    };
    function scheduleHeader() {
        if (headerFrame === null) headerFrame = requestAnimationFrame(updateHeader);
    }
    const tableVisibility = new IntersectionObserver(entries => {
        entries.forEach(entry => entry.isIntersecting ? visibleTables.add(entry.target) : visibleTables.delete(entry.target));
        scheduleHeader();
    });
    sourceTables.forEach(table => tableVisibility.observe(table));
    const headerSize = new ResizeObserver(() => { headerDirty = true; scheduleHeader(); });
    sourceTables.forEach(table => headerSize.observe(table));
    document.addEventListener('scroll', scheduleHeader, { capture: true, passive: true });
    window.addEventListener('resize', () => { headerDirty = true; scheduleHeader(); });
    document.fonts?.ready.then(() => { headerDirty = true; scheduleHeader(); });
    floating.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const cell = event.target.closest('th[data-crm-column]');
        if (!cell || !currentTable) return;
        const original = currentTable.tHead.querySelectorAll('th')[Number(cell.dataset.crmColumn)];
        const target = event.target.closest('a, button');
        event.preventDefault();
        if (target) {
            const index = Array.from(cell.querySelectorAll('a, button')).indexOf(target);
            original.querySelectorAll('a, button')[index]?.click();
        } else {
            original.click();
        }
    });
})();

</script>
