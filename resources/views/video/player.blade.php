<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Video Player with Remote Control</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            background-color: #121212;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
            position: relative; /* 為了放提示訊息的定位 */
        }
        video {
            max-width: 100%;
            height: auto;
            border: 2px solid #ffffff;
            border-radius: 8px;
            background-color: #000000;
        }
        .video-info {
            margin-top: 15px;
            text-align: center;
            font-size: 1.1em;
        }

        /* 提示訊息的樣式 */
        #messageBox {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background-color: rgba(0, 0, 0, 0.8);
            color: #fff;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 1em;
            z-index: 999;
            opacity: 0;
            transition: opacity 0.4s ease; /* 淡入淡出效果 */
            pointer-events: none; /* 避免擋住使用者操作 */
        }
    </style>
</head>
<body>

<video id="videoPlayer" controls>
    Your browser does not support the video tag.
</video>

<div class="video-info" id="videoInfo">
    Loading video list...
</div>

<!-- 放提示訊息 -->
<div id="messageBox"></div>

<script>
    document.addEventListener('DOMContentLoaded', async function () {
        const videoPlayer = document.getElementById('videoPlayer');
        const videoInfo   = document.getElementById('videoInfo');
        const messageBox  = document.getElementById('messageBox');

        // 以 video_type=3 為範例，如後端不需此參數可省略
        const videoType = @json($videoType);
        // 取得後端提供的隨機影片清單路由
        const apiUrl = `{{ route('videos.api.videoplayer_list') }}?video_type=${videoType}`;

        let videoList = [];   // 存放後端回傳的隨機影片 URL
        let currentIndex = 0; // 目前播放中的索引

        // === 單部循環開關 ===
        let isSingleRepeat = false;

        // === 長按判斷用計時器與閾值(毫秒) ===
        let playPauseTimer = null;
        const longPressThreshold = 500; // 按超過 500ms 視為長按

        /**
         * 顯示可自動淡出的提示訊息
         */
        function showMessage(message) {
            // 設定文字，並立即顯示
            messageBox.textContent = message;
            messageBox.style.opacity = 1;

            // 2 秒後淡出
            setTimeout(() => {
                messageBox.style.opacity = 0;
            }, 2000);
        }

        /**
         * 向後端抓取 100 筆影片清單
         */
        async function fetchVideoList() {
            videoInfo.textContent = 'Loading video list...';
            try {
                const response = await fetch(apiUrl);
                if (!response.ok) {
                    throw new Error('Failed to fetch video list');
                }
                const data = await response.json();

                if (!data.success || !Array.isArray(data.data) || data.data.length === 0) {
                    videoInfo.textContent = data.message || 'No videos found.';
                    return;
                }

                // 從後端取得的影片清單
                videoList = data.data; // 例如: ["https://public.test/video/folder1/test1.mp4", ...]
                currentIndex = 0;
                loadVideo(currentIndex);

            } catch (error) {
                console.error('Error fetching video list:', error);
                videoInfo.textContent = 'Error loading video list.';
            }
        }

        /**
         * 依索引載入並播放影片
         */
        function loadVideo(index) {
            if (videoList.length === 0) return;

            // 確保索引在範圍內，若超出則循環
            if (index < 0) {
                index = videoList.length - 1;
            } else if (index >= videoList.length) {
                index = 0;
            }

            currentIndex = index;
            const videoUrl = videoList[currentIndex];

            videoPlayer.src = videoUrl;
            videoPlayer.play().catch(err => {
                console.error('Error playing video:', err);
                videoInfo.textContent = 'Error playing video.';
            });

            videoInfo.textContent = `Now Playing: Video #${currentIndex + 1}`;
        }

        // 播放上一部
        function playPreviousVideo() {
            loadVideo(currentIndex - 1);
        }

        // 播放下一部
        function playNextVideo() {
            loadVideo(currentIndex + 1);
        }

        // 首次載入清單
        await fetchVideoList();

        // 影片播畢
        videoPlayer.addEventListener('ended', function() {
            if (isSingleRepeat) {
                // 單部循環時，重播同一支
                videoPlayer.currentTime = 0;
                videoPlayer.play();
            } else {
                // 正常情況：播下一部
                playNextVideo();
            }
        });

        // 監聽鍵盤(遙控器)事件 - keydown
        document.addEventListener('keydown', function(event) {
            // console.log('keydown:', event.key);
            switch (event.key) {
                // === 快退 5 秒 ===
                case 'ArrowLeft':
                    event.preventDefault();
                    videoPlayer.currentTime = Math.max(0, videoPlayer.currentTime - 5);
                    break;
                // === 快進 5 秒 ===
                case 'ArrowRight':
                    event.preventDefault();
                    videoPlayer.currentTime = Math.min(videoPlayer.duration, videoPlayer.currentTime + 5);
                    break;
                // === 上一部 ===
                case 'MediaTrackPrevious':
                case 'MediaPreviousTrack':
                    event.preventDefault();
                    playPreviousVideo();
                    break;
                // === 下一部 ===
                case 'MediaTrackNext':
                case 'MediaNextTrack':
                    event.preventDefault();
                    playNextVideo();
                    break;
                // === 撥放/暫停(長按切換單部循環) ===
                case 'MediaPlayPause':
                    // 若計時器尚未存在，表示剛開始按下
                    if (playPauseTimer === null) {
                        playPauseTimer = setTimeout(() => {
                            // 長按超過閾值 => 切換單部循環
                            isSingleRepeat = !isSingleRepeat;
                            showMessage(`單部循環已${isSingleRepeat ? '開啟' : '關閉'}`);
                            // 清除計時器，避免重複觸發
                            playPauseTimer = null;
                        }, longPressThreshold);
                    }
                    break;
                default:
                    break;
            }
        });

        // 監聽鍵盤(遙控器)事件 - keyup
        document.addEventListener('keyup', function(event) {
            // console.log('keyup:', event.key);
            switch (event.key) {
                case 'MediaPlayPause':
                    if (playPauseTimer !== null) {
                        // 若計時器尚存，表示未達長按時間 => 執行原生(短按)行為
                        clearTimeout(playPauseTimer);
                        playPauseTimer = null;
                        // 不做 preventDefault，讓播放器執行原生播放/暫停行為
                    }
                    break;
                default:
                    break;
            }
        });
    });
</script>

</body>
</html>
