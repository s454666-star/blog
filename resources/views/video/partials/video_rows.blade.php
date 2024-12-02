<!-- resources/views/videos/video_rows.blade.php -->

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
                            <img src="https://video.test/{{ $face->face_image_path }}" alt="人臉截圖" class="face-screenshot hover-zoom {{ $face->is_master ? 'master' : '' }}" data-id="{{ $face->id }}" data-video-id="{{ $video->id }}">
                        @endforeach
                    @endforeach
                </div>
            </div>
        </div>
    </div>
@endforeach
