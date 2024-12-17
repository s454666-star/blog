<!-- resources/views/video/index.blade.php -->

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>影片列表</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- 引入 jQuery UI CSS -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <style>
        /* 自訂樣式 */
        .video-row {
            display: flex;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            padding: 10px;
            border-radius: 5px;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.3s, border-color 0.3s;
            position: relative;
        }
        .video-row.selected {
            border-color: #007bff;
            background-color: #e7f1ff;
        }
        .video-row.focused {
            border-color: #28a745;
            background-color: #e6ffe6;
        }
        .video-container {
            width: 70%;
            padding-right: 10px;
        }
        .images-container {
            width: 30%;
            padding-left: 10px;
            overflow-y: hidden;
            overflow-x: hidden; /* 避免水平滾動條 */
        }
        .screenshot, .face-screenshot {
            width: 100px;
            height: 56px;
            object-fit: cover;
            margin: 5px;
            transition: transform 0.3s, border 0.3s, box-shadow 0.3s;
        }
        .face-screenshot.master {
            border: 3px solid #ff0000;
        }
        /* 放大圖片樣式 */
        .image-modal {
            display: none; /* 隱藏 */
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: hidden;
            background-color: rgba(0,0,0,0.8);
            justify-content: center;
            align-items: center;
            pointer-events: none; /* 讓滑鼠事件穿透 */
        }
        .image-modal img {
            max-width: 90%;
            max-height: 90%;
            border: 5px solid #fff;
            border-radius: 5px;
            pointer-events: none; /* 讓滑鼠事件穿透 */
        }
        .image-modal.active {
            display: flex;
        }
        .controls {
            position: fixed;
            bottom: 0;
            left: 30%; /* 調整為30%以避免遮住左側主面人臉 */
            right: 0;
            background: #fff;
            padding: 20px 30px;
            border-top: 1px solid #ddd;
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1);
            z-index: 1000;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }
        .controls .control-group {
            margin-right: 30px;
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        .controls label {
            margin-right: 10px;
            font-weight: bold;
            white-space: nowrap;
        }
        .video-wrapper {
            position: relative;
        }
        .fullscreen-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(255,255,255,0.7);
            border: none;
            padding: 5px;
            cursor: pointer;
        }
        #play-mode {
            width: 50px;
            height: 10px;
        }
        .upload-area {
            border: 2px dashed #007bff;
            border-radius: 5px;
            padding: 30px;
            text-align: center;
            color: #aaa;
            transition: background-color 0.3s, border-color 0.3s;
            margin-bottom: 20px;
        }
        .upload-area.dragover {
            background-color: #f0f8ff;
            border-color: #0056b3;
            color: #0056b3;
        }
        .ui-state-highlight {
            height: 120px;
            border: 2px dashed #ccc;
            background-color: #f9f9f9;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .video-container, .images-container {
                width: 100%;
                padding: 0;
            }
            .screenshot, .face-screenshot {
                width: 100px;
                height: 56px;
            }
            .controls {
                left: 0;
                flex-direction: column;
                align-items: flex-start;
            }
            .controls .control-group {
                margin-right: 0;
                margin-bottom: 10px;
            }
            .master-faces {
                width: 100%;
                height: auto;
                position: relative;
                border-right: none;
                border-bottom: 1px solid #ddd;
            }
            .container {
                margin-left: 0;
            }
            .master-face-images {
                grid-template-columns: repeat(4, 1fr);
            }
            .master-face-img {
                height: auto;
            }
        }
        .master-faces {
            position: fixed;
            top: 0;
            left: 0;
            width: 30%;
            height: 100%;
            overflow-y: auto;
            background-color: #f8f9fa;
            border-right: 1px solid #ddd;
            padding: 10px;
            box-sizing: border-box;
            z-index: 100;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .master-faces h5 {
            text-align: center;
            width: 100%;
        }
        .master-face-images {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            grid-auto-rows: 1fr;
            grid-gap: 10px;
            width: 100%;
        }
        .master-face-img {
            width: 100%;
            height: auto;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            cursor: pointer;
            border: 2px solid transparent;
            border-radius: 5px;
            transition: border-color 0.3s, box-shadow 0.3s, transform 0.3s;
        }
        .master-face-img.landscape {
            grid-column: span 2;
            aspect-ratio: 2 / 1;
        }
        .master-face-img:hover {
            border-color: #007bff;
            transform: scale(1.05);
        }
        .master-face-img.focused {
            border-color: #28a745;
            box-shadow: 0 0 15px rgba(40, 167, 69, 0.7);
            transform: scale(1.1);
        }
        .container {
            margin-left: 30%;
            padding-top: 20px;
            padding-bottom: 80px;
        }
        .message-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 3000;
        }
        .message {
            padding: 10px 20px;
            border-radius: 5px;
            margin-bottom: 10px;
            color: #fff;
            opacity: 0.9;
            animation: fadeOut 1s forwards;
        }
        .message.success {
            background-color: #28a745;
        }
        .message.error {
            background-color: #dc3545;
        }
        @keyframes fadeOut {
            0% { opacity: 0.9; }
            100% { opacity: 0; }
        }
        .delete-icon, .set-master-btn {
            position: absolute;
            top: 5px;
            right: 5px;
            background: rgba(220,53,69,0.8);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            text-align: center;
            line-height: 18px;
            cursor: pointer;
            display: none;
            font-size: 14px;
            padding: 0;
        }
        .set-master-btn {
            right: 30px;
            background: rgba(40, 167, 69, 0.8);
        }
        .screenshot-container, .face-screenshot-container {
            position: relative;
            display: inline-block;
        }
        .screenshot-container:hover .delete-icon,
        .face-screenshot-container:hover .delete-icon,
        .face-screenshot-container:hover .set-master-btn {
            display: block;
        }
        .fullscreen-mode .controls,
        .fullscreen-mode .master-faces,
        .fullscreen-mode .container {
            display: none;
        }
        .fullscreen-controls {
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: 2000;
            display: none;
        }
        .fullscreen-controls.show {
            display: block;
        }
        .fullscreen-controls .prev-video-btn,
        .fullscreen-controls .next-video-btn {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.5);
            border: none;
            color: #fff;
            padding: 20px;
            font-size: 24px;
            border-radius: 50%;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.3s;
        }
        .fullscreen-controls .prev-video-btn {
            left: 20px;
        }
        .fullscreen-controls .next-video-btn {
            right: 20px;
        }
        .fullscreen-controls .prev-video-btn.show,
        .fullscreen-controls .next-video-btn.show {
            opacity: 1;
        }
        .screenshot-images .d-flex,
        .face-screenshot-images .d-flex {
            max-height: 250px;
            overflow-y: auto;
        }
        @media (min-width: 1200px) {
            .container, .container-lg, .container-md, .container-sm, .container-xl {
                max-width: 1750px;
            }
        }
    </style>
