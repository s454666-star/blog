<!-- resources/views/video/partials/video_rows.blade.php -->

@foreach($videos as $video)
    @php
        $baseUrl = rtrim(config('app.video_base_url'), '/');

        $videoPath = ltrim((string) $video->video_path, '/');
        $videoUrl = $baseUrl . '/' . $videoPath;

        $firstScreenshotPath = $video->screenshots->first()?->screenshot_path;
        $posterUrl = '';
        if (!empty($firstScreenshotPath)) {
            $posterUrl = $baseUrl . '/' . ltrim((string) $firstScreenshotPath, '/');
        }
    @endphp

    <div class="video-row" data-id="{{ $video->id }}" data-duration="{{ $video->duration }}">
        <div class="video-container">
            <div class="video-wrapper">
                <div class="video-headline">
                    <div class="video-title-stack">
                        <div class="video-title-chip video-title-chip--main">{{ e($video->video_name) }}</div>
                        <div class="video-title-chip video-title-chip--path">{{ e($video->video_path) }}</div>
                    </div>
                    <div class="video-meta-chips">
                        <span class="video-chip">#{{ $video->id }}</span>
                        <span class="video-chip">{{ number_format((float) $video->duration, 2) }}s</span>
                    </div>
                </div>

                <video width="100%" controls preload="none" playsinline @if($posterUrl) poster="{{ $posterUrl }}" @endif>
                    <source src="{{ $videoUrl }}" type="video/mp4">
                    您的瀏覽器不支援影片播放。
                </video>
            </div>
        </div>

        <div class="images-container">
            <div class="screenshot-images mb-2">
                <h5>影片截圖</h5>
                <div class="d-flex flex-wrap">
                    @foreach($video->screenshots as $screenshot)
                        <div class="screenshot-container" data-screenshot-id="{{ $screenshot->id }}" data-video-id="{{ $video->id }}">
                            <img
                                src="{{ $baseUrl }}/{{ ltrim((string) $screenshot->screenshot_path, '/') }}"
                                alt="截圖"
                                class="screenshot hover-zoom"
                                data-id="{{ $screenshot->id }}"
                                data-video-id="{{ $video->id }}"
                                data-screenshot-id="{{ $screenshot->id }}"
                                data-type="screenshot"
                                loading="lazy"
                                decoding="async"
                                fetchpriority="low"
                            >
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="face-screenshot-images">
                <h5>人臉截圖</h5>
                <div class="d-flex flex-wrap face-upload-area" data-video-id="{{ $video->id }}">
                    @foreach($video->screenshots as $screenshot)
                        @foreach($screenshot->faceScreenshots as $face)
                            <div class="face-screenshot-container" data-screenshot-id="{{ $screenshot->id }}" data-video-id="{{ $video->id }}">
                                <img
                                    src="{{ $baseUrl }}/{{ ltrim((string) $face->face_image_path, '/') }}"
                                    alt="人臉截圖"
                                    class="face-screenshot hover-zoom {{ $face->is_master ? 'master' : '' }}"
                                    data-id="{{ $face->id }}"
                                    data-video-id="{{ $video->id }}"
                                    data-screenshot-id="{{ $screenshot->id }}"
                                    data-type="face-screenshot"
                                    loading="lazy"
                                    decoding="async"
                                    fetchpriority="low"
                                >
                            </div>
                        @endforeach
                    @endforeach

                    <div class="face-paste-target" contenteditable="true" tabindex="0">
                        <img class="face-paste-preview" alt="貼上預覽">
                        <span class="face-paste-hint">點一下後貼上，Enter 上傳</span>
                    </div>
                    <div class="upload-instructions">
                        拖曳圖片到此處上傳
                    </div>
                </div>
            </div>
        </div>
    </div>
@endforeach
