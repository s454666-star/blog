<!-- resources/views/videos/index.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Amethyst Video Library</title>
    <!-- Include Font Awesome and Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <style>
        body {
            font-family: 'Arial', sans-serif;
            margin: 0;
            padding: 20px;
            background: linear-gradient(135deg, #b19cd9 0%, #9b59b6 100%);
            color: #f0f0f0;
        }

        h1 {
            text-align: center;
            color: #ffffff;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background-color: #8a2be2;
            color: #ffffff;
            font-weight: bold;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
        }

        tr:nth-child(even) {
            background-color: #dcd6f7;
            color: #4b0082;
        }

        tr:nth-child(odd) {
            background-color: #e0d7f7;
            color: #4b0082;
        }

        tr:hover {
            background-color: #b19cd9;
            cursor: pointer;
            color: #ffffff;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
        }

        /* Enlarged and centered checkboxes */
        input[type="checkbox"] {
            width: 27px; /* 50% wider than 18px */
            height: 27px; /* 50% taller than 18px */
            border-radius: 4px;
            cursor: pointer;
            appearance: none;
            border: 2px solid #8a2be2;
            background-color: #e0d7f7;
            transition: all 0.3s;
            margin: auto;
            display: block;
        }

        input[type="checkbox"]:checked {
            background-color: #8a2be2;
            border-color: #8e44ad;
        }

        input[type="checkbox"]:checked:after {
            content: '\f00c';
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            display: block;
            color: #fff;
            font-size: 18px;
            text-align: center;
        }

        /* Styles for the image popup */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
        }

        .overlay img {
            max-width: 90%;
            max-height: 80%;
            border: 4px solid #8a2be2;
            border-radius: 8px;
        }

        #videoOverlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            display: none;
            justify-content: center;
            align-items: center;
        }

        td.play-icon {
            text-align: center; /* Centers the icon horizontally */
            vertical-align: middle; /* Centers the icon vertically */
        }

        /* Custom style for the play icon to increase size and cursor appearance */
        .play-icon i {
            font-size: 32px; /* Increase the font size to make the icon larger */
            cursor: pointer; /* Ensures the cursor changes to a pointer when hovering over the icon */
            color: #6a0dad; /* Deep violet color for the icon */
            transition: color 0.3s ease; /* Smooth transition for color change on hover */
        }

        .play-icon i:hover {
            color: #9b30ff; /* Lighter violet color when hovering */
        }
    </style>

    <script>
        // JavaScript function to handle the image pop-up
        function showPopupImage(url) {
            const overlay = document.getElementById('imageOverlay');
            const popupImage = overlay.querySelector('img');
            popupImage.src = url;
            overlay.style.display = 'flex';
        }

        function hidePopupImage() {
            const overlay = document.getElementById('imageOverlay');
            overlay.style.display = 'none';
        }

        function toggleImagePopup(url) {
            const overlay = document.getElementById('imageOverlay');
            const popupImage = overlay.querySelector('img');
            // Toggle display logic
            if (overlay.style.display === 'flex' && popupImage.src === url) {
                overlay.style.display = 'none'; // Hide if the same image is clicked
            } else {
                popupImage.src = url; // Change the source or show a new image
                overlay.style.display = 'flex'; // Show the overlay
            }
        }

        function playVideo(path) {
            var video = document.getElementById('videoPlayer');
            if (Hls.isSupported()) {
                var hls = new Hls();
                hls.loadSource(path);
                hls.attachMedia(video);
                hls.on(Hls.Events.MANIFEST_PARSED, function() {
                    video.play();
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                video.src = path;
                video.addEventListener('loadedmetadata', function() {
                    video.play();
                });
            }
            document.getElementById('videoOverlay').style.display = 'flex'; // Show the overlay
        }

        function closePlayer() {
            const player = document.getElementById('videoPlayer');
            player.pause(); // Pause the video
            document.getElementById('videoOverlay').style.display = 'none'; // Hide the overlay
        }
    </script>
</head>
<body>
<h1>Amethyst Video Library</h1>
<form method="GET" action="{{ route('videos.index') }}">
    <table>
        <thead>
        <tr>
            <th>Select</th>
            <th>
                <a href="{{ route('videos.index', ['sort' => 'id', 'direction' => request('direction') === 'asc' ? 'desc' : 'asc']) }}">ID</a>
            </th>
            <th>
                <a href="{{ route('videos.index', ['sort' => 'video_name', 'direction' => request('direction') === 'asc' ? 'desc' : 'asc']) }}">Video
                    Name</a></th>
            <th>
                <a href="{{ route('videos.index', ['sort' => 'video_time', 'direction' => request('direction') === 'asc' ? 'desc' : 'asc']) }}">Video
                    Time (seconds)</a></th>
            <th>Preview Image</th>
            <th>Video Screenshot</th>
            <th>Play Video</th>
        </tr>
        </thead>
        <tbody>
        @foreach($videos as $video)
            <tr>
                <td><input type="checkbox" name="selected_videos[]" value="{{ $video->id }}"></td>
                <td>{{ $video->id }}</td>
                <td>{{ $video->video_name }}</td>
                <td>{{ $video->video_time }}</td>
                <td><img src="{{ $video->preview_image }}" alt="Preview" width="100"
                         onclick="toggleImagePopup('{{ $video->preview_image }}')"></td>
                <td><img src="{{ $video->video_screenshot }}" alt="Screenshot" width="100"
                         onclick="toggleImagePopup('{{ $video->video_screenshot }}')"></td>
                <td class="play-icon"><i class="fas fa-play-circle" style="font-size:24px; cursor:pointer;"
                       onclick="playVideo('{{ $video->path }}')"></i></td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <!-- Pagination links with Bootstrap styling -->
    <div class="d-flex justify-content-center mt-4">
        {{ $videos->appends(request()->except('page'))->links('vendor.pagination.bootstrap-4') }}
    </div>
</form>

<!-- Image overlay for pop-up -->
<div id="imageOverlay" class="overlay" onclick="hidePopupImage()">
    <img src="" alt="Full Screenshot">
</div>
<div id="videoOverlay" class="overlay" onclick="closePlayer()">
    <video id="videoPlayer" controls style="width:90%; height:80%;"></video>
</div>
</body>
</html>
