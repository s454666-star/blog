<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Random Video Player</title>
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
        }
        .controls {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .controls a {
            padding: 10px 20px;
            background-color: #1db954;
            color: #ffffff;
            text-decoration: none;
            border-radius: 4px;
        }
        .controls a:hover {
            background-color: #1ed760;
        }
    </style>
</head>
<body>

<video id="videoPlayer" controls>
    Your browser does not support the video tag.
</video>

<div class="video-info" id="videoInfo">
    Loading video...
</div>

<div class="controls">
    <a href="{{ route('videos.player') }}" id="refreshLink">Refresh Video Type</a>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const videoPlayer = document.getElementById('videoPlayer');
        const videoInfo = document.getElementById('videoInfo');
        const refreshLink = document.getElementById('refreshLink');

        // Get videoType from Blade variable
        const videoType = "{{ $videoType }}";

        // Update the refresh link to retain the current video_type
        refreshLink.href = `{{ route('videos.player') }}?video_type=${videoType}`;

        const baseUrl = 'https://video.test/'; // Static resource base URL

        /**
         * Fetch a random video based on video_type from the server.
         */
        async function fetchRandomVideo() {
            try {
                videoInfo.textContent = 'Loading video...';
                const response = await fetch(`{{ route('videos.api.random_type') }}?video_type=${videoType}`);

                if (!response.ok) {
                    if (response.status === 404) {
                        throw new Error('No more videos available.');
                    } else {
                        throw new Error('Failed to fetch video.');
                    }
                }

                const data = await response.json();

                if (data.success) {
                    const videoUrl = baseUrl + data.data.video_path;
                    const videoName = data.data.video_name;

                    videoPlayer.src = videoUrl;
                    videoPlayer.play();
                    videoInfo.textContent = `Now Playing: ${videoName}`;
                } else {
                    videoInfo.textContent = data.message || 'Failed to load video.';
                }
            } catch (error) {
                console.error('Error fetching video:', error);
                videoInfo.textContent = 'Error loading video.';
            }
        }

        /**
         * Event listener for when the current video ends.
         * Automatically fetches and plays the next video.
         */
        videoPlayer.addEventListener('ended', function () {
            fetchRandomVideo();
        });

        // Initial video load
        fetchRandomVideo();
    });
</script>

</body>
</html>
