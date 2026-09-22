(() => {
    'use strict';
    const $ = (selector) => document.querySelector(selector);
    let toastTimer;
    function toast(message, error = false) {
        const box = $('#toast');
        box.textContent = message;
        box.classList.toggle('error', error);
        box.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { box.hidden = true; }, error ? 7000 : 4000);
    }
    if (!$('#toast').hidden) toastTimer = setTimeout(() => { $('#toast').hidden = true; }, 4500);
    document.querySelectorAll('[data-open]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.open).showModal()));
    document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    document.querySelectorAll('dialog').forEach(dialog => {
        if (dialog.hasAttribute('data-reopen')) dialog.showModal();
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const rect = dialog.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
        });
    });
    document.querySelectorAll('[data-delete-url]').forEach(button => button.addEventListener('click', () => {
        $('#delete-form').action = button.dataset.deleteUrl;
        $('#delete-title').textContent = button.dataset.deleteTitle;
        $('#delete-dialog').showModal();
    }));
    const picker = $('#video-picker');
    if (picker) {
        let currentPath = '', parentPath = '', browseSequence = 0, searchTimer;
        let dropped = [], dropGeneration = 0, resolving = 0, batchSaving = false;
        const dropzone = $('#video-dropzone');
        const createButton = $('#create-dialog button[type="submit"]');
        function renderDropped() {
            $('#drop-matches').replaceChildren();
            $('#new-title').readOnly = dropped.length > 1;
            if (!dropped.length) { createButton.textContent = '建立影片誌 ↗'; $('#new-source').value = ''; $('#new-title').value = ''; $('#video-drop-status').textContent = ''; return; }
            const ready = dropped.filter(item => item.source).length;
            $('#new-source').value = dropped.length === 1 ? (dropped[0].source || '') : `已選 ${dropped.length} 部影片`;
            $('#new-title').value = dropped.length === 1 ? Array.from(dropped[0].file.name).slice(0, 200).join('') : '各自使用原始檔案名稱';
            createButton.textContent = `建立 ${dropped.length} 篇影片誌 ↗`;
            $('#video-drop-status').textContent = resolving ? '正在確認影片來源…' : ready === dropped.length ? `${ready} 部影片已準備好，點選下方按鈕一起新增。` : `${ready} / ${dropped.length} 部來源已確認。瀏覽器未提供完整路徑的影片，請確認一次所在資料夾。`;
            dropped.forEach((item, index) => {
                const row = document.createElement('div'); row.className = 'drop-row';
                const info = document.createElement('span'); info.textContent = item.file.name;
                const state = document.createElement('small'); state.textContent = item.source ? '✓ 已確認來源' : (item.error || '待確認所在資料夾'); info.append(state);
                const locate = document.createElement('button'); locate.type = 'button'; locate.textContent = item.source ? '重新確認' : '確認資料夾'; locate.disabled = batchSaving || resolving > 0;
                locate.addEventListener('click', () => { picker.showModal(); $('#picker-search').value = ''; browse(currentPath); });
                const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', '移除 ' + item.file.name); remove.disabled = batchSaving || resolving > 0;
                remove.addEventListener('click', () => { dropped.splice(index, 1); renderDropped(); });
                row.append(info, locate, remove); $('#drop-matches').append(row);
            });
        }
        async function resolveDropped(folder = '') {
            const generation = ++dropGeneration;
            const queue = dropped.filter(item => !item.source || folder);
            resolving++; renderDropped();
            let next = 0;
            try {
                await Promise.all(Array.from({length: Math.min(4, queue.length)}, async () => {
                    while (next < queue.length) {
                        const item = queue[next++];
                        try {
                            if (!item.fingerprint) {
                                const chunks = await Promise.all([0, Math.max(0, Math.floor((item.file.size - 65536) / 2)), Math.max(0, item.file.size - 65536)].map(offset => item.file.slice(offset, offset + 65536).arrayBuffer()));
                                const sample = new Uint8Array(chunks.reduce((size, chunk) => size + chunk.byteLength, 0));
                                let offset = 0; for (const chunk of chunks) { sample.set(new Uint8Array(chunk), offset); offset += chunk.byteLength; }
                                item.fingerprint = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', sample)), byte => byte.toString(16).padStart(2, '0')).join('');
                            }
                            const response = await fetch(dropzone.dataset.resolveUrl, {method:'POST', headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':$('meta[name="csrf-token"]').content}, body:JSON.stringify({name:item.file.name, size:item.file.size, fingerprint:item.fingerprint, folder:folder || undefined, path:!folder && typeof item.file.path === 'string' ? item.file.path : undefined})});
                            const data = await response.json();
                            if (generation !== dropGeneration) return;
                            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || '來源確認失敗');
                            if (data.matches.length === 1) { item.source = data.matches[0].path; item.error = ''; }
                            else if (!item.source) item.error = data.matches.length > 1 ? '有多個同名來源，請確認資料夾' : '請確認所在資料夾';
                        } catch (error) { if (generation === dropGeneration) item.error = error.message; }
                    }
                }));
            } finally {
                resolving--; renderDropped();
                if (generation === dropGeneration && dropped.length && dropped.every(item => item.source) && picker.open) picker.close();
            }
        }
        let dragDepth = 0;
        dropzone.addEventListener('dragenter', event => { event.preventDefault(); dragDepth++; dropzone.classList.add('drag-over'); });
        dropzone.addEventListener('dragover', event => { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; });
        dropzone.addEventListener('dragleave', () => { if (--dragDepth <= 0) dropzone.classList.remove('drag-over'); });
        function acceptDropped(files) {
            if (batchSaving) return;
            if (!files.length || files.some(file => !/\.(mp4|webm|ogv|mov|m4v)$/i.test(file.name))) { toast('請拖入影片檔案，不支援資料夾或其他檔案。', true); return; }
            if (files.length > 50) { toast('每次最多新增 50 部影片。', true); return; }
            dropped = files.map(file => ({file, source:'', error:''}));
            resolveDropped();
        }
        dropzone.addEventListener('drop', event => {
            event.preventDefault(); event.stopPropagation(); dragDepth = 0; dropzone.classList.remove('drag-over');
            acceptDropped(Array.from(event.dataTransfer.files));
        });
        $('#dropped-files').addEventListener('change', event => { acceptDropped(Array.from(event.target.files)); event.target.value = ''; });
        dropzone.addEventListener('click', () => $('#dropped-files').click());
        dropzone.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); $('#dropped-files').click(); } });
        $('#create-dialog').addEventListener('dragover', event => { if (event.dataTransfer.types.includes('Files')) event.preventDefault(); });
        $('#create-dialog').addEventListener('drop', event => event.preventDefault());
        async function browse(path = '', search = '') {
            const sequence = ++browseSequence;
            $('#picker-items').replaceChildren();
            $('#picker-status').textContent = '正在讀取資料夾…';
            $('#picker-parent').disabled = true;
            const url = new URL(picker.dataset.browseUrl);
            url.searchParams.set('path', path); url.searchParams.set('q', search);
            try {
                const response = await fetch(url, {headers: {Accept: 'application/json'}});
                const data = await response.json();
                if (sequence !== browseSequence) return;
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || '無法讀取資料夾，請稍後重試。');
                currentPath = data.path; parentPath = data.parent;
                if (dropped.length && currentPath) resolveDropped(currentPath);
                $('#picker-path').value = currentPath;
                $('#picker-parent').disabled = parentPath === null;
                $('#picker-status').textContent = data.truncated ? '目前顯示前 300 項，請輸入檔名縮小範圍。' : data.items.length ? (currentPath ? '點選影片即可帶入原始檔名' : '選擇影片所在的磁碟') : '此資料夾沒有符合的影片或資料夾。';
                for (const item of data.items) {
                    const button = document.createElement('button');
                    button.type = 'button'; button.className = 'picker-item';
                    const icon = document.createElement('span'); icon.className = 'picker-icon'; icon.textContent = item.directory ? '▤' : '▷'; icon.setAttribute('aria-hidden', 'true');
                    const name = document.createElement('span'); name.textContent = item.name;
                    const hint = document.createElement('span'); hint.className = 'picker-hint'; hint.textContent = item.directory ? '開啟 →' : '選取 ↗';
                    button.append(icon, name, hint);
                    button.addEventListener('click', () => {
                        if (item.directory) { $('#picker-search').value = ''; browse(item.path); }
                        else {
                            if (dropped.length) { resolveDropped(currentPath); return; }
                            $('#new-source').value = item.path;
                            $('#new-title').value = Array.from(item.name).slice(0, 200).join('');
                            picker.close(); $('#new-title').focus();
                        }
                    });
                    $('#picker-items').append(button);
                }
            } catch (error) {
                if (sequence === browseSequence) $('#picker-status').textContent = error.message;
            }
        }
        $('#choose-video').addEventListener('click', () => { picker.showModal(); $('#picker-search').value = ''; browse(currentPath); });
        $('#picker-location').addEventListener('submit', event => { event.preventDefault(); clearTimeout(searchTimer); $('#picker-search').value = ''; browse($('#picker-path').value); });
        $('#picker-roots').addEventListener('click', () => { clearTimeout(searchTimer); $('#picker-search').value = ''; browse(''); });
        $('#picker-parent').addEventListener('click', () => { clearTimeout(searchTimer); $('#picker-search').value = ''; browse(parentPath || ''); });
        $('#picker-search').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => browse(currentPath, $('#picker-search').value), 250); });
        picker.addEventListener('close', () => { clearTimeout(searchTimer); ++browseSequence; });
        $('#create-dialog form').addEventListener('submit', async event => {
            if (dropped.length) {
                event.preventDefault();
                if (batchSaving || resolving) return;
                if (dropped.some(item => !item.source)) { toast('請先確認清單中每部影片的所在資料夾。', true); return; }
                batchSaving = true; createButton.disabled = true;
                const customTitle = $('#new-title').value.trim(); renderDropped();
                try {
                    const response = await fetch(dropzone.dataset.batchUrl, {method:'POST', headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':$('meta[name="csrf-token"]').content}, body:JSON.stringify({items:dropped.map(item => ({source:item.source, size:item.file.size, fingerprint:item.fingerprint, title:dropped.length === 1 ? customTitle : Array.from(item.file.name).slice(0,200).join('')}))})});
                    const data = await response.json();
                    if (!response.ok) throw new Error(Object.values(data.errors || {}).flat()[0] || '新增失敗，請重試。');
                    location.assign(data.redirect);
                } catch (error) { toast(error.message, true); batchSaving = false; createButton.disabled = false; renderDropped(); }
                return;
            }
            if (!$('#new-source').value) { event.preventDefault(); toast('請先選擇影片。', true); $('#choose-video').focus(); }
        });
    }
    const story = $('#story');
    if (!story) return;
    const video = $('#journal-video');
    let subtitleObjectUrl;
    function applySubtitles(text, isVtt = false) {
        video.querySelectorAll('track').forEach(track => track.remove());
        if (subtitleObjectUrl) URL.revokeObjectURL(subtitleObjectUrl);
        text = text.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
        if (!isVtt && !text.startsWith('WEBVTT')) text = 'WEBVTT\n\n' + text.replace(/(\d{2}:\d{2}:\d{2}),(\d{3})/g, '$1.$2');
        subtitleObjectUrl = URL.createObjectURL(new Blob([text], { type: 'text/vtt' }));
        const track = document.createElement('track');
        track.kind = 'subtitles'; track.label = '字幕'; track.srclang = 'zh'; track.default = true;
        track.src = subtitleObjectUrl;
        video.append(track);
        track.track.mode = 'showing';
        track.addEventListener('load', () => { track.track.mode = 'showing'; });
        $('#subtitle-status').textContent = '字幕已載入 · 可從播放器 CC 切換';
    }
    async function loadAutomaticSubtitles() {
        let url = video.dataset.subtitles;
        if (video.dataset.remote === '1') {
            const remote = new URL(video.getAttribute('src'), location.href);
            if (!/\.[a-z0-9]+$/i.test(remote.pathname)) {
                $('#subtitle-status').textContent = '可手動載入 SRT / VTT 字幕'; return;
            }
            remote.pathname = remote.pathname.replace(/\.[^.\/]+$/, '.srt');
            url = remote.href;
        }
        try {
            const response = await fetch(url, { signal: AbortSignal.timeout(8000) });
            if (!response.ok) throw new Error('Missing subtitles');
            const bytes = await response.arrayBuffer();
            if (bytes.byteLength > 5 * 1024 * 1024) throw new Error('Too large');
            let text;
            try { text = new TextDecoder('utf-8', { fatal: true }).decode(bytes); }
            catch { text = new TextDecoder('big5').decode(bytes); }
            if (!text.includes('-->') && !text.startsWith('WEBVTT')) throw new Error('Invalid subtitles');
            applySubtitles(text);
        } catch {
            $('#subtitle-status').textContent = '未找到同名字幕 · 可手動載入';
        }
    }
    loadAutomaticSubtitles();
    $('#subtitle-file').addEventListener('change', async event => {
        const file = event.target.files[0];
        if (!file) return;
        try {
            if (file.size > 5 * 1024 * 1024) throw new Error('字幕檔請小於 5 MB。');
            const bytes = await file.arrayBuffer();
            let text;
            try { text = new TextDecoder('utf-8', { fatal: true }).decode(bytes); }
            catch { text = new TextDecoder('big5').decode(bytes); }
            if (!text.includes('-->')) throw new Error('找不到有效的字幕時間軸，請選擇 SRT 或 VTT。');
            applySubtitles(text); toast('字幕已載入本次播放');
        } catch (error) { toast(error.message, true); }
        event.target.value = '';
    });
    video.addEventListener('error', () => { $('#player-error').hidden = false; });
    if (video.error) $('#player-error').hidden = false;
    const editor = $('#story-body');
    const title = $('#story-title');
    let editing = false, dirty = false, savedRange = null, selectedImage = null, replacing = false;
    let imageWork = 0, saving = false;
    const metadata = JSON.parse($('#journal-metadata').textContent);
    $('#journal-metadata').remove();
    let tags = metadata.tags, portraits = metadata.portraits, replacingPortrait = null;
    const imageActions = document.createElement('div');
    imageActions.className = 'image-actions'; imageActions.hidden = true;
    imageActions.innerHTML = '<img alt="已選取圖片的縮圖"><span>已選取圖片</span><button type="button" data-image-replace>替換圖片</button><button type="button" data-image-delete>刪除圖片</button>';
    $('#editor-tools').after(imageActions);
    const lightbox = document.createElement('div');
    lightbox.className = 'image-lightbox'; lightbox.hidden = true;
    lightbox.setAttribute('role', 'dialog'); lightbox.setAttribute('aria-label', '圖片全螢幕預覽');
    lightbox.innerHTML = '<img alt="放大預覽"><span class="lightbox-hint">移開滑鼠即可返回文章 · Esc 關閉</span><button type="button" aria-label="關閉圖片預覽">×</button>';
    document.body.append(lightbox);
    const closePreview = () => { lightbox.hidden = true; lightbox.classList.remove('touch-open'); };
    const showPreview = (image, touch = false) => {
        if (editing) return;
        lightbox.querySelector('img').src = image.src;
        lightbox.classList.toggle('touch-open', touch);
        lightbox.hidden = false;
    };
    lightbox.addEventListener('click', closePreview);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') closePreview(); });
    window.addEventListener('blur', closePreview);
    window.addEventListener('scroll', closePreview, { passive: true });
    editor.addEventListener('pointerover', event => {
        if (event.target.tagName === 'IMG' && event.pointerType === 'mouse') showPreview(event.target);
    });
    editor.addEventListener('pointerout', event => { if (event.target.tagName === 'IMG') closePreview(); });
    function markDirty() { dirty = true; $('#save-state').textContent = '有尚未儲存的變更'; }
    function renderMetadata() {
        $('#tag-count').textContent = tags.length + ' / 5';
        $('#portrait-count').textContent = portraits.length + ' / 5';
        $('#tags-empty').hidden = tags.length > 0;
        $('#portraits-empty').hidden = portraits.length > 0;
        $('#tag-controls').hidden = !editing;
        $('#portrait-controls').hidden = !editing;
        $('#add-portrait').disabled = portraits.length >= 5 || imageWork > 0;
        $('#tag-list').replaceChildren();
        tags.forEach((tag, index) => {
            const chip = document.createElement('span'); chip.className = 'tag-chip'; chip.textContent = '# ' + tag;
            if (editing) {
                const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = '×'; remove.setAttribute('aria-label', '移除標籤 ' + tag);
                remove.addEventListener('click', () => { tags.splice(index, 1); markDirty(); renderMetadata(); }); chip.append(remove);
            }
            $('#tag-list').append(chip);
        });
        $('#portrait-list').replaceChildren();
        portraits.forEach((src, index) => {
            const item = document.createElement('div'); item.className = 'portrait-item';
            const img = document.createElement('img'); img.src = src; img.alt = '大頭照 ' + (index + 1);
            img.addEventListener('click', () => showPreview(img, true));
            const label = document.createElement('span'); label.className = 'portrait-label'; label.textContent = index === 0 ? '查詢卡片大頭照' : '大頭照 ' + (index + 1);
            item.append(img, label);
            if (editing) {
                const actions = document.createElement('div'); actions.className = 'portrait-actions';
                const action = (text, callback) => { const button = document.createElement('button'); button.type = 'button'; button.textContent = text; button.disabled = imageWork > 0; button.addEventListener('click', callback); actions.append(button); };
                action('替換', () => { replacingPortrait = index; $('#portrait-file').multiple = false; $('#portrait-file').click(); });
                action('刪除', () => { portraits.splice(index, 1); markDirty(); renderMetadata(); });
                if (index > 0) action('設為首張', () => { portraits.unshift(portraits.splice(index, 1)[0]); markDirty(); renderMetadata(); });
                item.append(actions);
            }
            $('#portrait-list').append(item);
        });
    }
    function addTag() {
        const value = $('#tag-input').value.trim().replace(/^#+/, '').trim();
        if (!value) { $('#tag-input').value = ''; return true; }
        if (Array.from(value).length > 40) { toast('每個標籤最多 40 個字。', true); return false; }
        if (tags.includes(value)) { $('#tag-input').value = ''; return true; }
        if (tags.length >= 5) { toast('每篇最多 5 個標籤。', true); return false; }
        tags.push(value); $('#tag-input').value = ''; markDirty(); renderMetadata(); return true;
    }
    $('#add-tag').addEventListener('click', addTag);
    $('#tag-input').addEventListener('input', markDirty);
    $('#tag-input').addEventListener('keydown', event => { if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); addTag(); } });
    $('#add-portrait').addEventListener('click', () => { replacingPortrait = null; $('#portrait-file').multiple = true; $('#portrait-file').click(); });
    $('#portrait-file').addEventListener('change', async event => {
        const files = Array.from(event.target.files);
        if (!files.length) return;
        if (replacingPortrait === null && portraits.length + files.length > 5) { toast('每篇最多 5 張大頭照。', true); event.target.value = ''; return; }
        imageBusy(1);
        try {
            const pending = [];
            for (const file of files) {
                await readImage(file); // Same file type and 20 MB limits as article images.
                const bitmap = await createImageBitmap(file);
                try {
                    const scale = Math.min(1, 1920 / bitmap.width, 1080 / bitmap.height);
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.max(1, Math.floor(bitmap.width * scale)); canvas.height = Math.max(1, Math.floor(bitmap.height * scale));
                    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                    pending.push(canvas.toDataURL('image/webp', .85));
                } finally { bitmap.close(); }
            }
            if (replacingPortrait === null) portraits.push(...pending);
            else portraits[replacingPortrait] = pending[0];
            markDirty(); renderMetadata();
        } catch (error) { toast('大頭照處理失敗：' + error.message, true); }
        finally { imageBusy(-1); event.target.value = ''; replacingPortrait = null; }
    });
    renderMetadata();
    function clearImage() {
        selectedImage?.classList.remove('selected-image'); selectedImage = null; imageActions.hidden = true;
    }
    editor.addEventListener('click', event => {
        if (event.target.tagName !== 'IMG') { clearImage(); return; }
        if (!editing) { showPreview(event.target, true); return; }
        clearImage(); selectedImage = event.target; selectedImage.classList.add('selected-image');
        imageActions.querySelector('img').src = selectedImage.src;
        imageActions.hidden = false;
    });
    function setEditing(value) {
        editing = value; closePreview(); clearImage();
        editor.contentEditable = String(value); title.readOnly = !value;
        $('#editor-tools').hidden = !value; $('#save-bar').hidden = !value;
        $('#empty-body').hidden = value || Boolean(editor.textContent.trim() || editor.querySelector('img'));
        $('#edit-toggle').textContent = value ? '◉ 預覽文章' : '✎ 編輯文章';
        renderMetadata();
    }
    $('#edit-toggle').addEventListener('click', () => setEditing(!editing));
    title.addEventListener('input', markDirty); editor.addEventListener('input', markDirty);
    document.addEventListener('selectionchange', () => {
        const selection = window.getSelection();
        if (selection.rangeCount && editor.contains(selection.anchorNode)) savedRange = selection.getRangeAt(0).cloneRange();
    });
    function restoreSelection() {
        editor.focus(); const selection = window.getSelection();
        selection.removeAllRanges();
        if (savedRange && editor.contains(savedRange.startContainer)) selection.addRange(savedRange);
        else { const range = document.createRange(); range.selectNodeContents(editor); range.collapse(false); selection.addRange(range); }
    }
    document.querySelectorAll('[data-command]').forEach(button => {
        button.addEventListener('mousedown', event => event.preventDefault());
        button.addEventListener('click', () => { restoreSelection(); document.execCommand(button.dataset.command, false, button.dataset.value || null); markDirty(); });
    });
    function insertHtml(html) { restoreSelection(); document.execCommand('insertHTML', false, html); markDirty(); }
    async function readImage(file) {
        if (!['image/png', 'image/jpeg', 'image/webp', 'image/gif'].includes(file.type)) throw new Error('請使用 PNG、JPEG、WebP 或 GIF 圖片。');
        if (file.size > 20 * 1024 * 1024) throw new Error('每張圖片最多 20 MB。');
        return new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = () => reject(new Error('圖片讀取失敗')); reader.readAsDataURL(file); });
    }
    function imageBusy(value) {
        imageWork += value; $('#save-button').disabled = imageWork > 0 || saving; $('#edit-toggle').disabled = imageWork > 0;
        document.querySelectorAll('.portrait-actions button').forEach(button => { button.disabled = imageWork > 0; });
        $('#add-portrait').disabled = imageWork > 0 || portraits.length >= 5;
    }
    $('#insert-image').addEventListener('click', () => { replacing = false; $('#image-file').multiple = true; $('#image-file').click(); });
    imageActions.querySelector('[data-image-replace]').addEventListener('click', () => { replacing = true; $('#image-file').multiple = false; $('#image-file').click(); });
    imageActions.querySelector('[data-image-delete]').addEventListener('click', () => { selectedImage?.remove(); clearImage(); markDirty(); });
    $('#image-file').addEventListener('change', async event => {
        const target = replacing ? selectedImage : null;
        imageBusy(1);
        try {
            for (const file of event.target.files) {
                const src = await readImage(file);
                if (target && editor.contains(target)) { target.src = src; imageActions.querySelector('img').src = src; markDirty(); }
                else insertHtml('<img src="' + src + '" alt="貼上的圖片"><p><br></p>');
            }
        } catch (error) { toast(error.message, true); }
        finally { imageBusy(-1); event.target.value = ''; replacing = false; }
    });
    function safePaste(html) {
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        parsed.querySelectorAll('script,style,iframe,object,svg,math,template').forEach(node => node.remove());
        const tags = new Set(['P','DIV','BR','H2','H3','STRONG','B','EM','I','U','S','UL','OL','LI','BLOCKQUOTE','PRE','CODE','IMG']);
        let removedImage = false;
        Array.from(parsed.body.querySelectorAll('*')).reverse().forEach(node => {
            if (node.tagName === 'IMG' && !/^data:image\/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=\r\n]+$/.test(node.getAttribute('src') || '')) { node.remove(); removedImage = true; return; }
            if (!tags.has(node.tagName)) { node.replaceWith(...node.childNodes); return; }
            for (const attr of Array.from(node.attributes)) if (!(node.tagName === 'IMG' && attr.name === 'src')) node.removeAttribute(attr.name);
        });
        if (removedImage) toast('外部圖片請複製圖片本身再貼上，或使用「插入圖片」。', true);
        return parsed.body.innerHTML;
    }
    editor.addEventListener('paste', async event => {
        if (!editing) return;
        event.preventDefault();
        const clipboard = event.clipboardData;
        const files = Array.from(clipboard.files).filter(file => file.type.startsWith('image/'));
        imageBusy(1);
        try {
            const html = clipboard.getData('text/html');
            const plain = clipboard.getData('text/plain');
            if (html) insertHtml(safePaste(html));
            else if (plain) { restoreSelection(); document.execCommand('insertText', false, plain); markDirty(); }
            if (!html || !/src=["']data:image\//i.test(html)) {
                for (const file of files) insertHtml('<img src="' + await readImage(file) + '" alt="貼上的圖片"><p><br></p>');
            }
        } catch (error) { toast(error.message, true); }
        finally { imageBusy(-1); }
    });
    // Browser-native HTML drops bypass paste filtering; use the explicit image control instead.
    editor.addEventListener('drop', event => { event.preventDefault(); toast('請使用貼上或「插入圖片」加入圖片。'); });
    $('#save-button').addEventListener('click', async () => {
        if (saving || imageWork > 0) return;
        if (!addTag()) return;
        if (!title.value.trim()) { toast('請填寫文章標題。', true); title.focus(); return; }
        clearImage();
        if (new Blob([editor.innerHTML, ...portraits]).size > 64 * 1024 * 1024) { toast('文章與大頭照合計最多 64 MB。', true); return; }
        const payload = { title: title.value.trim(), body: editor.innerHTML, tags: [...tags], portraits: [...portraits] };
        saving = true; $('#save-button').disabled = true; $('#save-state').textContent = '正在壓縮圖片並儲存文章…';
        try {
            const response = await fetch(story.dataset.saveUrl, { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content }, body: JSON.stringify(payload) });
            let data;
            try { data = await response.json(); } catch { throw new Error('儲存失敗，請確認連線或文章大小後重試。'); }
            if (!response.ok) throw new Error(response.status === 419 ? '頁面已過期，請先複製內文備份，再重新整理。' : Object.values(data.errors || {}).flat()[0] || '儲存失敗，請稍後重試。');
            const unchanged = title.value.trim() === payload.title && editor.innerHTML === payload.body
                && JSON.stringify(tags) === JSON.stringify(payload.tags) && portraits.length === payload.portraits.length
                && portraits.every((src, i) => src === payload.portraits[i]) && !$('#tag-input').value.trim();
            if (unchanged) { editor.innerHTML = data.body; tags = data.tags; portraits = data.portraits; dirty = false; setEditing(false); }
            $('#updated-at').textContent = data.updated_at;
            document.title = payload.title + ' — FRAME / 影片生活誌';
            $('#save-state').textContent = unchanged ? '所有變更已儲存' : '有尚未儲存的變更';
            toast(unchanged ? '文章已儲存，文字與圖片都收藏好了。' : '先前變更已儲存，後續編輯請再次儲存。');
        } catch (error) { $('#save-state').textContent = '儲存失敗，內容仍保留在畫面'; toast(error.message, true); }
        finally { saving = false; $('#save-button').disabled = imageWork > 0; }
    });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('keydown', event => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && editing) { event.preventDefault(); if (!$('#save-button').disabled) $('#save-button').click(); } });
})();