</head>
<body>
<<div class="master-faces">
    <h5>主面人臉</h5>
    <div class="master-face-images">
        @foreach($masterFaces as $masterFace)
            @php
                $imagePath = public_path($masterFace->face_image_path);
                $orientation = '';
                if (file_exists($imagePath)) {
                    list($width, $height) = getimagesize($imagePath);
                    if ($width >= $height) {
                        $orientation = 'landscape';
                    }
                }
            @endphp
            <img src="{{ config('app.video_base_url') }}/{{ $masterFace->face_image_path }}" alt="主面人臉" class="master-face-img {{ $orientation }}" data-video-id="{{ $masterFace->videoScreenshot->videoMaster->id }}" data-duration="{{ $masterFace->videoScreenshot->videoMaster->duration }}">
        @endforeach
    </div>
</div>
<div class="container mt-4">
    <div class="message-container" id="message-container"></div>
    <div id="videos-list">
        @include('video.partials.video_rows', ['videos' => $videos])
    </div>
    <div id="load-more" class="text-center my-4" style="display: none;">
        <p>正在載入更多影片...</p>
    </div>
</div>

<div class="fullscreen-controls" id="fullscreen-controls">
    <button class="prev-video-btn" id="prev-video-btn">❮</button>
    <button class="next-video-btn" id="next-video-btn">❯</button>
</div>

<div class="controls d-flex justify-content-between align-items-center">
    <form id="controls-form" class="d-flex flex-wrap w-100">
        <div class="control-group flex-grow-1">
            <label for="video-size">影片大小:</label>
            <input type="range" id="video-size" name="video_size" min="10" max="50" value="{{ request('video_size', 25) }}">
        </div>
        <div class="control-group flex-grow-1">
            <label for="image-size">截圖大小:</label>
            <input type="range" id="image-size" name="image_size" min="100" max="300" value="{{ request('image_size', 200) }}">
        </div>
        <div class="control-group flex-grow-1">
            <label for="video-type">影片類別:</label>
            <select id="video-type" name="video_type" class="form-control">
                <option value="1" {{ request('video_type', '1') == '1' ? 'selected' : '' }}>1</option>
                <option value="2" {{ request('video_type') == '2' ? 'selected' : '' }}>2</option>
                <option value="3" {{ request('video_type') == '3' ? 'selected' : '' }}>3</option>
                <option value="4" {{ request('video_type') == '4' ? 'selected' : '' }}>4</option>
            </select>
        </div>
        <div class="control-group flex-grow-1">
            <label for="play-mode">播放模式:</label>
            <input type="range" id="play-mode" name="play_mode" min="0" max="1" value="{{ request('play_mode', '0') }}" step="1">
            <span id="play-mode-label">{{ request('play_mode', '0') == '0' ? '循環' : '自動' }}</span>
        </div>
        <div class="control-group flex-grow-1">
            <button id="delete-focused-btn" class="btn btn-warning">刪除聚焦的影片</button>
        </div>
    </form>
</div>

