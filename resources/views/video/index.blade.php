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
        /* 調整播放模式開關樣式 */
        #play-mode {
            width: 50px;
            height: 10px;
        }
        /* 拖曳上傳區域 */
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
        /* 新增 jQuery UI sortable 的 placeholder 樣式 */
        .ui-state-highlight {
            height: 120px; /* 根據 .video-row 的高度調整 */
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
                width: 100%; /* 全寬以適應小螢幕 */
                height: auto;
                position: relative;
                border-right: none;
                border-bottom: 1px solid #ddd;
            }
            .container {
                margin-left: 0; /* 移除左邊距 */
            }
            .master-face-images {
                grid-template-columns: repeat(4, 1fr);
            }
            .master-face-img {
                height: auto;
            }
        }
        /* 左側主面板樣式 */
        .master-faces {
            position: fixed;
            top: 0;
            left: 0;
            width: 30%; /* 調整寬度為30% */
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
            aspect-ratio: 1 / 1; /* 確保縱橫比 */
            object-fit: cover;
            cursor: pointer;
            border: 2px solid transparent;
            border-radius: 5px;
            transition: border-color 0.3s, box-shadow 0.3s, transform 0.3s;
        }
        .master-face-img.landscape {
            grid-column: span 2;
            aspect-ratio: 2 / 1; /* 橫向圖片的縱橫比 */
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
            margin-left: 30%; /* 調整主面板左邊距為30% */
            padding-top: 20px;
            padding-bottom: 80px;
        }

        /* 消息提示樣式 */
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
        /* 新增刪除圖示及設定主面圖示 */
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
        /* 全螢幕模式時，隱藏頁面元素 */
        .fullscreen-mode .controls,
        .fullscreen-mode .master-faces,
        .fullscreen-mode .container {
            display: none;
        }
        /* 全螢幕控制按鈕 */
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
    </style>
</head>
<body>
<div class="master-faces">
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
    <!-- 消息提示 -->
    <div class="message-container" id="message-container">
    </div>

    <!-- 影片列表 -->
    <div id="videos-list">
        @include('video.partials.video_rows', ['videos' => $videos])
    </div>

    <!-- 載入更多提示 -->
    <div id="load-more" class="text-center my-4" style="display: none;">
        <p>正在載入更多影片...</p>
    </div>
</div>

<!-- 全螢幕控制按鈕 -->
<div class="fullscreen-controls" id="fullscreen-controls">
    <button class="prev-video-btn" id="prev-video-btn">❮</button>
    <button class="next-video-btn" id="next-video-btn">❯</button>
</div>

