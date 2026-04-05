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
    let masterFacesPage = 0;
    let masterFacesLastPage = 1;
    let masterFacesLoading = false;
    let masterFacesLoadedCount = 0;
    const pendingFaceUploads = new Set();
    const pendingMasterUpdates = new Set();

    $('#video-type, #sort-by, #sort-dir').on('change', function () {
        setTimeout(() => $('#controls-form').trigger('submit'), 0);
    });

    /* --- ?芷＊蝷箸?訾蜓?Ｗ???--- */
    $('#missing-only')
        .on('input', function () {                // ????＊蝷箸?摮?            missingOnly = $(this).val() === '1';
            updateMissingOnlyLabel();
        })
        .on('change', function () {               // ?暸?皛? ????渡?
            missingOnly = $(this).val() === '1';
            $('#controls-form').submit();
        });
    /* 蝚砌?甈⊿脤??Ｗ停撖思?甈⊥?摮?*/
    updateMissingOnlyLabel();

    /* --------------------------------------------------
     * 敹怨?閮
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

    /* --------------------------------------------------
     * ??頛 / ??
     * -------------------------------------------------- */
    function recalcPages() {
        const min = Math.min.apply(null, loadedPages);
        const max = Math.max.apply(null, loadedPages);
        prevPage = min > 1 ? (min - 1) : null;
        nextPage = max < lastPage ? (max + 1) : null;
    }

    /* --- ?芷＊蝷箸?訾蜓?Ｘ?????--- */
    function updateMissingOnlyLabel() {
        $('#missing-only-label').text(missingOnly ? '?? : '??);
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

    function loadMoreVideos(dir = 'down', target = null) {
        if (loading) return;

        // 瘝?摰?target ???斗?臬??銝???        if (!target) {
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
                    dir === 'down'
                        ? $('#videos-list').append($temp.children())
                        : $('#videos-list').prepend($temp.children());

                    if (!loadedPages.includes(res.current_page))
                        loadedPages.push(res.current_page);

                    lastPage = res.last_page || lastPage;
                    rebuildAndSort();
                } else {
                    if (!target) dir === 'down' ? nextPage = null : prevPage = null;
                    $('#load-more').html('<p>瘝??游?鞈?鈭?/p>');
                }
                loading = false;
                $('#load-more').hide();
            },
            error() {
                showMessage('error', '頛憭望?嚗?蝔??岫??);
                loading = false;
                $('#load-more').hide();
            }
        });
    }

    function loadPageAndFocus(videoId, page) {
        if (!page) {
            showMessage('error', '?曆??啗府敶梁???函????);
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
                    $('#videos-list').append($temp.children());

                    if (!loadedPages.includes(res.current_page))
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
                    showMessage('error', '?⊥?頛閰脤?鞈???);
                }
                loading = false;
                $('#load-more').hide();
            },
            error() {
                showMessage('error', '頛憭望?嚗?蝔??岫??);
                loading = false;
                $('#load-more').hide();
            }
        });
    }

    function rebuildAndSort() {
        const currentId = $('.video-row.focused').data('id') || null;

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
        watchFocusedRow();

        // ??????銵?嚗?湧???嚗椰?港?靘?璅?? sortBy/sortDir ???        resortMasterFacesByCurrentSort();
    }

    /* --------------------------------------------------
     * 敶梁??” / 撠箏站
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
     * 銝駁鈭箄??郊
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
     * ?刻撟??     * -------------------------------------------------- */
    function enterFullScreen(video) {
        /* ------- ?刻撟?銝敺儐??------- */
        video.loop = true;                 // JS 撅祆?        video.setAttribute('loop', '');    // HTML 撅祆改??澆捆??汗??
        try {
            if (video.requestFullscreen) {
                video.requestFullscreen().then(() => {
                    $('body').addClass('fullscreen-mode');
                    video.play();          // ??剜嚗Ⅱ靽?loop ??
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
            else showMessage('error', '撌脩??舀?敺??典蔣??);
        }
    }

    function playAt(idx) {
        if (idx < 0 || idx >= videoList.length) {
            showMessage('error', '蝝Ｗ?頞蝭?');
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
        /* --- ??憿舐內?? --- */
        $('#play-mode-label').text(playMode === '0' ? '敺芰' : '?芸?');

        /* --- 憿舐內??芸?嚗?蔣??頛? poster?蜓?Ｖ犖???瑟帖鞊? --- */
        applyMediaPerfOptimizations();

        /* --- Range 隤踵 --- */
        $('#video-size').on('input', e => {
            videoSize = e.target.value;
            applySizes();
        });
        $('#image-size').on('input', e => {
            imageSize = e.target.value;
            applySizes();
        });
        $('#play-mode').on('input', e => {
            playMode = e.target.value;
            $('#play-mode-label').text(playMode === '0' ? '敺芰' : '?芸?');
        });
        $('#video-type').change(() => $('#controls-form').submit());

        /* --- ?敶梁??芷 --- */
        $('#delete-focused-btn').click(() => {
            const $f = $('.video-row.focused');
            if (!$f.length) {
                showMessage('error', '瘝???蔣??);
                return;
            }
            if (!confirm('蝣箏?閬?方??衣?敶梁???甇斗?雿瘜?瑯?)) return;
            const restoreMedia = releaseRowMediaSources($f);
            $.post("{{ route('video.deleteSelected') }}", {ids: [$f.data('id')], _token: '{{ csrf_token() }}'}, res => {
                if (res?.success) {
                    const deletedId = $f.data('id');
                    $f.remove();
                    $(`.master-face-img[data-video-id="${deletedId}"]`).remove();
                    masterFacesLoadedCount = $('.master-face-img').length;
                    updateMasterFacesStatus(
                        masterFacesPage < masterFacesLastPage
                            ? `銝駁鈭箄?撌脰???${masterFacesLoadedCount} 撘蛛??蝥?銝?..`
                            : `銝駁鈭箄?撌脰??亙?????${masterFacesLoadedCount} 撘萸,
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
                const message = xhr?.responseJSON?.message || '?芷憭望?嚗?蝔??岫??;
                showMessage('error', message);
            });
        });

        /* --- 敶梁?????--- */
        $(document).on('click', '.video-row', function () {
            $('.video-row').removeClass('focused');
            $(this).addClass('focused');
            const id = $(this).data('id');

            $('#focus-id').val(id);                 // ???啣?嚗銵典?葆銝?            focusMasterFace(id);
            this.scrollIntoView({behavior: 'smooth', block: 'center'});
        });

        /* --- Hover ?曉之?芸? --- */
        const $modal = $('#image-modal'), $modalImg = $modal.find('img');
        $(document).on('mouseenter', '.hover-zoom', function () {
            $modalImg.attr('src', $(this).attr('src'));
            $modal.addClass('active');
        }).on('mouseleave', '.hover-zoom', function () {
            $modal.removeClass('active');
            $modalImg.attr('src', '');
        });

        /* --- ?刻撟???--- */
        /* --- 敶梁?蝯?鈭辣 --- */
        $(document).on('ended', 'video', onVideoEnded);

        /* --- ?脣?頛?游? --- */
        $(window).scroll(() => {
            if ($(window).scrollTop() <= 100) loadMoreVideos('up');
            if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100) loadMoreVideos('down');
        });

        /* --- ?刻撟???--- */
        document.addEventListener('fullscreenchange', () => {
            const fs = document.fullscreenElement;

            if (fs && $(fs).is('video')) {             // ?脣?刻撟?                currentFSVideo = fs;
                fs.addEventListener('ended', onVideoEnded);

                /* ------- ?刻撟?摰儐??------- */
                fs.loop = true;
                fs.setAttribute('loop', '');

            } else if (currentFSVideo) {               // ?ａ??刻撟?                /* ------- ?Ｗ儔 playMode (0=敺芰??=?芸?銝??? ------- */
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

        /* --- 皛?蝭?撌血??& 閫豢 --- */
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
                showMessage('success', currentFSVideo.loop ? '?桅敺芰撌脤??? : '?桅敺芰撌脤???);
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

        $('#prev-video-btn').click(() => currentVideoIndex > 0 ? playAt(currentVideoIndex - 1) : showMessage('error', '撌脩??舐洵銝?典蔣??));
        $('#next-video-btn').click(() => currentVideoIndex < videoList.length - 1 ? playAt(currentVideoIndex + 1) : showMessage('error', '撌脩??舀?敺??典蔣??));

        /* --- ??銝鈭箄??芸? --- */
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
                showMessage('error', '隢票銝???獢?);
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

        function buildFaceScreenshotMarkup(vid, face) {
            const facePath = normalizeMediaPath(face.face_image_path);
            const isMasterClass = face.is_master ? ' master' : '';

            return `
                <div class="face-screenshot-container">
                    <img
                        src="${baseVideoUrl}/${facePath}"
                        alt="人臉截圖"
                        class="face-screenshot hover-zoom${isMasterClass}"
                        data-id="${face.id}"
                        data-video-id="${vid}"
                        data-type="face-screenshot"
                        loading="lazy"
                        decoding="async"
                        fetchpriority="low"
                    >
                    <button class="set-master-btn" data-id="${face.id}" data-video-id="${vid}">★</button>
                    <button class="delete-icon" data-id="${face.id}" data-type="face-screenshot">&times;</button>
                </div>
            `.trim();
        }

        function appendUploadedFaces(vid, faces) {
            const $area = $('.face-upload-area[data-video-id="' + vid + '"]');
            const $pasteTarget = $area.find('.face-paste-target').first();

            faces.forEach(face => {
                const html = buildFaceScreenshotMarkup(vid, face);

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
                showMessage('error', '隢票銝??豢???瑼???);
                return;
            }

            const requestKey = String(vid);
            if (pendingFaceUploads.has(requestKey)) {
                showMessage('error', '?敶梁??犖??迤?其??喉?隢???);
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
                        showMessage('success', '鈭箄??芸?銝????);
                    } else {
                        showMessage('error', res.message);
                    }
                },
                error(xhr) {
                    const message = xhr?.responseJSON?.message || '銝憭望?嚗?蝔??岫??;
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

        /* --- ?芷?芸? --- */
        $(document).on('click', '.face-paste-target', function () {
            $(this).trigger('focus');
        });

        $(document).on('keydown', '.face-paste-target', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const $target = $(this);
                const pendingFile = $target.data('pendingFaceFile');

                if (!pendingFile) {
                    showMessage('error', '隢?鞎潔????汗??);
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
                showMessage('error', '?芾票蝪輯ㄐ瘝??舫?閬賜?????);
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
                    showMessage('success', '???芷????);
                } else showMessage('error', res.message);
            }).fail(() => showMessage('error', '?芷憭望?嚗?蝔??岫??));
        });

        /* --- 閮剔銝駁鈭箄? --- */
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
                    showMessage('success', '銝駁鈭箄?撌脫?啜?);
                } else showMessage('error', res.message);
            }).fail(xhr => {
                const message = xhr?.responseJSON?.message || '?湔憭望?嚗?蝔??岫??;
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

        /* ------------------ 撌行?銝駁鈭箄? ???敶梁? ------------------ */
        $(document).off('click', '.master-face-img');     // ?圾?方?蝬?嚗??銴?        $(document).on('click', '.master-face-img', function () {
            const vid = $(this).data('video-id');

            /* 1. 閰西??曄???臬撌脫?敶梁? */
            const $row = $('.video-row[data-id="' + vid + '"]');
            if ($row.length) {
                $('.video-row').removeClass('focused');
                $row.addClass('focused');
                focusMasterFace(vid);
                $row[0].scrollIntoView({behavior: 'smooth', block: 'center'});
                return;
            }

            /* 2. 銝?桀???????Ⅳ嚗?頛銝西???*/
            $.get("{{ route('video.findPage') }}", {
                video_id: vid,
                video_type: videoType,
                missing_only: missingOnly ? 1 : 0,   // 潃???蝻箔蜓?Ｙ祟??                sort_by: sortBy,                 // 潃?????靘?
                sort_dir: sortDir                 // 潃??????孵?
            }, res => {
                if (res?.success && res.page) {
                    loadPageAndFocus(vid, res.page);
                } else {
                    showMessage('error', '?曆??啗府敶梁???函????);
                }
            }).fail(() => showMessage('error', '?亥岷憭望?嚗?蝔??岫??));
        });

        /* --- ????? --- */
        $("#videos-list").sortable({
            placeholder: "ui-state-highlight", delay: 150, cancel: "video, img, button"
        }).disableSelection();

        /* --- ??撱箸? --- */
        buildVideoList();
        applySizes();
        focusInitial();
        loadMasterFacesPage(1, true);

        $('.master-faces').on('scroll', function () {
            if (masterFacesLoading || masterFacesPage >= masterFacesLastPage) {
                return;
            }

            if (this.scrollTop + this.clientHeight >= this.scrollHeight - 180) {
                loadMasterFacesPage(masterFacesPage + 1);
            }
        });

        /* ----------- 銝駁鈭箄??湔??? ----------- */
        const $btnToggle = $('#toggle-master-faces');
        const $sidebar = $('.master-faces');
        const $content = $('.container');
        const $controls = $('.controls');

        function updateToggleState(collapsed) {
            if (collapsed) {
                $sidebar.addClass('collapsed');
                $content.removeClass('expanded');
                $controls.removeClass('expanded');
                updateBtnPos(true);
                $btnToggle.html('??);
            } else {
                $sidebar.removeClass('collapsed');
                $content.addClass('expanded');
                $controls.addClass('expanded');
                updateBtnPos(false);
                $btnToggle.html('??);
            }
        }

        // ?身撅?嚗ou can set collapsed=true if you want嚗?        let collapsed = false;
        updateToggleState(collapsed);

        $btnToggle.on('click', () => {
            collapsed = !collapsed;
            updateToggleState(collapsed);
        });

        /* ------- ???脤??Ｗ停??? h5 璅??? ------- */
        const headerTop = $('.master-faces h5').offset().top;   // ??蝒?蝡航???        $('#toggle-master-faces').css('top', headerTop + 'px');

        /* ------- ?????湔???嚗??.inside class ------- */

        // const $btnToggle = $('#toggle-master-faces');
        function updateBtnPos(collapsed) {
            collapsed ? $btnToggle.removeClass('inside')
                : $btnToggle.addClass('inside');
        }

        /* --------------------------------------------------
         * 蝣箔???神??focus-id
         * -------------------------------------------------- */
        $('#controls-form').on('submit', function () {
            const fid = $('.video-row.focused').data('id') || '';
            $('#focus-id').val(fid);          // ???銵典??敺?撖?        });
    });

    /* --------------------------------------------------
     * ResizeObserver
     * -------------------------------------------------- */
    const ro = new ResizeObserver(entries => {
        entries.forEach(ent => {
            if ($(ent.target).hasClass('focused'))
                ent.target.scrollIntoView({behavior: 'auto', block: 'center'});
        });
    });

    function watchFocusedRow() {
        ro.disconnect();
        const f = document.querySelector('.video-row.focused');
        if (f) ro.observe(f);
    }

    const listRO = new ResizeObserver(() => {
        const f = document.querySelector('.video-row.focused');
        if (!f) return;
        const rect = f.getBoundingClientRect(), vp = window.innerHeight / 2;
        if (Math.abs(rect.top + rect.height / 2 - vp) > 10)
            f.scrollIntoView({behavior: 'auto', block: 'center'});
    });
    listRO.observe(document.getElementById('videos-list'));

    function focusInitial() {
        const targetId = (initialFocusId !== null) ? initialFocusId : latestId;
        if (targetId === null) return;

        const $t = $('.video-row[data-id="' + targetId + '"]');
        if ($t.length) {
            $('.video-row').removeClass('focused');
            $t.addClass('focused');
            focusMasterFace(targetId);
            $t[0].scrollIntoView({behavior: 'smooth', block: 'center'});
        }
        // ?典??喃?嚗??敺?rebuild ???蝙?刻????        initialFocusId = null;                                     // ???啣?
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
        img.alt = '銝駁鈭箄?';
        img.dataset.videoId = String(face.video_id);
        img.dataset.duration = String(Number(face.video_duration) || 0);
        img.loading = 'lazy';
        img.decoding = 'async';
        img.fetchPriority = 'low';
        img.title = (face.video_name || '敶梁?') + ' #' + face.video_id;

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
                    .attr('title', (face.video_name || '敶梁?') + ' #' + face.video_id);
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
                ? `銝駁鈭箄?撌脰???${masterFacesLoadedCount} 撘蛛??蝥?銝?..`
                : `銝駁鈭箄?撌脰??亙?????${masterFacesLoadedCount} 撘萸,
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
            updateMasterFacesStatus('銝駁鈭箄?頛銝?..');
        } else {
            updateMasterFacesStatus(`銝駁鈭箄?撌脰???${masterFacesLoadedCount} 撘蛛?蝜潛??郊銝?..`);
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
                    updateMasterFacesStatus(res?.message || '銝駁鈭箄?頛憭望???);
                }
            },
            error() {
                updateMasterFacesStatus('銝駁鈭箄?頛憭望?嚗?蝔??岫??);
            },
            complete() {
                masterFacesLoading = false;
            }
        });
    }

    function updateMasterFace(face) {
        const videoId = parseInt(face.video_id, 10) || 0;
        if (!videoId) {
            showMessage('error', '銝駁鈭箄??郊憭望?嚗撩撠蔣??閮?);
            return;
        }

        appendMasterFaces([face], false);
        updateMasterFacesStatus(
            masterFacesPage < masterFacesLastPage
                ? `銝駁鈭箄?撌脰???${masterFacesLoadedCount} 撘蛛??蝥?銝?..`
                : `銝駁鈭箄?撌脰??亙?????${masterFacesLoadedCount} 撘萸,
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
        // 1) ?踹??”銝剔?瘥敶梁???嚗???撖砍?????葆????????        $('#videos-list video').each(function () {
            const $v = $(this);
            if (($v.attr('preload') || '').toLowerCase() !== 'none') {
                $v.attr('preload', 'none');
            }
        });

        // 2) ?亙?銵典蔣????poster嚗停?刻府?洵銝撘萸蔣?? poster嚗鋆?甈∴?
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

        // 3) 銝駁鈭箄?蝮桀?嚗絞銝瘥撐?芯? 1 ?潘?皜??航畾???landscape嚗?        $('.master-face-img').removeClass('landscape');
    }

    /* === ?誨??撠?video mousemove ??摰??敹怨??摩 === */
    $(document).off('mousemove', 'video');
    $(document).on('mousemove', 'video', function (e) {
        // ?刻撟?蝬剜???嗆??摩
        if (document.fullscreenElement === this) {
            onVideoMouseMove(e);
            return;
        }
        // ??Ｗ?嚗椰?喟宏??翰頧?        const rect = this.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const percent = x / rect.width;
        if (percent >= 0 && percent <= 1 && this.duration) {
            this.currentTime = percent * this.duration;
        }
    });
</script>
</body>
</html>
