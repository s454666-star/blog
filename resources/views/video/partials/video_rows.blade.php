<!-- resources/views/video/partials/video_rows.blade.php -->

@foreach($videos as $video)
    <div class="video-row" data-id="{{ $video->id }}">
        <div class="video-container">
            <div class="video-wrapper">
                <video width="100%" controls>
                    <source src="{{ config('app.video_base_url') }}/{{ $video->video_path }}" type="video/mp4">
                    您的瀏覽器不支援影片播放。
                </video>
{{--                <button class="fullscreen-btn">全螢幕</button>--}}
            </div>
        </div>
        <div class="images-container">
            <div class="screenshot-images mb-2">
                <h5>影片截圖</h5>
                <div class="d-flex flex-wrap">
                    @foreach($video->screenshots as $screenshot)
                        <div class="screenshot-container">
                            <img src="{{ config('app.video_base_url') }}/{{ $screenshot->screenshot_path }}" alt="截圖" class="screenshot hover-zoom" data-id="{{ $screenshot->id }}" data-type="screenshot">
                            <button class="delete-icon" data-id="{{ $screenshot->id }}" data-type="screenshot">&times;</button>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="face-screenshot-images">
                <h5>人臉截圖</h5>
                <div class="d-flex flex-wrap face-upload-area" data-video-id="{{ $video->id }}" style="position: relative; border: 2px dashed #007bff; border-radius: 5px; padding: 10px; min-height: 120px;">
                    @foreach($video->screenshots as $screenshot)
                        @foreach($screenshot->faceScreenshots as $face)
                            <div class="face-screenshot-container">
                                <img src="{{ config('app.video_base_url') }}/{{ $face->face_image_path }}" alt="人臉截圖" class="face-screenshot hover-zoom {{ $face->is_master ? 'master' : '' }}" data-id="{{ $face->id }}" data-video-id="{{ $video->id }}" data-type="face-screenshot">
                                <button class="set-master-btn" data-id="{{ $face->id }}" data-video-id="{{ $video->id }}">★</button>
                                <button class="delete-icon" data-id="{{ $face->id }}" data-type="face-screenshot">&times;</button>
                            </div>
                        @endforeach
                    @endforeach
                    <div class="upload-instructions" style="width: 100%; text-align: center; color: #aaa; margin-top: 10px;">
                        拖曳圖片到此處上傳
                    </div>
                </div>
            </div>
        </div>
    </div>
@endforeach