<!-- 控制條 -->
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
    <div class="video-row" data-id="{id}">
        <div class="video-container">
            <div class="video-wrapper">
                <video width="100%" controls>
                    <source src="{{ config('app.video_base_url') }}/{video_path}" type="video/mp4">
                    您的瀏覽器不支援影片播放。
                </video>
                <button class="fullscreen-btn">全螢幕</button>
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
    let nextPage = {{ $videos->currentPage() + 1 }};
    let prevPage = {{ $videos->currentPage() - 1 }};
    let loading = false;
    let videoList = [];
    let currentVideoIndex = 0;
    let playMode = {{ request('play_mode') ? '1' : '0' }}; // 0: 循環, 1: 自動
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

    function loadMoreVideos(direction = 'down') {
        if (loading || (direction === 'down' && !nextPage) || (direction === 'up' && !prevPage)) return;
        loading = true;
        if(direction === 'down') {
            $('#load-more').show();
        } else {
            $('#load-more').show();
        }

        let data = { video_type: videoType };
        if(direction === 'down') {
            data.page = nextPage;
        } else {
            data.page = prevPage;
        }

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data: data,
            success: function(response) {
                if(response && response.success && response.data.trim() !== '') {
                    if(direction === 'down') {
                        $('#videos-list').append(response.data);
                        nextPage = response.next_page;
                    } else {
                        $('#videos-list').prepend(response.data);
                        prevPage = response.prev_page;
                    }
                    loading = false;
                    $('#load-more').hide();
                    // Refresh sortable to include new items
                    $("#videos-list").sortable("refresh");
                    buildVideoList();
                    applyVideoSize();
                } else {
                    if(direction === 'down') {
                        nextPage = null;
                    } else {
                        prevPage = null;
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
            'height': (imageSize * 0.56) + 'px' // 保持16:9比例
        });
    }

    $(document).ready(function() {
        // 控制條調整
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
                'height': (imageSize * 0.56) + 'px' // 保持16:9比例
            });
        });

        $('#video-type').on('change', function () {
            $('#controls-form').submit();
        });

        // 播放模式切換
        $('#play-mode').on('input', function() {
            playMode = $(this).val();
            $('#play-mode-label').text(playMode === '0' ? '循環' : '自動');
        });

        // 初始化控制條狀態
        $('#video-size').trigger('input');
        $('#image-size').trigger('input');
        $('#play-mode').trigger('input');

        // 滾動自動載入
        $(window).scroll(function () {
            if ($(window).scrollTop() <= 100) { // 滾動到頂部
                loadMoreVideos('up');
            }
            if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100) { // 滾動到底部
                loadMoreVideos('down');
            }
        });

        // 全螢幕按鈕
        $(document).on('click', '.fullscreen-btn', function (e) {
            e.stopPropagation(); // 防止觸發選取
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
                } else if (video.webkitRequestFullscreen) { /* Safari */
                    video.webkitRequestFullscreen();
                    $('body').addClass('fullscreen-mode');
                } else if (video.msRequestFullscreen) { /* IE11 */
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

        // 滑鼠移動控制進度條（非全螢幕時）
        $(document).on('mousemove', 'video', function (e) {
            let video = this;
            let isFullScreen = document.fullscreenElement === video || document.webkitFullscreenElement === video || document.mozFullScreenElement === video || document.msFullscreenElement === video;
            if (!isFullScreen) {
                let rect = video.getBoundingClientRect();
                let x = e.clientX - rect.left; // 滑鼠在影片上的X位置
                let percent = x / rect.width;
                video.currentTime = percent * video.duration;
            }
        });

        // 選取影片 - 點擊選取或聚焦
        let lastSelectedIndex = null;

        // 點擊影片行
        $(document).on('click', '.video-row', function (e) {
            let isFocused = $(this).hasClass('focused');

            if (isFocused) {
                // 如果已聚焦，則取消聚焦
                $(this).removeClass('focused');
                removeMasterFaceFocus();
            } else {
                // 如果未聚焦，移除其他聚焦並設為聚焦
                $('.video-row').removeClass('focused');
                $(this).addClass('focused');

                // 聚焦對應的主面人臉
                let videoId = $(this).data('id');
                focusMasterFace(videoId);
            }

            // 更新最後選取的索引
            lastSelectedIndex = $('.video-row').index(this);
        });

        // 刪除聚焦的影片
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
                        // Reload master faces after deletion
                        loadMasterFaces();
                        buildVideoList();
                    } else {
                        showMessage('error', response.message);
                    }
                },
                error: function () {
                    showMessage('error', '刪除失敗，請稍後再試。');
                }
            });
        });

        // 放大圖片功能
        const imageModal = $('#image-modal');
        const modalImg = $('#image-modal img');

        // 當滑鼠移入圖片時顯示放大圖
        $(document).on('mouseenter', '.hover-zoom', function () {
            let src = $(this).attr('src');
            modalImg.attr('src', src);
            imageModal.addClass('active');
        });

        // 當滑鼠移出圖片時隱藏放大圖
        $(document).on('mouseleave', '.hover-zoom', function () {
            imageModal.removeClass('active');
            modalImg.attr('src', '');
        });

        // 初始化 Sortable 功能
        $("#videos-list").sortable({
            placeholder: "ui-state-highlight",
            delay: 150,
            cancel: "video, .fullscreen-btn, img, button"
        });

        // 禁用選取文字以避免拖曳時的選取問題
        $("#videos-list").disableSelection();

        // 雙擊設定主面人臉
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
                        // 移除所有master類別
                        $('.face-screenshot[data-video-id="' + videoId + '"]').removeClass('master');
                        // 添加master類別到當前圖片
                        $('.face-screenshot[data-id="' + faceId + '"]').addClass('master');

                        // 更新左側主面人臉
                        updateMasterFace(response.data);

                        showMessage('success', '主面人臉已更新。');

                        // 移動到剛剛設定的主面人臉位置
                        setTimeout(() => {
                            let newMasterFace = $('.master-face-img[data-video-id="' + videoId + '"]');
                            if (newMasterFace.length) {
                                $('.master-face-img').removeClass('focused');
                                newMasterFace.addClass('focused');
                                // 滾動到該主面人臉
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

        // 點擊左側主面人臉導航
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
                // 找出該影片位於第幾頁
                $.ajax({
                    url: "{{ route('video.findPage') }}",
                    method: 'GET',
                    data: { video_id: videoId, video_type: videoType },
                    success: function(response) {
                        if(response && response.success && response.page) {
                            // 載入該頁資料
                            $.ajax({
                                url: "{{ route('video.loadMore') }}",
                                method: 'GET',
                                data: { page: response.page, video_type: videoType },
                                success: function(loadResponse) {
                                    if(loadResponse && loadResponse.success && loadResponse.data.trim() !== '') {
                                        $('#videos-list').prepend(loadResponse.data);
                                        prevPage = loadResponse.prev_page;
                                        buildVideoList();
                                        applyVideoSize();
                                        // 聚焦該影片
                                        let targetRow = $('.video-row[data-id="' + videoId + '"]');
                                        if(targetRow.length) {
                                            $('.video-row').removeClass('focused');
                                            targetRow.addClass('focused');
                                            focusMasterFace(videoId);
                                            $('html, body').animate({
                                                scrollTop: targetRow.offset().top - 100
                                            }, 500);
                                        }
                                    } else {
                                        showMessage('error', '無法載入該頁資料。');
                                    }
                                },
                                error: function() {
                                    showMessage('error', '載入失敗，請稍後再試。');
                                }
                            });
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

        // 加載左側主面人臉
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
                        applyVideoSize(); // 確保新載入的圖片大小正確
                    }
                },
                error: function () {
                    showMessage('error', '無法加載主面人臉。');
                }
            });
        }

        // 更新左側主面人臉
        function updateMasterFace(face) {
            let orientation = '';
            if (face.width && face.height && parseInt(face.width) >= parseInt(face.height)) {
                orientation = 'landscape';
            }
            let videoId = face.video_screenshot.video_master.id;
            let masterFaceImg = $('.master-face-img[data-video-id="' + videoId + '"]');
            if (masterFaceImg.length) {
                // 更新現有的主面人臉
                masterFaceImg.attr('src', '{{ config('app.video_base_url') }}/' + face.face_image_path);
                masterFaceImg.removeClass('landscape').addClass(orientation);
            } else {
                // 新增主面人臉
                let newMasterFaceHtml = '<img src="{{ config('app.video_base_url') }}/' + face.face_image_path + '" alt="主面人臉" class="master-face-img ' + orientation + '" data-video-id="' + videoId + '" data-duration="' + face.video_screenshot.video_master.duration + '">';
                // 插入到正確的位置（根據duration排序）
                let inserted = false;
                $('.master-face-images img').each(function () {
                    let currentDuration = parseFloat($(this).data('duration'));
                    if (face.video_screenshot.video_master.duration < currentDuration) {
                        $(this).before(newMasterFaceHtml);
                        inserted = true;
                        return false; // break loop
                    }
                });
                if (!inserted) {
                    $('.master-face-images').append(newMasterFaceHtml);
                }
            }
            applyVideoSize(); // 確保新載入的圖片大小正確
        }

        // 聚焦對應的主面人臉
        function focusMasterFace(videoId) {
            // 移除其他聚焦
            $('.master-face-img').removeClass('focused');

            // 找到對應的主面人臉
            let targetFace = $('.master-face-img[data-video-id="' + videoId + '"]');
            if (targetFace.length) {
                targetFace.addClass('focused');
                // 滾動到該主面人臉
                $('.master-faces').animate({
                    scrollTop: targetFace.position().top + $('.master-faces').scrollTop() - 30
                }, 500);
            }
        }

        // 移除主面人臉的聚焦
        function removeMasterFaceFocus() {
            $('.master-face-img').removeClass('focused');
        }

        // 設定預設聚焦最後一筆（id最大）
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

        // 呼叫聚焦函式在全部頁面載入後
        $(window).on('load', function () {
            focusMaxIdVideo();
            buildVideoList();
            applyVideoSize();
        });

        // 刪除圖片
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
                            // 如果刪除的是主面人臉，重新載入主面人臉
                            loadMasterFaces();
                        }
                        showMessage('success', '圖片刪除成功。');
                        applyVideoSize(); // 確保刪除後的影片大小正確
                    } else {
                        showMessage('error', response.message);
                    }
                },
                error: function () {
                    showMessage('error', '刪除失敗，請稍後再試。');
                }
            });
        });

        // 設定主面人臉
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
                        // 移除所有master類別
                        $('.face-screenshot[data-video-id="' + videoId + '"]').removeClass('master');
                        // 添加master類別到當前圖片
                        $('.face-screenshot[data-id="' + faceId + '"]').addClass('master');

                        // 更新左側主面人臉
                        updateMasterFace(response.data);

                        showMessage('success', '主面人臉已更新。');

                        // 移動到剛剛設定的主面人臉位置
                        setTimeout(() => {
                            let newMasterFace = $('.master-face-img[data-video-id="' + videoId + '"]');
                            if (newMasterFace.length) {
                                $('.master-face-img').removeClass('focused');
                                newMasterFace.addClass('focused');
                                // 滾動到該主面人臉
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

        // 拖曳上傳人臉截圖
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
                            applyVideoSize(); // 確保新加入的影片大小正確
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

        // 提交控制條表單
        $('#controls-form').on('submit', function (e) {
            e.preventDefault();
            let videoSizeVal = $('#video-size').val();
            let imageSizeVal = $('#image-size').val();
            let videoTypeVal = $('#video-type').val();
            let playModeValue = $('#play-mode').val();
            window.location.href = "{{ route('video.index') }}" + "?video_size=" + videoSizeVal + "&image_size=" + imageSizeVal + "&video_type=" + videoTypeVal + "&play_mode=" + playModeValue;
        });

        // 處理全螢幕變化事件
        function onFullScreenChange(e) {
            let fsElement = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;
            if (fsElement && $(fsElement).is('video')) {
                // Video has entered full-screen
                currentFullScreenVideoElement = fsElement;
                $(fsElement).addClass('is-fullscreen');

                // Set currentVideoIndex
                for (let i = 0; i < videoList.length; i++) {
                    if (videoList[i].videoElement === fsElement) {
                        currentVideoIndex = i;
                        break;
                    }
                }

                // Add 'ended' event listener
                fsElement.addEventListener('ended', onVideoEnded);

                // Initialize loop state
                fsElement.loop = playMode === '0' ? true : false;

                // Add mousemove event listener
                fsElement.addEventListener('mousemove', onVideoMouseMove);

                // Add touch event listeners
                fsElement.addEventListener('touchstart', onTouchStart, {passive: true});
                fsElement.addEventListener('touchend', onTouchEnd, {passive: true});

                // Show controls initially
                showFullscreenControls();

            } else {
                // Video has exited full-screen
                $('video.is-fullscreen').removeClass('is-fullscreen');
                $('body').removeClass('fullscreen-mode');

                // Remove event listeners from previous full-screen video
                if (currentFullScreenVideoElement) {
                    currentFullScreenVideoElement.removeEventListener('ended', onVideoEnded);
                    currentFullScreenVideoElement.removeEventListener('mousemove', onVideoMouseMove);
                    currentFullScreenVideoElement.removeEventListener('touchstart', onTouchStart);
                    currentFullScreenVideoElement.removeEventListener('touchend', onTouchEnd);
                    currentFullScreenVideoElement.loop = false;
                    currentFullScreenVideoElement = null;
                }

                // Hide controls
                hideFullscreenControls();
            }
        }

        document.addEventListener('fullscreenchange', onFullScreenChange);
        document.addEventListener('webkitfullscreenchange', onFullScreenChange);
        document.addEventListener('mozfullscreenchange', onFullScreenChange);
        document.addEventListener('msfullscreenchange', onFullScreenChange);

        // 全螢幕控制按鈕功能
        $('#prev-video-btn').on('click', function() {
            playPreviousVideo();
        });

        $('#next-video-btn').on('click', function() {
            playNextVideo();
        });

        // 隱藏控制按鈕的定時器
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
            let x = e.clientX - rect.left; // Mouse X position within the video element
            let y = e.clientY - rect.top;  // Mouse Y position within the video element

            let edgeThreshold = 50; // pixels

            if (x < edgeThreshold) {
                // Near the left edge
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
                // Near the right edge
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

            // Show controls if not already visible
            if (!controlsVisible) {
                showFullscreenControls();
            }

            // Hide controls after a timeout
            clearTimeout(controlsTimeout);
            controlsTimeout = setTimeout(function() {
                hideFullscreenControls();
                $('.prev-video-btn').removeClass('show');
                $('.next-video-btn').removeClass('show');
                prevButtonVisible = false;
                nextButtonVisible = false;
            }, 3000);
        }

        // 處理觸控事件
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
                // Horizontal swipe
                if (deltaX > 50) {
                    // Swipe right, next video
                    playNextVideo();
                } else if (deltaX < -50) {
                    // Swipe left, previous video
                    playPreviousVideo();
                }
            } else {
                // Vertical swipe
                if (deltaY > 50) {
                    // Swipe down, toggle loop
                    toggleLoopPlay();
                } else if (deltaY < -50) {
                    // Swipe up, random play
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

            // Update currentVideoIndex
            currentVideoIndex = index;

            // Scroll to the next video
            $('html, body').animate({
                scrollTop: nextVideoData.videoRow.offset().top - 100
            }, 500);

            let isFullScreen = document.fullscreenElement === nextVideoData.videoElement || document.webkitFullscreenElement === nextVideoData.videoElement || document.mozFullScreenElement === nextVideoData.videoElement || document.msFullscreenElement === nextVideoData.videoElement;

            if (isFullScreen) {
                let videoElement = currentFullScreenVideoElement;

                if (videoElement) {
                    // Update the video source
                    let sourceElement = videoElement.querySelector('source');
                    if (sourceElement) {
                        sourceElement.src = nextVideoData.videoElement.querySelector('source').src;
                    } else {
                        videoElement.src = nextVideoData.videoElement.src;
                    }

                    // Load the new video
                    videoElement.load();

                    videoElement.currentTime = 0;
                    videoElement.play();

                    // Update loop state
                    videoElement.loop = playMode === '0' ? true : false;

                    // Update event listeners
                    videoElement.removeEventListener('ended', onVideoEnded);
                    videoElement.addEventListener('ended', onVideoEnded);
                }

            } else {
                // Play next video
                nextVideoData.videoElement.currentTime = 0;
                nextVideoData.videoElement.play();

                // Enter full-screen with next video element
                enterFullScreen(nextVideoData.videoElement);
            }
        }

        function onVideoEnded(e) {
            let videoElement = e.target;
            if (videoElement.loop) {
                // 單部循環已開啟，自動重播
                videoElement.play();
            } else if (playMode === '1') {
                // 自動播放下一部
                if (currentVideoIndex < videoList.length - 1) {
                    playVideoAtIndex(currentVideoIndex + 1);
                } else {
                    showMessage('info', '已經是最後一部影片');
                }
            }
        }

        // 初始化 Sortable 和載入更多功能
        buildVideoList();

        // 設定預設聚焦最後一筆（id最大）
        $(window).on('load', function () {
            focusMaxIdVideo();
            buildVideoList();
            applyVideoSize();
        });
    });
</script>
</body>
</html>