<!-- 模板：影片列 -->
<template id="video-row-template">
    <div class="video-row" data-id="{id}" data-duration="{duration}">
        <div class="video-container">
            <div class="video-wrapper">
                <video width="100%" controls>
                    <source src="{{ config('app.video_base_url') }}/{video_path}" type="video/mp4">
                    您的瀏覽器不支援影片播放。
                </video>
            </div>
        </div>
        <div class="images-container">
            <div class="screenshot-images mb-2">
                <h5>影片截圖</h5>
                <div class="d-flex flex-wrap">
                    {screenshot_images}
                </div>
            </div>
            <div class="face-screenshot-images">
                <h5>人臉截圖</h5>
                <div class="d-flex flex-wrap face-upload-area" data-video-id="{video_id}" style="position: relative; border: 2px dashed #007bff; border-radius: 5px; padding: 10px; min-height: 120px;">
                    {face_screenshot_images}
                    <div class="upload-instructions" style="width: 100%; text-align: center; color: #aaa; margin-top: 10px;">
                        拖曳圖片到此處上傳
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<!-- 模板：截圖圖片 -->
<template id="screenshot-template">
    <div class="screenshot-container">
        <img src="{{ config('app.video_base_url') }}/{{ '{screenshot_path}' }}" alt="截圖" class="screenshot hover-zoom" data-id="{{ '{screenshot_id}' }}" data-type="screenshot">
        <button class="delete-icon" data-id="{{ '{screenshot_id}' }}" data-type="screenshot">&times;</button>
    </div>
</template>

<!-- 模板：人臉截圖圖片 -->
<template id="face-screenshot-template">
    <div class="face-screenshot-container">
        <img src="{{ config('app.video_base_url') }}/{{ '{face_image_path}' }}" alt="人臉截圖" class="face-screenshot hover-zoom {master_class}" data-id="{{ '{face_id}' }}" data-video-id="{{ '{video_id}' }}" data-type="face-screenshot">
        <button class="set-master-btn" data-id="{{ '{face_id}' }}" data-video-id="{{ '{video_id}' }}">★</button>
        <button class="delete-icon" data-id="{{ '{face_id}' }}" data-type="face-screenshot">&times;</button>
    </div>
</template>

<!-- 放大圖片容器 -->
<div class="image-modal" id="image-modal">
    <img src="" alt="放大圖片">
</div>

