/* --------------------------------------------------
     * 全域變數
     * -------------------------------------------------- */
    const baseVideoUrl = '{{ rtrim(config('app.video_base_url'), '/') }}';
    let missingOnly = {{ $missingOnly ? 'true' : 'false' }};
    let latestId = {{ $latestId ?? 'null' }};
    let sortBy = '{{ $sortBy }}';
    let sortDir = '{{ $sortDir }}';
    let lastPage = {{ $lastPage ?? 1 }};
    let loadedPages = [{{ $videos->currentPage() }}];
    let nextPage = {{ $nextPage ?? 'null' }};
    let prevPage = {{ $prevPage ?? 'null' }};
    let loading = false;

    let videoList = [];
    let currentVideoIndex = 0;
    let playMode = {{ request('play_mode') ? '1' : '0' }};
    let currentFSVideo = null;

    let videoSize = {{ request('video_size',25) }};
    let imageSize = {{ request('image_size',200) }};
    let videoType = '{{ request('video_type','1') }}';

    let initialFocusId = {{ $focusId ?? 'null' }};
    const initialFocusTargetId = initialFocusId !== null ? initialFocusId : latestId;
    let masterFacesPage = 0;
    let masterFacesLastPage = 1;
    let masterFacesLoading = false;
    let masterFacesLoadedCount = 0;
    const pendingFaceUploads = new Set();
    const pendingMasterUpdates = new Set();
    const initialFocusRetryDelays = [0, 120, 320, 700, 1300];

    if ('scrollRestoration' in window.history) {
        window.history.scrollRestoration = 'manual';
    }

    $('#video-type, #sort-by, #sort-dir').on('change', function () {
        setTimeout(() => $('#controls-form').trigger('submit'), 0);
    });

    /* --- 只顯示未選主面切換 --- */
    $('#missing-only')
        .on('input', function () {                // 拖動時即時顯示文字
            missingOnly = $(this).val() === '1';
            updateMissingOnlyLabel();
            updateRangeProgress(this);
        })
        .on('change', function () {               // 放開滑鼠 → 重新整理
            missingOnly = $(this).val() === '1';
            updateRangeProgress(this);
            $('#controls-form').submit();
        });
    /* 第一次進頁面就寫一次文字 */
    updateMissingOnlyLabel();

    /* --------------------------------------------------
     * 快訊訊息
     * -------------------------------------------------- */
    function showMessage(type, text) {
        const $mc = $('#message-container');
        const $msg = $('<div class="message"></div>')
            .addClass(type === 'success' ? 'success' : 'error')
            .text(text);
        $mc.append($msg);
        setTimeout(() => {
            $msg.fadeOut(500, () => {
                $msg.remove();
            });
        }, 1000);
    }

    function getCurrentFocusedVideoId() {
        return Number($('.video-row.focused').data('id') || $('#focus-id').val() || 0) || null;
    }

    function normalizeMediaPath(path) {
        return String(path || '').replace(/^\/+/, '');
    }

    function updateMasterFacesStatus(text, hidden = false) {
        const $status = $('#master-faces-status');
        $status.text(text || '');
        $status.toggleClass('is-hidden', !!hidden);
    }

    function updateRangeProgress(input) {
        if (!input) {
            return;
        }

        const min = Number(input.min ?? 0);
        const max = Number(input.max ?? 100);
        const value = Number(input.value ?? min);
        const progress = max === min ? 0 : ((value - min) / (max - min)) * 100;

        input.style.setProperty('--range-progress', `${progress}%`);
    }

    function updateRangeBadge(selector, text, active = false) {
        const $badge = $(selector);
        $badge.text(text);
        $badge.toggleClass('is-active', !!active);
    }

    /* --------------------------------------------------
     * 分頁載入 / 排序
     * -------------------------------------------------- */
    function recalcPages() {
        const min = Math.min.apply(null, loadedPages);
        const max = Math.max.apply(null, loadedPages);
        prevPage = min > 1 ? (min - 1) : null;
        nextPage = max < lastPage ? (max + 1) : null;
    }

    /* --- 只顯示未選主面滑動開關 --- */
    function updateMissingOnlyLabel() {
        updateRangeBadge('#missing-only-label', missingOnly ? '開啟' : '關閉', missingOnly);
    }

    function updatePlayModeLabel() {
        updateRangeBadge('#play-mode-label', playMode === '1' ? '自動' : '循環', playMode === '1');
    }

    function updateControlRangeBadges() {
        updateRangeBadge('#video-size-value', `${videoSize}%`);
        updateRangeBadge('#image-size-value', `${imageSize}px`);
    }

    function refreshControlRanges() {
        $('#controls-form input[type="range"]').each(function () {
            updateRangeProgress(this);
        });
    }

    function compareWithTiebreaker(primaryA, secondaryA, primaryB, secondaryB) {
        if (primaryA !== primaryB) {
            return sortDir === 'asc' ? (primaryA - primaryB) : (primaryB - primaryA);
        }

        return sortDir === 'asc' ? (secondaryA - secondaryB) : (secondaryB - secondaryA);
    }

    function getRowSortParts(el) {
        const $el = $(el);
        const id = parseInt($el.data('id'), 10) || 0;
        const duration = parseFloat($el.data('duration')) || 0;

        return sortBy === 'duration'
            ? {primary: duration, secondary: id}
            : {primary: id, secondary: id};
    }

    function compareVideoRows(a, b) {
        const left = getRowSortParts(a);
        const right = getRowSortParts(b);

        return compareWithTiebreaker(left.primary, left.secondary, right.primary, right.secondary);
    }

    function scrollVideoRowToFocus(rowElement, behavior = 'smooth') {
        if (!rowElement) {
            return;
        }

        const rect = rowElement.getBoundingClientRect();
        const controlsHeight = $('.controls.controls-open').outerHeight() || 0;
        const usableViewportHeight = Math.max(window.innerHeight - controlsHeight, 0);
        const targetTop = window.scrollY + rect.top - Math.max((usableViewportHeight - rect.height) / 2, 0);

        window.scrollTo({
            top: Math.max(targetTop, 0),
            behavior,
        });
    }

    function focusVideoRowById(targetId, options = {}) {
        const {behavior = 'smooth', syncSidebar = true} = options;
        const $target = $('.video-row[data-id="' + targetId + '"]');

        if (!$target.length) {
            return false;
        }

        $('.video-row').removeClass('focused');
        $target.addClass('focused');
        $('#focus-id').val(targetId);

        if (syncSidebar) {
            focusMasterFace(targetId);
        }

        scrollVideoRowToFocus($target[0], behavior);

        return true;
    }

    function scheduleInitialFocusSequence() {
        if (initialFocusTargetId === null) {
            return;
        }

        initialFocusRetryDelays.forEach((delay, index) => {
            window.setTimeout(() => {
                focusInitial(index > 0);
            }, delay);
        });
    }

    function collectExistingVideoIds() {
        return new Set(
            $('.video-row').map(function () {
                return String($(this).data('id'));
            }).get().filter(Boolean)
        );
    }

    function filterUniqueVideoRows($rows) {
        const existingIds = collectExistingVideoIds();
        const incomingIds = new Set();

        return $rows.filter(function () {
            const id = String($(this).data('id') || '');
            if (!id || existingIds.has(id) || incomingIds.has(id)) {
                return false;
            }

            incomingIds.add(id);
            return true;
        });
    }

    function dedupeRenderedVideoRows() {
        const seen = new Set();

        $('.video-row').each(function () {
            const id = String($(this).data('id') || '');
            if (!id) {
                return;
            }

            if (seen.has(id)) {
                $(this).remove();
                return;
            }

            seen.add(id);
        });
    }

    function loadMoreVideos(dir = 'down', target = null) {
        if (loading) return;

        // 沒指定 target 時，判斷是否還有上下頁
        if (!target) {
            if (dir === 'down' && !nextPage) return;
            if (dir === 'up' && !prevPage) return;
        }

        loading = true;
        $('#load-more').show();

        const data = {
            video_type: videoType,
            missing_only: missingOnly ? 1 : 0,
            sort_by: sortBy,
            sort_dir: sortDir,
            page: target ?? (dir === 'down' ? nextPage : prevPage),
            focus_id: $('#focus-id').val()
        };

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data,
            success(res) {
                if (res && res.success && res.data.trim()) {
                    const $temp = $('<div>').html(res.data);
                    const $rows = filterUniqueVideoRows($temp.children('.video-row'));
                    const appendedCount = $rows.length;

                    if (appendedCount > 0) {
                        dir === 'down'
                            ? $('#videos-list').append($rows)
                            : $('#videos-list').prepend($rows);
                    }

                    if (appendedCount > 0 && !loadedPages.includes(res.current_page))
                        loadedPages.push(res.current_page);

                    lastPage = res.last_page || lastPage;
                    if (appendedCount > 0) {
                        rebuildAndSort();
                    } else if (!target) {
                        dir === 'down' ? nextPage = res.next_page : prevPage = res.prev_page;
                    }
                } else {
                    if (!target) dir === 'down' ? nextPage = null : prevPage = null;
                    $('#load-more').html('<p>沒有更多資料了。</p>');
                }
                loading = false;
                $('#load-more').hide();
            },
            error() {
                showMessage('error', '載入失敗，請稍後再試。');
                loading = false;
                $('#load-more').hide();
            }
        });
    }

    function loadPageAndFocus(videoId, page) {
        if (!page) {
            showMessage('error', '找不到該影片所在的頁面。');
            return;
        }

        loading = true;
        $('#load-more').show();

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data: {
                page,
                video_type: videoType,
                missing_only: missingOnly ? 1 : 0,
                sort_by: sortBy,
                sort_dir: sortDir,
                focus_id: $('#focus-id').val()
            },
            success(res) {
                if (res && res.success && res.data.trim()) {
                    const $temp = $('<div>').html(res.data);
                    const $rows = filterUniqueVideoRows($temp.children('.video-row'));

                    if ($rows.length) {
                        $('#videos-list').append($rows);
                    }

                    if ($rows.length && !loadedPages.includes(res.current_page))
                        loadedPages.push(res.current_page);

                    lastPage = res.last_page || lastPage;
                    rebuildAndSort();

                    const $target = $('.video-row[data-id="' + videoId + '"]');
                    if ($target.length) {
                        $('.video-row').removeClass('focused');
                        $target.addClass('focused');
                        focusMasterFace(videoId);
                        $target[0].scrollIntoView({behavior: 'smooth', block: 'center'});
                    }
                } else {
                    showMessage('error', '無法載入該頁資料。');
                }
                loading = false;
                $('#load-more').hide();
            },
            error() {
                showMessage('error', '載入失敗，請稍後再試。');
                loading = false;
                $('#load-more').hide();
            }
        });
    }

    function rebuildAndSort() {
        const currentId = $('.video-row.focused').data('id') || null;

        dedupeRenderedVideoRows();
        const rows = $('.video-row').get().sort(compareVideoRows);

        $('#videos-list').empty().append(rows);
        buildVideoList();
        applySizes();
        applyMediaPerfOptimizations();
        recalcPages();

        if (currentId) {
            const $t = $('.video-row[data-id="' + currentId + '"]');
            if ($t.length) {
                $('.video-row').removeClass('focused');
                $t.addClass('focused');
                focusMasterFace(currentId);
            }
            $('#focus-id').val(currentId);
        }

        // ★★★ 這一行是關鍵：右側重排後，左側也依同樣的 sortBy/sortDir 重新排
        resortMasterFacesByCurrentSort();
    }

    /* --------------------------------------------------
     * 影片列表 / 尺寸
     * -------------------------------------------------- */
    function buildVideoList() {
        videoList = [];
        $('.video-row').each(function () {
            videoList.push({
                id: $(this).data('id'),
                video: $(this).find('video')[0],
                row: $(this)
            });
        });
    }

    function applySizes() {
        $('.video-container').css('width', videoSize + '%');
        $('.images-container').css('width', (100 - videoSize) + '%');
        $('.screenshot,.face-screenshot,.face-paste-target').css({
            width: imageSize + 'px',
            height: (imageSize * 0.56) + 'px'
        });
    }

    /* --------------------------------------------------
     * 主面人臉同步
     * -------------------------------------------------- */
    function focusMasterFace(id) {
        $('.master-face-img').removeClass('focused');
        const $t = $(`.master-face-img[data-video-id="${id}"]`).addClass('focused');
        if (!$t.length) return;
        const c = document.querySelector('.master-faces');
        if (!c) return;
        c.scrollTo({top: $t[0].offsetTop - c.clientHeight / 2 + $t[0].clientHeight / 2, behavior: 'smooth'});
    }

    function releaseRowMediaSources($row) {
        const states = [];

        $row.find('video').each(function () {
            const video = this;
            const sourceStates = Array.from(video.querySelectorAll('source')).map(source => ({
                element: source,
                src: source.getAttribute('src'),
                type: source.getAttribute('type'),
            }));

            states.push({
                element: video,
                src: video.getAttribute('src'),
                poster: video.getAttribute('poster'),
                preload: video.getAttribute('preload'),
                sourceStates,
            });

            try {
                video.pause();
            } catch (err) {
                console.warn('pause video failed before delete', err);
            }

            video.removeAttribute('src');
            video.removeAttribute('poster');
            sourceStates.forEach(({element}) => element.removeAttribute('src'));
            video.load();
        });

        return function restore() {
            states.forEach(({element, src, poster, preload, sourceStates}) => {
                if (src) {
                    element.setAttribute('src', src);
                } else {
                    element.removeAttribute('src');
                }

                if (poster) {
                    element.setAttribute('poster', poster);
                } else {
                    element.removeAttribute('poster');
                }

                if (preload) {
                    element.setAttribute('preload', preload);
                } else {
                    element.removeAttribute('preload');
                }

                sourceStates.forEach(({element: sourceEl, src: sourceSrc, type}) => {
                    if (sourceSrc) {
                        sourceEl.setAttribute('src', sourceSrc);
                    } else {
                        sourceEl.removeAttribute('src');
                    }

                    if (type) {
                        sourceEl.setAttribute('type', type);
                    } else {
                        sourceEl.removeAttribute('type');
                    }
                });

                element.load();
            });
        };
    }

    /* --------------------------------------------------
     * 全螢幕播放
     * -------------------------------------------------- */
    function enterFullScreen(video) {
        /* ------- 全螢幕時一律循環 ------- */
        video.loop = true;                 // JS 屬性
        video.setAttribute('loop', '');    // HTML 屬性，兼容所有瀏覽器

        try {
            if (video.requestFullscreen) {
                video.requestFullscreen().then(() => {
                    $('body').addClass('fullscreen-mode');
                    video.play();          // 重新播放，確保 loop 生效
                });
            } else if (video.webkitRequestFullscreen) {
                video.webkitRequestFullscreen();
                $('body').addClass('fullscreen-mode');
                video.play();
            } else if (video.msRequestFullscreen) {
                video.msRequestFullscreen();
                $('body').addClass('fullscreen-mode');
                video.play();
            } else {
                $('body').addClass('fullscreen-mode');
                video.play();
            }
        } catch (err) {
            console.error(err);
        }
    }

    function exitFullScreen() {
        if (document.fullscreenElement) document.exitFullscreen();
        $('body').removeClass('fullscreen-mode');
    }

    function onVideoEnded(e) {
        const v = e.target;
        if (v.loop) {
            v.play();
            return;
        }
        if (playMode === '1') {
            if (currentVideoIndex < videoList.length - 1) playAt(currentVideoIndex + 1);
            else showMessage('error', '已經是最後一部影片');
        }
    }

    function playAt(idx) {
        if (idx < 0 || idx >= videoList.length) {
            showMessage('error', '索引超出範圍');
            return;
        }
        currentVideoIndex = idx;
        const {video, row} = videoList[idx];
        $('html,body').animate({scrollTop: row.offset().top - 100}, 500);
        const isFS = document.fullscreenElement === video;
        if (isFS) {
            video.currentTime = 0;
            video.play();
            video.loop = playMode === '0';
        } else {
            video.currentTime = 0;
            video.play();
            enterFullScreen(video);
        }
    }

    /* --------------------------------------------------
     * DOM Ready
     * -------------------------------------------------- */
    $(function () {
        /* --- 初始顯示文字 --- */
        updatePlayModeLabel();
        updateControlRangeBadges();
        refreshControlRanges();

        /* --- 顯示效能優化（避免影片預載、補 poster、主面人臉自動判斷橫豎） --- */
        applyMediaPerfOptimizations();

        /* --- Range 調整 --- */
        $('#video-size').on('input', e => {
            videoSize = e.target.value;
            updateRangeBadge('#video-size-value', `${videoSize}%`);
            updateRangeProgress(e.target);
            applySizes();
        });
        $('#image-size').on('input', e => {
            imageSize = e.target.value;
            updateRangeBadge('#image-size-value', `${imageSize}px`);
            updateRangeProgress(e.target);
            applySizes();
        });
        $('#play-mode').on('input', e => {
            playMode = e.target.value;
            updatePlayModeLabel();
            updateRangeProgress(e.target);
        });
        $('#video-type').change(() => $('#controls-form').submit());

        /* --- 聚焦影片刪除 --- */
        $('#delete-focused-btn').click(() => {
            const $f = $('.video-row.focused');
            if (!$f.length) {
                showMessage('error', '沒有聚焦的影片');
                return;
            }
            if (!confirm('確定要刪除聚焦的影片嗎？此操作無法撤銷。')) return;
            const restoreMedia = releaseRowMediaSources($f);
            $.post("{{ route('video.deleteSelected') }}", {ids: [$f.data('id')], _token: '{{ csrf_token() }}'}, res => {
                if (res?.success) {
                    const deletedId = $f.data('id');
                    $f.remove();
                    $(`.master-face-img[data-video-id="${deletedId}"]`).remove();
                    masterFacesLoadedCount = $('.master-face-img').length;
                    updateMasterFacesStatus(
                        masterFacesPage < masterFacesLastPage
                            ? `主面人臉已載入 ${masterFacesLoadedCount} 張，背景續載中...`
                            : `主面人臉已載入完成，共 ${masterFacesLoadedCount} 張。`,
                        masterFacesLoadedCount > 0 && masterFacesPage >= masterFacesLastPage
                    );
                    showMessage('success', res.message);
                    rebuildAndSort();
                    const $next = $('.video-row').first();
                    if ($next.length) {
                        const nextId = $next.data('id');
                        $next.addClass('focused');
                        $('#focus-id').val(nextId);
                        focusMasterFace(nextId);
                    } else {
                        $('#focus-id').val('');
                        $('.master-face-img').removeClass('focused');
                    }
                } else {
                    restoreMedia();
                    showMessage('error', res.message);
                }
            }).fail(xhr => {
                restoreMedia();
                const message = xhr?.responseJSON?.message || '刪除失敗，請稍後再試。';
                showMessage('error', message);
            });
        });

        /* --- 影片列點擊 --- */
        $(document).on('click', '.video-row', function () {
            $('.video-row').removeClass('focused');
            $(this).addClass('focused');
            const id = $(this).data('id');

            $('#focus-id').val(id);                 // ★ 新增：送出表單時帶上
            focusMasterFace(id);
            this.scrollIntoView({behavior: 'smooth', block: 'center'});
        });

        /* --- Hover 放大截圖 --- */
        const $modal = $('#image-modal'), $modalImg = $modal.find('img');
        $(document).on('mouseenter', '.hover-zoom', function () {
            $modalImg.attr('src', $(this).attr('src'));
            $modal.addClass('active');
        }).on('mouseleave', '.hover-zoom', function () {
            $modal.removeClass('active');
            $modalImg.attr('src', '');
        });

        /* --- 全螢幕按鈕 --- */
        $(document).on('click', '.fullscreen-btn', function (e) {
            e.stopPropagation();
            enterFullScreen($(this).siblings('video')[0]);
        });

        /* --- 影片結束事件 --- */
        $(document).on('ended', 'video', onVideoEnded);

        /* --- 捲動載入更多 --- */
        $(window).scroll(() => {
            if ($(window).scrollTop() <= 100) loadMoreVideos('up');
            if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100) loadMoreVideos('down');
        });

        /* --- 全螢幕變動 --- */
        document.addEventListener('fullscreenchange', () => {
            const fs = document.fullscreenElement;

            if (fs && $(fs).is('video')) {             // 進入全螢幕
                currentFSVideo = fs;
                fs.addEventListener('ended', onVideoEnded);

                /* ------- 全螢幕一定循環 ------- */
                fs.loop = true;
                fs.setAttribute('loop', '');

            } else if (currentFSVideo) {               // 離開全螢幕
                /* ------- 恢復 playMode (0=循環、1=自動下一部) ------- */
                const shouldLoop = (playMode === '0');
                currentFSVideo.loop = shouldLoop;
                if (shouldLoop) {
                    currentFSVideo.setAttribute('loop', '');
                } else {
                    currentFSVideo.removeAttribute('loop');
                }

                currentFSVideo = null;
            }
        });

        /* --- 滑鼠範圍左右鈕 & 觸控 --- */
        let ctrlTimeout, ctrlVisible = false, prevVisible = false, nextVisible = false;

        function showFSControls() {
            $('#fullscreen-controls').addClass('show');
            ctrlVisible = true;
        }

        function hideFSControls() {
            $('#fullscreen-controls').removeClass('show');
            ctrlVisible = false;
        }

        function onVideoMouseMove(e) {
            const v = e.currentTarget, rect = v.getBoundingClientRect(), x = e.clientX - rect.left, edge = 50;
            if (x < edge) {
                !prevVisible && ($('.prev-video-btn').addClass('show'), prevVisible = true);
            } else {
                prevVisible && ($('.prev-video-btn').removeClass('show'), prevVisible = false);
            }
            if (x > rect.width - edge) {
                !nextVisible && ($('.next-video-btn').addClass('show'), nextVisible = true);
            } else {
                nextVisible && ($('.next-video-btn').removeClass('show'), nextVisible = false);
            }
            if (!ctrlVisible) showFSControls();
            clearTimeout(ctrlTimeout);
            ctrlTimeout = setTimeout(() => {
                hideFSControls();
                $('.prev-video-btn,.next-video-btn').removeClass('show');
                prevVisible = nextVisible = false;
            }, 3000);
        }

        function onTouchStart(e) {
            this._tx = e.changedTouches[0].clientX;
            this._ty = e.changedTouches[0].clientY;
        }

        function onTouchEnd(e) {
            const dx = e.changedTouches[0].clientX - this._tx, dy = e.changedTouches[0].clientY - this._ty;
            if (Math.abs(dx) > Math.abs(dy)) {
                dx > 50 ? playAt(Math.min(videoList.length - 1, currentVideoIndex + 1))
                    : dx < -50 ? playAt(Math.max(0, currentVideoIndex - 1)) : 0;
            } else {
                dy > 50 ? toggleLoop() : dy < -50 ? playRandom() : 0;
            }
        }

        function toggleLoop() {
            if (currentFSVideo) {
                currentFSVideo.loop = !currentFSVideo.loop;
                showMessage('success', currentFSVideo.loop ? '單部循環已開啟' : '單部循環已關閉');
            }
        }

        function playRandom() {
            let r = Math.floor(Math.random() * videoList.length);
            if (r === currentVideoIndex) r = (r + 1) % videoList.length;
            playAt(r);
        }

        $(document).on('mousemove', 'video', function (e) {
            if (document.fullscreenElement === this) onVideoMouseMove(e);
        });
        $(document).on('touchstart', 'video', function (e) {
            if (document.fullscreenElement === this) onTouchStart.call(this, e);
        }, {passive: true});
        $(document).on('touchend', 'video', function (e) {
            if (document.fullscreenElement === this) onTouchEnd.call(this, e);
        }, {passive: true});

        $('#prev-video-btn').click(() => currentVideoIndex > 0 ? playAt(currentVideoIndex - 1) : showMessage('error', '已經是第一部影片'));
        $('#next-video-btn').click(() => currentVideoIndex < videoList.length - 1 ? playAt(currentVideoIndex + 1) : showMessage('error', '已經是最後一部影片'));

        /* --- 拖拉上傳人臉截圖 --- */
        function normalizeFaceUploadFiles(files) {
            return Array.from(files || [])
                .filter(file => file && /^image\//.test(file.type || ''))
                .map((file, index) => {
                    const ext = (file.type || 'image/png').split('/')[1] || 'png';
                    const hasName = file.name && /\.[a-z0-9]+$/i.test(file.name);
                    return hasName ? file : new File([file], `face-upload-${Date.now()}-${index}.${ext}`, {
                        type: file.type || `image/${ext}`
                    });
                });
        }

        function clearFacePastePreview($target) {
            const previewUrl = $target.data('previewUrl');
            if (previewUrl) {
                URL.revokeObjectURL(previewUrl);
            }

            $target.removeData('pendingFaceFile').removeData('previewUrl').removeClass('has-preview');
            $target.find('.face-paste-preview').attr('src', '');
        }

        function setFacePastePreview($target, file) {
            const normalizedFiles = normalizeFaceUploadFiles([file]);
            if (!normalizedFiles.length) {
                showMessage('error', '請貼上圖片檔案。');
                return false;
            }

            clearFacePastePreview($target);

            const previewFile = normalizedFiles[0];
            const previewUrl = URL.createObjectURL(previewFile);

            $target.data('pendingFaceFile', previewFile);
            $target.data('previewUrl', previewUrl);
            $target.addClass('has-preview');
            $target.find('.face-paste-preview').attr('src', previewUrl);

            return true;
        }

        function appendUploadedFaces(vid, faces) {
            const tpl = $('#face-screenshot-template').html();
            const $area = $('.face-upload-area[data-video-id="' + vid + '"]');
            const $pasteTarget = $area.find('.face-paste-target').first();

            faces.forEach(f => {
                const html = tpl.replace('{is_master_class}', f.is_master ? 'master' : '')
                    .replace(/{face_image_path}/g, f.face_image_path)
                    .replace(/{face_id}/g, f.id)
                    .replace(/{video_id}/g, vid);

                if ($pasteTarget.length) {
                    $pasteTarget.before(html);
                } else {
                    $area.prepend(html);
                }
            });

            applySizes();
        }

        function uploadFaceImages(vid, files, options = {}) {
            const normalizedFiles = normalizeFaceUploadFiles(files);
            if (!normalizedFiles.length) {
                showMessage('error', '請貼上或選擇圖片檔案。');
                return;
            }

            const requestKey = String(vid);
            if (pendingFaceUploads.has(requestKey)) {
                showMessage('error', '這部影片的人臉截圖正在上傳，請稍候。');
                return;
            }

            const fd = new FormData();
            normalizedFiles.forEach(file => fd.append('face_images[]', file));
            fd.append('video_id', vid);

            const $area = $('.face-upload-area[data-video-id="' + vid + '"]');
            pendingFaceUploads.add(requestKey);
            $area.addClass('is-uploading');

            $.ajax({
                url: "{{ route('video.uploadFaceScreenshot') }}",
                method: 'POST', data: fd, contentType: false, processData: false,
                headers: {'X-CSRF-TOKEN': '{{ csrf_token() }}'},
                success(res) {
                    if (res && res.success) {
                        appendUploadedFaces(vid, res.data || []);
                        if (typeof options.onSuccess === 'function') {
                            options.onSuccess();
                        }
                        showMessage('success', '人臉截圖上傳成功。');
                    } else {
                        showMessage('error', res.message);
                    }
                },
                error(xhr) {
                    const message = xhr?.responseJSON?.message || '上傳失敗，請稍後再試。';
                    showMessage('error', message);
                },
                complete() {
                    pendingFaceUploads.delete(requestKey);
                    $area.removeClass('is-uploading');
                }
            });
        }

        $(document).on('dragover', '.face-upload-area', function (e) {
            e.preventDefault();
            $(this).addClass('dragover');
        })
            .on('dragleave', '.face-upload-area', function () {
                $(this).removeClass('dragover');
            })
            .on('drop', '.face-upload-area', function (e) {
                e.preventDefault();
                $(this).removeClass('dragover');
                uploadFaceImages($(this).data('video-id'), e.originalEvent.dataTransfer.files);
            });

        /* --- 刪除截圖 --- */
        $(document).on('click', '.face-paste-target', function () {
            $(this).trigger('focus');
        });

        $(document).on('keydown', '.face-paste-target', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const $target = $(this);
                const pendingFile = $target.data('pendingFaceFile');

                if (!pendingFile) {
                    showMessage('error', '請先貼上圖片預覽。');
                    return;
                }

                uploadFaceImages($target.closest('.face-upload-area').data('video-id'), [pendingFile], {
                    onSuccess() {
                        clearFacePastePreview($target);
                    }
                });
                return;
            }

            if (!e.ctrlKey && !e.metaKey && !['Tab', 'Shift', 'Control', 'Meta', 'Alt'].includes(e.key)) {
                e.preventDefault();
            }
        });

        $(document).on('paste', '.face-paste-target', function (e) {
            e.preventDefault();
            const files = Array.from(e.originalEvent.clipboardData?.items || [])
                .filter(item => item.kind === 'file' && /^image\//.test(item.type || ''))
                .map(item => item.getAsFile())
                .filter(Boolean);

            if (!files.length) {
                showMessage('error', '剪貼簿裡沒有可預覽的圖片。');
                return;
            }

            setFacePastePreview($(this), files[0]);
        });

        $(document).on('click', '.delete-icon', function (e) {
            e.stopPropagation();
            const id = $(this).data('id'), type = $(this).data('type');
            $.post("{{ route('video.deleteScreenshot') }}", {id, type, _token: '{{ csrf_token() }}'}, res => {
                if (res && res.success) {
                    $(this).closest(type === 'screenshot' ? '.screenshot-container' : '.face-screenshot-container').remove();
                    applySizes();
                    showMessage('success', '圖片刪除成功。');
                } else showMessage('error', res.message);
            }).fail(() => showMessage('error', '刪除失敗，請稍後再試。'));
        });

        /* --- 設為主面人臉 --- */
        function setMaster(faceId, vid) {
            const requestKey = String(vid);
            if (pendingMasterUpdates.has(requestKey)) {
                return;
            }

            const $area = $('.face-upload-area[data-video-id="' + vid + '"]');
            pendingMasterUpdates.add(requestKey);
            $area.addClass('is-saving-master');

            $.post("{{ route('video.setMasterFace') }}", {face_id: faceId, _token: '{{ csrf_token() }}'}, res => {
                if (res && res.success) {
                    $(`.face-screenshot[data-video-id="${vid}"]`).removeClass('master');
                    $(`.face-screenshot[data-id="${faceId}"]`).addClass('master');
                    updateMasterFace(res.data);
                    showMessage('success', '主面人臉已更新。');
                } else showMessage('error', res.message);
            }).fail(xhr => {
                const message = xhr?.responseJSON?.message || '更新失敗，請稍後再試。';
                showMessage('error', message);
            }).always(() => {
                pendingMasterUpdates.delete(requestKey);
                $area.removeClass('is-saving-master');
            });
        }

        $(document).on('click', '.face-screenshot', function (e) {
            e.stopPropagation();
            const $img = $(this);
            const vid = $img.data('video-id');
            const $row = $img.closest('.video-row');

            $('.video-row').removeClass('focused');
            $row.addClass('focused');
            $('#focus-id').val(vid);
            focusMasterFace(vid);

            if (!$img.hasClass('master')) {
                setMaster($img.data('id'), vid);
            }
        });
        $(document).on('click', '.set-master-btn', function (e) {
            e.stopPropagation();
            setMaster($(this).data('id'), $(this).data('video-id'));
        });

        /* ------------------ 左欄主面人臉 → 聚焦影片 ------------------ */
        $(document).off('click', '.master-face-img');     // 先解除舊綁定，避免重複
        $(document).on('click', '.master-face-img', function () {
            const vid = $(this).data('video-id');

            /* 1. 試著找目前頁是否已有影片 */
            const $row = $('.video-row[data-id="' + vid + '"]');
            if ($row.length) {
                $('.video-row').removeClass('focused');
                $row.addClass('focused');
                focusMasterFace(vid);
                $row[0].scrollIntoView({behavior: 'smooth', block: 'center'});
                return;
            }

            /* 2. 不在目前頁 → 先查頁碼，再載入並聚焦 */
            $.get("{{ route('video.findPage') }}", {
                video_id: vid,
                video_type: videoType,
                missing_only: missingOnly ? 1 : 0,   // ⭐ 加上缺主面篩選
                sort_by: sortBy,                 // ⭐ 加上排序依據
                sort_dir: sortDir                 // ⭐ 加上排序方向
            }, res => {
                if (res?.success && res.page) {
                    loadPageAndFocus(vid, res.page);
                } else {
                    showMessage('error', '找不到該影片所在的頁面。');
                }
            }).fail(() => showMessage('error', '查詢失敗，請稍後再試。'));
        });

        /* --- 監聽排列拖曳 --- */
        $("#videos-list").sortable({
            placeholder: "ui-state-highlight", delay: 150, cancel: "video, .fullscreen-btn, img, button"
        }).disableSelection();

        /* --- 初始建構 --- */
        buildVideoList();
        applySizes();
        loadMasterFacesPage(1, true);

        $('.master-faces').on('scroll', function () {
            if (masterFacesLoading || masterFacesPage >= masterFacesLastPage) {
                return;
            }

            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 180) {
                loadMasterFacesPage(masterFacesPage + 1);
            }
        });

        /* ----------- 主面人臉側欄開關 ----------- */
        const $btnToggle = $('#toggle-master-faces');
        const $sidebar = $('.master-faces');
        const $content = $('.container');
        const $controls = $('.controls');
        const $controlsToggle = $('#toggle-controls');
        let controlsOpen = false;

        function updateToggleState(collapsed) {
            if (collapsed) {
                $sidebar.addClass('collapsed');
                $content.removeClass('expanded');
                $controls.removeClass('expanded');
                updateBtnPos(true);
                $btnToggle.html('☰');
            } else {
                $sidebar.removeClass('collapsed');
                $content.addClass('expanded');
                $controls.addClass('expanded');
                updateBtnPos(false);
                $btnToggle.html('❮');
            }
        }

        // 預設展開（You can set collapsed=true if you want）
        let collapsed = false;
        updateToggleState(collapsed);

        $btnToggle.on('click', () => {
            collapsed = !collapsed;
            updateToggleState(collapsed);
        });

        /* ------- ① 進頁面就把鈕頂到 h5 標題同高 ------- */
        const headerTop = $('.master-faces h5').offset().top;   // 與視窗頂端距離
        $('#toggle-master-faces').css('top', headerTop + 'px');

        /* ------- ② 監聽側欄開關，控制 .inside class ------- */

        // const $btnToggle = $('#toggle-master-faces');
        function updateBtnPos(collapsed) {
            collapsed ? $btnToggle.removeClass('inside')
                : $btnToggle.addClass('inside');
        }

        function updateControlsToggleState(open) {
            controlsOpen = !!open;

            $controls.toggleClass('controls-open', controlsOpen);
            $content.toggleClass('controls-open', controlsOpen);
            $controlsToggle
                .toggleClass('controls-open', controlsOpen)
                .attr('aria-expanded', String(controlsOpen))
                .attr('title', controlsOpen ? '隱藏控制列' : '顯示控制列')
                .attr('aria-label', controlsOpen ? '隱藏控制列' : '顯示控制列');
        }

        updateControlsToggleState(false);

        $controlsToggle.on('click', () => {
            updateControlsToggleState(!controlsOpen);
        });

        scheduleInitialFocusSequence();
        $(window).one('load', function () {
            focusInitial(true);
        });

        /* --------------------------------------------------
         * 確保送出前寫入 focus-id
         * -------------------------------------------------- */
        $('#controls-form').on('submit', function () {
            const fid = $('.video-row.focused').data('id') || '';
            $('#focus-id').val(fid);          // ← 送出表單前最後覆寫
        });
    });

    /* --------------------------------------------------
     * 永遠聚焦最新 id 的那支影片
     * -------------------------------------------------- */
    function focusMaxId() {
        if (latestId === null) return;

        const $target = $('.video-row[data-id="' + latestId + '"]');

        if ($target.length) {
            $('.video-row').removeClass('focused');
            $target.addClass('focused');
            focusMasterFace(latestId);
            $target[0].scrollIntoView({behavior: 'smooth', block: 'center'});
        } else {
            // 這一頁沒有 → 動態查詢它在第幾頁，載進來再聚焦
            $.get("{{ route('video.findPage') }}", {video_id: latestId, video_type: videoType}, res => {
                if (res?.success && res.page) {
                    loadPageAndFocus(latestId, res.page);
                }
            });
        }
    }

    function focusInitial(forceRetry = false) {
        const targetId = initialFocusTargetId;
        if (targetId === null) return;

        focusVideoRowById(targetId, {behavior: forceRetry ? 'auto' : 'smooth'});

        if (!forceRetry) {
            // 用完即丟，避免之後 rebuild 又蓋掉使用者手動選擇
            initialFocusId = null;
        }
    }

    function getFaceSortParts(el) {
        const $el = $(el);
        const videoId = parseInt($el.data('video-id'), 10) || 0;
        const duration = parseFloat($el.data('duration')) || 0;

        return sortBy === 'duration'
            ? {primary: duration, secondary: videoId}
            : {primary: videoId, secondary: videoId};
    }

    function compareFaces(a, b) {
        const left = getFaceSortParts(a);
        const right = getFaceSortParts(b);

        return compareWithTiebreaker(left.primary, left.secondary, right.primary, right.secondary);
    }

    function buildMasterFaceElement(face) {
        const img = document.createElement('img');
        img.src = baseVideoUrl + '/' + normalizeMediaPath(face.face_image_path);
        img.className = 'master-face-img';
        img.alt = '主面人臉';
        img.dataset.videoId = String(face.video_id);
        img.dataset.duration = String(Number(face.video_duration) || 0);
        img.loading = 'lazy';
        img.decoding = 'async';
        img.fetchPriority = 'low';
        img.title = (face.video_name || '影片') + ' #' + face.video_id;

        return img;
    }

    function insertMasterFaceInOrder(el) {
        const $c = $('.master-face-images');
        const items = $c.children('img.master-face-img').get();
        let inserted = false;

        for (let i = 0; i < items.length; i++) {
            if (compareFaces(el, items[i]) < 0) {
                $(items[i]).before(el);
                inserted = true;
                break;
            }
        }
        if (!inserted) {
            $c.append(el);
        }
    }

    function repositionMasterFace(el) {
        const $el = $(el);
        $el.detach();
        insertMasterFaceInOrder(el);
    }

    function appendMasterFaces(faces, reset = false) {
        const $container = $('.master-face-images');
        if (reset) {
            $container.empty();
            masterFacesLoadedCount = 0;
        }

        faces.forEach(face => {
            const videoId = parseInt(face.video_id, 10) || 0;
            if (!videoId) {
                return;
            }

            const $existing = $container.children(`img.master-face-img[data-video-id="${videoId}"]`);
            if ($existing.length) {
                $existing
                    .attr('src', baseVideoUrl + '/' + normalizeMediaPath(face.face_image_path))
                    .attr('data-duration', Number(face.video_duration) || 0)
                    .attr('title', (face.video_name || '影片') + ' #' + face.video_id);
                repositionMasterFace($existing[0]);
                return;
            }

            insertMasterFaceInOrder(buildMasterFaceElement(face));
            masterFacesLoadedCount += 1;
        });

        const focusedId = getCurrentFocusedVideoId();
        if (focusedId) {
            focusMasterFace(focusedId);
        }

        updateMasterFacesStatus(
            masterFacesPage < masterFacesLastPage
                ? `主面人臉已載入 ${masterFacesLoadedCount} 張，背景續載中...`
                : `主面人臉已載入完成，共 ${masterFacesLoadedCount} 張。`,
            masterFacesLoadedCount > 0 && masterFacesPage >= masterFacesLastPage
        );
    }

    function queueMasterFacesPrefetch() {
        if (masterFacesLoading || masterFacesPage >= masterFacesLastPage) {
            return;
        }

        const schedule = window.requestIdleCallback
            ? cb => window.requestIdleCallback(cb, {timeout: 1200})
            : cb => setTimeout(cb, 180);

        schedule(() => {
            if (!masterFacesLoading && masterFacesPage < masterFacesLastPage) {
                loadMasterFacesPage(masterFacesPage + 1);
            }
        });
    }

    function loadMasterFacesPage(page = 1, reset = false) {
        if (masterFacesLoading) {
            return;
        }

        masterFacesLoading = true;
        if (reset) {
            masterFacesPage = 0;
            masterFacesLastPage = 1;
            updateMasterFacesStatus('主面人臉載入中...');
        } else {
            updateMasterFacesStatus(`主面人臉已載入 ${masterFacesLoadedCount} 張，繼續同步中...`);
        }

        $.ajax({
            url: "{{ route('video.loadMasterFaces') }}",
            method: 'GET',
            data: {
                page,
                per_page: 160,
                video_type: videoType,
                sort_by: sortBy,
                sort_dir: sortDir
            },
            success(res) {
                if (res?.success) {
                    masterFacesPage = Number(res.current_page || page) || page;
                    masterFacesLastPage = Number(res.last_page || masterFacesPage) || masterFacesPage;
                    appendMasterFaces(Array.isArray(res.data) ? res.data : [], reset);

                    if (masterFacesPage < masterFacesLastPage) {
                        queueMasterFacesPrefetch();
                    }
                } else {
                    updateMasterFacesStatus(res?.message || '主面人臉載入失敗。');
                }
            },
            error() {
                updateMasterFacesStatus('主面人臉載入失敗，請稍後再試。');
            },
            complete() {
                masterFacesLoading = false;
            }
        });
    }

    function updateMasterFace(face) {
        const videoId = parseInt(face.video_id, 10) || 0;
        if (!videoId) {
            showMessage('error', '主面人臉同步失敗：缺少影片資訊。');
            return;
        }

        appendMasterFaces([face], false);
        updateMasterFacesStatus(
            masterFacesPage < masterFacesLastPage
                ? `主面人臉已載入 ${masterFacesLoadedCount} 張，背景續載中...`
                : `主面人臉已載入完成，共 ${masterFacesLoadedCount} 張。`,
            masterFacesLoadedCount > 0 && masterFacesPage >= masterFacesLastPage
        );
        applySizes();
        applyMediaPerfOptimizations();
    }

    function resortMasterFacesByCurrentSort() {
        const $c = $('.master-face-images');
        const arr = $c.children('img.master-face-img').get();
        arr.sort(compareFaces);
        $c.empty().append(arr);
    }

    function applyMediaPerfOptimizations() {
        // 1) 避免列表中的每支影片預載，否則會把頻寬吃光，連帶拖慢所有圖片載入
        $('#videos-list video').each(function () {
            const $v = $(this);
            if (($v.attr('preload') || '').toLowerCase() !== 'none') {
                $v.attr('preload', 'none');
            }
        });

        // 2) 若列表影片沒有 poster，就用該列第一張「影片截圖」當 poster（只補一次）
        $('#videos-list .video-row').each(function () {
            const $row = $(this);
            const $video = $row.find('video').first();
            if (!$video.length) return;

            const poster = ($video.attr('poster') || '').trim();
            if (poster.length > 0) return;

            const $firstShot = $row.find('img.screenshot').first();
            if (!$firstShot.length) return;

            const shotSrc = ($firstShot.attr('src') || '').trim();
            if (shotSrc.length === 0) return;

            $video.attr('poster', shotSrc);
        });

        // 3) 主面人臉縮圖：統一每張只佔 1 格（清掉可能殘留的 landscape）
        $('.master-face-img').removeClass('landscape');
    }

    /* === 取代原有對 video mousemove 的綁定，加入快轉邏輯 === */
    $(document).off('mousemove', 'video');
    $(document).on('mousemove', 'video', function (e) {
        // 全螢幕時維持原控制條邏輯
        if (document.fullscreenElement === this) {
            onVideoMouseMove(e);
            return;
        }
        // 非全螢幕：左右移動即時快轉
        const rect = this.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const percent = x / rect.width;
        if (percent >= 0 && percent <= 1 && this.duration) {
            this.currentTime = percent * this.duration;
        }
    });
