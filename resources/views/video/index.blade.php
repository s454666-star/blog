<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>影片列表</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
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
            width: 25%;
            padding-right: 10px;
        }
        .images-container {
            width: 75%;
            padding-left: 10px;
        }
        .screenshot, .face-screenshot {
            width: 200px;
            height: 112px;
            object-fit: cover;
            margin: 5px;
            transition: transform 0.3s;
        }
        /* 移除:hover效果以避免抖動 */
        /* 放大圖片不再直接影響原圖 */
        /* 新增的放大圖片樣式 */
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
            left: 0;
            right: 0;
            background: #fff;
            padding: 10px 20px;
            border-top: 1px solid #ddd;
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .controls .control-group {
            margin-right: 20px;
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
        @media (max-width: 768px) {
            .video-container, .images-container {
                width: 100%;
                padding: 0;
            }
            .screenshot, .face-screenshot {
                width: 100px;
                height: 56px;
            }
        }
    </style>
</head>
<body>
<div class="container mt-4 mb-80">
    <!-- 上傳區 -->
{{--    <div class="upload-area" id="upload-area">--}}
{{--        將影片檔案拖曳到此處上傳--}}
{{--    </div>--}}

    <!-- 影片列表 -->
    <div id="videos-list">
        @foreach($videos as $video)
            <div class="video-row" data-id="{{ $video->id }}">
                <div class="video-container">
                    <div class="video-wrapper">
                        <video width="100%" controls>
                            <source src="https://video.test/{{ $video->video_path }}" type="video/mp4">
                            您的瀏覽器不支援影片播放。
                        </video>
                        <button class="fullscreen-btn">全螢幕</button>
                    </div>
                </div>
                <div class="images-container">
                    <div class="screenshot-images mb-2">
                        <h5>影片截圖</h5>
                        <div class="d-flex flex-wrap">
                            @foreach($video->screenshots as $screenshot)
                                <img src="https://video.test/{{ $screenshot->screenshot_path }}" alt="截圖" class="screenshot hover-zoom">
                            @endforeach
                        </div>
                    </div>
                    <div class="face-screenshot-images">
                        <h5>人臉截圖</h5>
                        <div class="d-flex flex-wrap">
                            @foreach($video->screenshots as $screenshot)
                                @foreach($screenshot->faceScreenshots as $face)
                                    <img src="https://video.test/{{ $face->face_image_path }}" alt="人臉截圖" class="face-screenshot hover-zoom">
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- 載入更多按鈕或提示 -->
    <div id="load-more" class="text-center my-4">
        <button id="load-more-btn" class="btn btn-primary">載入更多</button>
    </div>
</div>

<!-- 控制條 -->
<div class="controls d-flex justify-content-between align-items-center">
    <div class="control-group">
        <label for="video-size">影片大小:</label>
        <input type="range" id="video-size" min="10" max="50" value="25">
    </div>
    <div class="control-group">
        <label for="image-size">截圖大小:</label>
        <input type="range" id="image-size" min="100" max="300" value="200">
    </div>
    <div class="control-group">
        <!-- 移除刪除選取的影片按鈕 -->
        <button id="delete-focused-btn" class="btn btn-warning">刪除聚焦的影片</button>
    </div>
</div>

<!-- 模板：影片列 -->
<template id="video-row-template">
    <div class="video-row" data-id="{id}">
        <div class="video-container">
            <div class="video-wrapper">
                <video width="100%" controls>
                    <source src="https://video.test/{video_path}" type="video/mp4">
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
                <div class="d-flex flex-wrap">
                    {face_screenshot_images}
                </div>
            </div>
        </div>
    </div>
</template>

<!-- 放大圖片容器 -->
<div class="image-modal" id="image-modal">
    <img src="" alt="放大圖片">
</div>

<!-- 載入更多影片的JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script>
    let nextPage = {{ $videos->currentPage() + 1 }};
    let loading = false;

    function loadMoreVideos() {
        if (loading) return;
        loading = true;
        $('#load-more-btn').text('載入中...');

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data: { page: nextPage },
            success: function(response) {
                if(response.success) {
                    $('#videos-list').append(response.data);
                    nextPage = response.next_page;
                    loading = false;
                    $('#load-more-btn').text('載入更多');
                } else {
                    $('#load-more').html('<p>沒有更多資料了。</p>');
                }
            },
            error: function() {
                $('#load-more-btn').text('載入更多');
                alert('載入失敗，請稍後再試。');
                loading = false;
            }
        });
    }

    $(document).ready(function() {
        // 控制條調整
        $('#video-size').on('input', function () {
            let videoWidthPercent = $(this).val();
            let imagesWidthPercent = 100 - videoWidthPercent;
            $('.video-container').css('width', videoWidthPercent + '%');
            $('.images-container').css('width', imagesWidthPercent + '%');
        });

        $('#image-size').on('input', function () {
            let imageSize = $(this).val();
            $('.screenshot, .face-screenshot').css({
                'width': imageSize + 'px',
                'height': (imageSize * 0.56) + 'px' // 保持16:9比例
            });
        });

        // 初始化控制條狀態
        $('#video-size').trigger('input');
        $('#image-size').trigger('input');

        // 載入更多按鈕
        $('#load-more-btn').on('click', function () {
            loadMoreVideos();
        });

        // 滾動到最底部自動載入
        $(window).scroll(function () {
            if ($(window).scrollTop() + $(window).height() >= $(document).height() - 100) {
                loadMoreVideos();
            }
        });

        // 全螢幕按鈕
        $(document).on('click', '.fullscreen-btn', function (e) {
            e.stopPropagation(); // 防止觸發選取
            let video = $(this).siblings('video')[0];
            if (video.requestFullscreen) {
                video.requestFullscreen();
            } else if (video.webkitRequestFullscreen) { /* Safari */
                video.webkitRequestFullscreen();
            } else if (video.msRequestFullscreen) { /* IE11 */
                video.msRequestFullscreen();
            }
        });

        // 滑鼠移動控制進度條
        $(document).on('mousemove', 'video', function (e) {
            let video = this;
            let rect = video.getBoundingClientRect();
            let x = e.clientX - rect.left; // 滑鼠在影片上的X位置
            let percent = x / rect.width;
            video.currentTime = percent * video.duration;
        });

        // 拖曳上傳
        const uploadArea = $('#upload-area');

        uploadArea.on('dragover', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).addClass('dragover');
        });

        uploadArea.on('dragleave', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');
        });

        uploadArea.on('drop', function (e) {
            e.preventDefault();
            e.stopPropagation();
            $(this).removeClass('dragover');

            let files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                let formData = new FormData();
                formData.append('video_file', files[0]);

                $.ajax({
                    url: "{{ route('video.upload') }}",
                    method: 'POST',
                    data: formData,
                    contentType: false,
                    processData: false,
                    headers: {
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    success: function (response) {
                        if (response.success) {
                            // 使用模板新增影片
                            let template = $('#video-row-template').html();
                            let screenshotImages = '';
                            let faceScreenshotImages = '';
                            response.data.screenshots.forEach(function(screenshot) {
                                screenshotImages += `<img src="https://video.test/${screenshot.screenshot_path}" alt="截圖" class="screenshot hover-zoom">`;
                                screenshot.faceScreenshots.forEach(function(face) {
                                    faceScreenshotImages += `<img src="https://video.test/${face.face_image_path}" alt="人臉截圖" class="face-screenshot hover-zoom">`;
                                });
                            });
                            let newRow = template
                                .replace('{id}', response.data.id)
                                .replace('{video_path}', response.data.video_path)
                                .replace('{screenshot_images}', screenshotImages)
                                .replace('{face_screenshot_images}', faceScreenshotImages);
                            $('#videos-list').prepend(newRow);
                            alert('影片上傳成功！');
                        } else {
                            alert(response.message);
                        }
                    },
                    error: function (xhr) {
                        if (xhr.status === 409) {
                            alert(xhr.responseJSON.message);
                        } else {
                            alert('上傳失敗，請稍後再試。');
                        }
                    }
                });
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
            } else {
                // 如果未聚焦，移除其他聚焦並設為聚焦
                $('.video-row').removeClass('focused');
                $(this).addClass('focused');
            }

            // 更新最後選取的索引
            lastSelectedIndex = $('.video-row').index(this);
        });

        // 刪除聚焦的影片
        $('#delete-focused-btn').on('click', function () {
            let focusedRow = $('.video-row.focused');
            if (focusedRow.length === 0) {
                alert('沒有聚焦的影片。');
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
                    if (response.success) {
                        focusedRow.remove();
                        alert(response.message);
                    } else {
                        alert(response.message);
                    }
                },
                error: function () {
                    alert('刪除失敗，請稍後再試。');
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
    });
</script>
</body>
</html>