<!-- 載入更多影片的JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<!-- 引入 jQuery UI JS -->
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
<script>
    // 將 Controller 回傳的 lastPage 載入
    let lastPage = {{ $lastPage ?? 1 }};
    // 將目前頁面也加入 loadedPages
    let loadedPages = [{{ $videos->currentPage() }}];

    let nextPage = {{ $next_page ?? 'null' }};
    let prevPage = {{ $prev_page ?? 'null' }};
    let loading = false;
    let videoList = [];
    let currentVideoIndex = 0;
    let playMode = {{ request('play_mode') ? '1' : '0' }};
    let currentFullScreenVideoElement = null;
    let videoSize = {{ request('video_size', 25) }};
    let imageSize = {{ request('image_size', 200) }};
    let videoType = '{{ request('video_type', '1') }}';

    function showMessage(type, text) {
        const messageContainer = $('#message-container');
        const message = $('<div class="message"></div>').addClass(type === 'success' ? 'success' : 'error').text(text);
        messageContainer.append(message);
        setTimeout(() => {
            message.fadeOut(500, () => {
                message.remove();
            });
        }, 1000);
    }

    // 載入更多影片後重新計算 prevPage 與 nextPage
    function recalculatePages() {
        let minPage = Math.min.apply(null, loadedPages);
        let maxPage = Math.max.apply(null, loadedPages);

        prevPage = (minPage > 1) ? (minPage - 1) : null;
        nextPage = (maxPage < lastPage) ? (maxPage + 1) : null;
    }

    function loadMoreVideos(direction = 'down', targetPage = null) {
        if (loading) return;
        if (!targetPage) {
            if (direction === 'down' && !nextPage) return;
            if (direction === 'up' && !prevPage) return;
        }

        loading = true;
        $('#load-more').show();

        let data = { video_type: videoType };
        if (targetPage) {
            data.page = targetPage;
        } else {
            if(direction === 'down') {
                data.page = nextPage;
            } else {
                data.page = prevPage;
            }
        }

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data: data,
            success: function(response) {
                if(response && response.success && response.data.trim() !== '') {
                    let tempContainer = $('<div></div>').html(response.data);
                    $('#videos-list').append(tempContainer.children());

                    // 將當前載入的頁面加入 loadedPages
                    let current_page = response.current_page;
                    if (loadedPages.indexOf(current_page) === -1) {
                        loadedPages.push(current_page);
                    }

                    // 更新 lastPage（若需要）
                    lastPage = response.last_page || lastPage;

                    rebuildAndSortVideoList();

                    loading = false;
                    $('#load-more').hide();
                } else {
                    if(!targetPage) {
                        if(direction === 'down') {
                            nextPage = null;
                        } else {
                            prevPage = null;
                        }
                    }
                    $('#load-more').html('<p>沒有更多資料了。</p>');
                }
            },
            error: function() {
                $('#load-more').hide();
                showMessage('error', '載入失敗，請稍後再試。');
                loading = false;
            }
        });
    }

    function loadPageAndFocus(videoId, page) {
        if(!page) {
            showMessage('error', '找不到該影片所在的頁面。');
            return;
        }
        loading = true;
        $('#load-more').show();

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data: { page: page, video_type: videoType },
            success: function(loadResponse) {
                if(loadResponse && loadResponse.success && loadResponse.data.trim() !== '') {
                    let tempContainer = $('<div></div>').html(loadResponse.data);
                    $('#videos-list').append(tempContainer.children());

                    let current_page = loadResponse.current_page;
                    if (loadedPages.indexOf(current_page) === -1) {
                        loadedPages.push(current_page);
                    }

                    lastPage = loadResponse.last_page || lastPage;

                    rebuildAndSortVideoList();

                    let targetRow = $('.video-row[data-id="' + videoId + '"]');
                    if(targetRow.length) {
                        $('.video-row').removeClass('focused');
                        targetRow.addClass('focused');
                        focusMasterFace(videoId);
                        $('html, body').animate({
                            scrollTop: targetRow.offset().top - 100
                        }, 500);
                    }
                    loading = false;
                    $('#load-more').hide();
                } else {
                    showMessage('error', '無法載入該頁資料。');
                    loading = false;
                    $('#load-more').hide();
                }
            },
            error: function() {
                showMessage('error', '載入失敗，請稍後再試。');
                loading = false;
                $('#load-more').hide();
            }
        });
    }

    function rebuildAndSortVideoList() {
        let videoRows = $('.video-row').get();

        videoRows.sort(function(a, b) {
            let durationA = parseFloat($(a).data('duration'));
            let durationB = parseFloat($(b).data('duration'));
            return durationA - durationB;
        });

        $('#videos-list').empty();
        for (let i = 0; i < videoRows.length; i++) {
            $('#videos-list').append(videoRows[i]);
        }

        buildVideoList();
        applyVideoSize();

        // 每次重建排序後重新計算上下頁
        recalculatePages();
    }

    function buildVideoList() {
        videoList = [];
        $('.video-row').each(function(index) {
            let videoId = $(this).data('id');
            let videoElement = $(this).find('video')[0];
            videoList.push({
                id: videoId,
                videoElement: videoElement,
                videoRow: $(this)
            });
        });
    }

    function applyVideoSize() {
        $('.video-container').css('width', videoSize + '%');
        $('.images-container').css('width', (100 - videoSize) + '%');
        $('.screenshot, .face-screenshot').css({
            'width': imageSize + 'px',
            'height': (imageSize * 0.56) + 'px'
        });
    }

    $(document).ready(function() {
        $('#video-size').on('input', function () {
            videoSize = $(this).val();
            let imagesWidthPercent = 100 - videoSize;
            $('.video-container').css('width', videoSize + '%');
            $('.images-container').css('width', imagesWidthPercent + '%');
        });

        $('#image-size').on('input', function () {
            imageSize = $(this).val();
            $('.screenshot, .face-screenshot').css({
                'width': imageSize + 'px',
                'height': (imageSize * 0.56) + 'px'
            });
        });

        $('#video-type').on('change', function () {
            $('#controls-form').submit();
        });

        $('#play-mode').on('input', function() {
            playMode = $(this).val();
            $('#play-mode-label').text(playMode === '0' ? '循環' : '自動');
        });

        $('#video-size').trigger('input');
        $('#image-size').trigger('input');
        $('#play-mode').trigger('input');

        $(window).scroll(function () {
            // 向上載入
            if ($(window).scrollTop() <= 100) {
                loadMoreVideos('up');
            }
            // 向下載入
            if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100) {
                loadMoreVideos('down');
            }
        });

        $(document).on('click', '.fullscreen-btn', function (e) {
            e.stopPropagation();
            let video = $(this).siblings('video')[0];
            enterFullScreen(video);
        });

        function enterFullScreen(video) {
            try {
                if (video.requestFullscreen) {
                    video.requestFullscreen().then(() => {
                        $('body').addClass('fullscreen-mode');
                    }).catch((err) => {
                        console.error(err);
                    });
                } else if (video.webkitRequestFullscreen) {
                    video.webkitRequestFullscreen();
                    $('body').addClass('fullscreen-mode');
                } else if (video.msRequestFullscreen) {
                    video.msRequestFullscreen();
                    $('body').addClass('fullscreen-mode');
                } else {
                    $('body').addClass('fullscreen-mode');
                }
            } catch (err) {
                console.error(err);
            }
        }

        function exitFullScreen() {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            }
            $('body').removeClass('fullscreen-mode');
        }

        $(document).on('mousemove', 'video', function (e) {
            let video = this;
            let isFullScreen = document.fullscreenElement === video || document.webkitFullscreenElement === video || document.mozFullScreenElement === video || document.msFullscreenElement === video;
            if (!isFullScreen) {
                let rect = video.getBoundingClientRect();
                let x = e.clientX - rect.left;
                let percent = x / rect.width;
                video.currentTime = percent * video.duration;
            }
        });

        let lastSelectedIndex = null;

        $(document).on('click', '.video-row', function (e) {
            let isFocused = $(this).hasClass('focused');

            if (isFocused) {
                $(this).removeClass('focused');
                removeMasterFaceFocus();
            } else {
                $('.video-row').removeClass('focused');
                $(this).addClass('focused');
                let videoId = $(this).data('id');
                focusMasterFace(videoId);
            }

            lastSelectedIndex = $('.video-row').index(this);
        });

        $('#delete-focused-btn').on('click', function () {
            let focusedRow = $('.video-row.focused');
            if (focusedRow.length === 0) {
                showMessage('error', '沒有聚焦的影片。');
                return;
            }

            if (!confirm('確定要刪除聚焦的影片嗎？此操作無法撤銷。')) {
                return;
            }

            let id = focusedRow.data('id');

            $.ajax({
                url: "{{ route('video.deleteSelected') }}",
                method: 'POST',
                data: {
                    ids: [id],
                    _token: '{{ csrf_token() }}'
                },
                success: function (response) {
                    if (response && response.success) {
                        focusedRow.remove();
                        showMessage('success', response.message);
                        removeMasterFaceFocus();
                        loadMasterFaces();
                        rebuildAndSortVideoList();
                    } else {
                        showMessage('error', response.message);
                    }
                },
                error: function () {
                    showMessage('error', '刪除失敗，請稍後再試。');
                }
            });
        });

        const imageModal = $('#image-modal');
        const modalImg = $('#image-modal img');

        $(document).on('mouseenter', '.hover-zoom', function () {
            let src = $(this).attr('src');
            modalImg.attr('src', src);
            imageModal.addClass('active');
        });

        $(document).on('mouseleave', '.hover-zoom', function () {
            imageModal.removeClass('active');
            modalImg.attr('src', '');
        });

        $("#videos-list").sortable({
            placeholder: "ui-state-highlight",
            delay: 150,
            cancel: "video, .fullscreen-btn, img, button"
        });

        $("#videos-list").disableSelection();

        $(document).on('dblclick', '.face-screenshot', function (e) {
            e.stopPropagation();
            let faceId = $(this).data('id');
            let videoId = $(this).data('video-id');

            $.ajax({
                url: "{{ route('video.setMasterFace') }}",
                method: 'POST',
                data: {
                    face_id: faceId,
                    _token: '{{ csrf_token() }}'
                },
                success: function (response) {
                    if (response && response.success) {
                        $('.face-screenshot[data-video-id="' + videoId + '"]').removeClass('master');
                        $('.face-screenshot[data-id="' + faceId + '"]').addClass('master');

                        updateMasterFace(response.data);
                        showMessage('success', '主面人臉已更新。');

                        setTimeout(() => {
                            let newMasterFace = $('.master-face-img[data-video-id="' + videoId + '"]');
                            if (newMasterFace.length) {
                                $('.master-face-img').removeClass('focused');
                                newMasterFace.addClass('focused');
                                $('.master-faces').animate({
                                    scrollTop: newMasterFace.position().top + $('.master-faces').scrollTop() - 30
                                }, 500);
                            }
                        }, 100);
                    } else {
                        showMessage('error', response.message);
                    }
                },
                error: function () {
                    showMessage('error', '更新失敗，請稍後再試。');
                }
            });
        });

        $(document).on('click', '.master-face-img', function () {
            let videoId = $(this).data('video-id');
            let targetRow = $('.video-row[data-id="' + videoId + '"]');
            if (targetRow.length) {
                $('.video-row').removeClass('focused');
                targetRow.addClass('focused');
                focusMasterFace(videoId);
                $('html, body').animate({
                    scrollTop: targetRow.offset().top - 100
                }, 500);
            } else {
                // 找不到該影片在目前列表中，透過 findPage 取得頁數再載入
                $.ajax({
                    url: "{{ route('video.findPage') }}",
                    method: 'GET',
                    data: { video_id: videoId, video_type: videoType },
                    success: function(response) {
                        if(response && response.success && response.page) {
                            loadPageAndFocus(videoId, response.page);
                        } else {
                            showMessage('error', '找不到該影片所在的頁面。');
                        }
                    },
                    error: function() {
                        showMessage('error', '查詢失敗，請稍後再試。');
                    }
                });
            }
        });

        function loadMasterFaces() {
            $.ajax({
                url: "{{ route('video.loadMasterFaces') }}",
                method: 'GET',
                data: { video_type: videoType },
                cache: false,
                success: function (response) {
                    if (response && response.success) {
                        let masterFacesHtml = '<h5>主面人臉</h5><div class="master-face-images">';
                        response.data.sort((a, b) => a.video_screenshot.video_master.duration - b.video_screenshot.video_master.duration).forEach(function (face) {
                            let orientation = '';
                            if (face.width && face.height && parseInt(face.width) >= parseInt(face.height)) {
                                orientation = 'landscape';
                            }
                            masterFacesHtml += '<img src="{{ config('app.video_base_url') }}/' + face.face_image_path + '" alt="主面人臉" class="master-face-img ' + orientation + '" data-video-id="' + face.video_screenshot.video_master.id + '" data-duration="' + face.video_screenshot.video_master.duration + '">';
                        });
                        masterFacesHtml += '</div>';
                        $('.master-faces').html(masterFacesHtml);
                        applyVideoSize();
                    }
                },
                error: function () {
                    showMessage('error', '無法加載主面人臉。');
                }
            });
        }

        function updateMasterFace(face) {
            let orientation = '';
            if (face.width && face.height && parseInt(face.width) >= parseInt(face.height)) {
                orientation = 'landscape';
            }
            let videoId = face.video_screenshot.video_master.id;
            let masterFaceImg = $('.master-face-img[data-video-id="' + videoId + '"]');
            if (masterFaceImg.length) {
                masterFaceImg.attr('src', '{{ config("app.video_base_url") }}/' + face.face_image_path);
                masterFaceImg.removeClass('landscape').addClass(orientation);
            } else {
                let newMasterFaceHtml = '<img src="{{ config('app.video_base_url') }}/' + face.face_image_path + '" alt="主面人臉" class="master-face-img ' + orientation + '" data-video-id="' + videoId + '" data-duration="' + face.video_screenshot.video_master.duration + '">';
                let inserted = false;
                $('.master-face-images img').each(function () {
                    let currentDuration = parseFloat($(this).data('duration'));
                    if (face.video_screenshot.video_master.duration < currentDuration) {
                        $(this).before(newMasterFaceHtml);
                        inserted = true;
                        return false;
                    }
                });
                if (!inserted) {
                    $('.master-face-images').append(newMasterFaceHtml);
                }
            }
            applyVideoSize();
        }

        function focusMasterFace(videoId) {
            $('.master-face-img').removeClass('focused');
            let targetFace = $('.master-face-img[data-video-id="' + videoId + '"]');
            if (targetFace.length) {
                targetFace.addClass('focused');
                $('.master-faces').animate({
                    scrollTop: targetFace.position().top + $('.master-faces').scrollTop() - 30
                }, 500);
            }
        }

        function removeMasterFaceFocus() {
            $('.master-face-img').removeClass('focused');
        }

        function focusMaxIdVideo() {
            let maxId = -Infinity;
            let maxIdElement = null;
            $('.video-row').each(function () {
                let currentId = parseInt($(this).data('id'));
                if (currentId > maxId) {
                    maxId = currentId;
                    maxIdElement = $(this);
                }
            });
            if (maxIdElement) {
                $('.video-row').removeClass('focused');
                maxIdElement.addClass('focused');
                let videoId = maxIdElement.data('id');
                focusMasterFace(videoId);
                $('html, body').animate({
                    scrollTop: maxIdElement.offset().top - 100
                }, 500);
            }
        }

        $(window).on('load', function () {
            focusMaxIdVideo();
            buildVideoList();
            applyVideoSize();
        });

        $(document).on('click', '.delete-icon', function (e) {
            e.stopPropagation();
            let id = $(this).data('id');
            let type = $(this).data('type');

            $.ajax({
                url: "{{ route('video.deleteScreenshot') }}",
                method: 'POST',
                data: {
                    id: id,
                    type: type,
                    _token: '{{ csrf_token() }}'
                },
                success: function (response) {
                    if (response && response.success) {
                        if (type === 'screenshot') {
                            $('img[data-id="' + id + '"][data-type="screenshot"]').closest('.screenshot-container').remove();
                        } else if (type === 'face-screenshot') {
                            $('img[data-id="' + id + '"][data-type="face-screenshot"]').closest('.face-screenshot-container').remove();
                            loadMasterFaces();
                        }
                        showMessage('success', '圖片刪除成功。');
                        applyVideoSize();
                    } else {
                        showMessage('error', response.message);
                    }
                },
                error: function () {
                    showMessage('error', '刪除失敗，請稍後再試。');
                }
            });
        });

        $(document).on('click', '.set-master-btn', function (e) {
            e.stopPropagation();
            let faceId = $(this).data('id');
            let videoId = $(this).data('video-id');

            $.ajax({
                url: "{{ route('video.setMasterFace') }}",
                method: 'POST',
                data: {
                    face_id: faceId,
                    _token: '{{ csrf_token() }}'
                },
                success: function (response) {
                    if (response && response.success) {
                        $('.face-screenshot[data-video-id="' + videoId + '"]').removeClass('master');
                        $('.face-screenshot[data-id="' + faceId + '"]').addClass('master');

                        updateMasterFace(response.data);
                        showMessage('success', '主面人臉已更新。');

                        setTimeout(() => {
                            let newMasterFace = $('.master-face-img[data-video-id="' + videoId + '"]');
                            if (newMasterFace.length) {
                                $('.master-face-img').removeClass('focused');
                                newMasterFace.addClass('focused');
                                $('.master-faces').animate({
                                    scrollTop: newMasterFace.position().top + $('.master-faces').scrollTop() - 30
                                }, 500);
                            }
                        }, 100);
                    } else {
                        showMessage('error', response.message);
                    }
                },
                error: function () {
                    showMessage('error', '更新失敗，請稍後再試。');
                }
            });
        });

        $(document).on('dragover', '.face-upload-area', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        });

        $(document).on('dragleave', '.face-upload-area', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        });

        $(document).on('drop', '.face-upload-area', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');

            let files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                let videoId = $(this).data('video-id');
                let formData = new FormData();
                for (let i = 0; i < files.length; i++) {
                    formData.append('face_images[]', files[i]);
                }
                formData.append('video_id', videoId);

                $.ajax({
                    url: "{{ route('video.uploadFaceScreenshot') }}",
                    method: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    success: function (response) {
                        if (response && response.success) {
                            let template = $('#face-screenshot-template').html();
                            response.data.forEach(function (face) {
                                let masterClass = face.is_master ? 'master' : '';
                                let newFace = template
                                    .replace('{{ config("app.video_base_url") }}', '{{ config("app.video_base_url") }}')
                                    .replace('{{ "{face_image_path}" }}', face.face_image_path)
                                    .replace('{master_class}', masterClass)
                                    .replace(/{face_id}/g, face.id)
                                    .replace('{video_id}', videoId);
                                $('.face-upload-area[data-video-id="' + videoId + '"]').prepend(newFace);
                            });
                            showMessage('success', '人臉截圖上傳成功！');
                            applyVideoSize();
                        } else {
                            showMessage('error', response.message);
                        }
                    },
                    error: function () {
                        showMessage('error', '上傳失敗，請稍後再試。');
                    }
                });
            }
        });

        $('#controls-form').on('submit', function (e) {
            e.preventDefault();
            let videoSizeVal = $('#video-size').val();
            let imageSizeVal = $('#image-size').val();
            let videoTypeVal = $('#video-type').val();
            let playModeValue = $('#play-mode').val();
            window.location.href = "{{ route('video.index') }}" + "?video_size=" + videoSizeVal + "&image_size=" + imageSizeVal + "&video_type=" + videoTypeVal + "&play_mode=" + playModeValue;
        });

        function onVideoEnded(e) {
            let videoElement = e.target;
            if (videoElement.loop) {
                videoElement.play();
            } else if (playMode === '1') {
                if (currentVideoIndex < videoList.length - 1) {
                    playVideoAtIndex(currentVideoIndex + 1);
                } else {
                    showMessage('info', '已經是最後一部影片');
                }
            }
        }

        document.addEventListener('fullscreenchange', onFullScreenChange);
        document.addEventListener('webkitfullscreenchange', onFullScreenChange);
        document.addEventListener('mozfullscreenchange', onFullScreenChange);
        document.addEventListener('msfullscreenchange', onFullScreenChange);

        $('#prev-video-btn').on('click', function() {
            playPreviousVideo();
        });

        $('#next-video-btn').on('click', function() {
            playNextVideo();
        });

        let controlsTimeout;
        let controlsVisible = false;
        let prevButtonVisible = false;
        let nextButtonVisible = false;

        function showFullscreenControls() {
            $('#fullscreen-controls').addClass('show');
            controlsVisible = true;
        }

        function hideFullscreenControls() {
            $('#fullscreen-controls').removeClass('show');
            controlsVisible = false;
        }

        function onVideoMouseMove(e) {
            let video = e.currentTarget;
            let rect = video.getBoundingClientRect();
            let x = e.clientX - rect.left;
            let y = e.clientY - rect.top;

            let edgeThreshold = 50;

            if (x < edgeThreshold) {
                if (!prevButtonVisible) {
                    $('.prev-video-btn').addClass('show');
                    prevButtonVisible = true;
                }
            } else {
                if (prevButtonVisible) {
                    $('.prev-video-btn').removeClass('show');
                    prevButtonVisible = false;
                }
            }

            if (x > rect.width - edgeThreshold) {
                if (!nextButtonVisible) {
                    $('.next-video-btn').addClass('show');
                    nextButtonVisible = true;
                }
            } else {
                if (nextButtonVisible) {
                    $('.next-video-btn').removeClass('show');
                    nextButtonVisible = false;
                }
            }

            if (!controlsVisible) {
                showFullscreenControls();
            }

            clearTimeout(controlsTimeout);
            controlsTimeout = setTimeout(function() {
                hideFullscreenControls();
                $('.prev-video-btn').removeClass('show');
                $('.next-video-btn').removeClass('show');
                prevButtonVisible = false;
                nextButtonVisible = false;
            }, 3000);
        }

        let touchStartX = null;
        let touchStartY = null;

        function onTouchStart(e) {
            touchStartX = e.changedTouches[0].clientX;
            touchStartY = e.changedTouches[0].clientY;
        }

        function onTouchEnd(e) {
            let touchEndX = e.changedTouches[0].clientX;
            let touchEndY = e.changedTouches[0].clientY;

            let deltaX = touchEndX - touchStartX;
            let deltaY = touchEndY - touchStartY;

            if (Math.abs(deltaX) > Math.abs(deltaY)) {
                if (deltaX > 50) {
                    playNextVideo();
                } else if (deltaX < -50) {
                    playPreviousVideo();
                }
            } else {
                if (deltaY > 50) {
                    toggleLoopPlay();
                } else if (deltaY < -50) {
                    playRandomVideo();
                }
            }
        }

        function playPreviousVideo() {
            if (currentVideoIndex > 0) {
                playVideoAtIndex(currentVideoIndex - 1);
            } else {
                showMessage('error', '已經是第一部影片');
            }
        }

        function playNextVideo() {
            if (currentVideoIndex < videoList.length - 1) {
                playVideoAtIndex(currentVideoIndex + 1);
            } else {
                showMessage('error', '已經是最後一部影片');
            }
        }

        function playRandomVideo() {
            let randomIndex = Math.floor(Math.random() * videoList.length);
            if (randomIndex === currentVideoIndex) {
                randomIndex = (randomIndex + 1) % videoList.length;
            }
            playVideoAtIndex(randomIndex);
        }

        function toggleLoopPlay() {
            if (currentFullScreenVideoElement) {
                currentFullScreenVideoElement.loop = !currentFullScreenVideoElement.loop;
                if (currentFullScreenVideoElement.loop) {
                    showMessage('success', '單部循環已開啟');
                } else {
                    showMessage('success', '單部循環已關閉');
                }
            }
        }

        function playVideoAtIndex(index) {
            if (index < 0 || index >= videoList.length) {
                showMessage('error', '影片索引超出範圍');
                return;
            }

            let nextVideoData = videoList[index];
            currentVideoIndex = index;

            $('html, body').animate({
                scrollTop: nextVideoData.videoRow.offset().top - 100
            }, 500);

            let isFullScreen = document.fullscreenElement === nextVideoData.videoElement || document.webkitFullscreenElement === nextVideoData.videoElement || document.mozFullScreenElement === nextVideoData.videoElement || document.msFullscreenElement === nextVideoData.videoElement;

            if (isFullScreen) {
                let videoElement = currentFullScreenVideoElement;

                if (videoElement) {
                    let sourceElement = videoElement.querySelector('source');
                    if (sourceElement) {
                        sourceElement.src = nextVideoData.videoElement.querySelector('source').src;
                    } else {
                        videoElement.src = nextVideoData.videoElement.src;
                    }

                    videoElement.load();

                    videoElement.currentTime = 0;
                    videoElement.play();

                    videoElement.loop = playMode === '0' ? true : false;

                    videoElement.removeEventListener('ended', onVideoEnded);
                    videoElement.addEventListener('ended', onVideoEnded);
                }

            } else {
                nextVideoData.videoElement.currentTime = 0;
                nextVideoData.videoElement.play();
                enterFullScreen(nextVideoData.videoElement);
            }
        }

        function onFullScreenChange(e) {
            let fsElement = document.fullscreenElement || document.webkitFullscreenElement || document.mozfullscreenElement || document.msfullscreenElement;
            if (fsElement && $(fsElement).is('video')) {
                currentFullScreenVideoElement = fsElement;
                $(fsElement).addClass('is-fullscreen');

                for (let i = 0; i < videoList.length; i++) {
                    if (videoList[i].videoElement === fsElement) {
                        currentVideoIndex = i;
                        break;
                    }
                }

                fsElement.addEventListener('ended', onVideoEnded);
                fsElement.loop = playMode === '0' ? true : false;
                fsElement.addEventListener('mousemove', onVideoMouseMove);
                fsElement.addEventListener('touchstart', onTouchStart, {passive: true});
                fsElement.addEventListener('touchend', onTouchEnd, {passive: true});
                showFullscreenControls();

            } else {
                $('video.is-fullscreen').removeClass('is-fullscreen');
                $('body').removeClass('fullscreen-mode');

                if (currentFullScreenVideoElement) {
                    currentFullScreenVideoElement.removeEventListener('ended', onVideoEnded);
                    currentFullScreenVideoElement.removeEventListener('mousemove', onVideoMouseMove);
                    currentFullScreenVideoElement.removeEventListener('touchstart', onTouchStart);
                    currentFullScreenVideoElement.removeEventListener('touchend', onTouchEnd);
                    currentFullScreenVideoElement.loop = false;
                    currentFullScreenVideoElement = null;
                }

                hideFullscreenControls();
            }
        }

        buildVideoList();
    });
</script>
</body>
</html>
