<!-- resources/views/partials/video_list.blade.php -->

<div class="row">
    @forelse($videos as $video)
        <div class="col-md-4" id="video-card-{{ $video->id }}">
            <div class="video-card">
                <!-- 刪除按鈕 -->
                <button class="delete-button" data-id="{{ $video->id }}" title="刪除影片">
                    <i class="fas fa-trash-alt"></i>
                </button>
                <div class="video-thumbnail">
                    {{-- 設定影片播放路徑為當前 URL + /video/ + video_path --}}
                    <video width="100%" height="200" controls>
                        <source src="{{ url('video/' . $video->video_path) }}" type="video/mp4">
                        您的瀏覽器不支援影片播放。
                    </video>
                </div>
                <div class="video-details">
                    <h5 class="video-title">{{ $video->video_name }}</h5>
                    @if(isset($video->description))
                        <p class="video-description">{{ \Illuminate\Support\Str::limit($video->description, 100, '...') }}</p>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="col-12 text-center">
            <p>沒有找到相關影片。</p>
        </div>
    @endforelse
</div>

@if($videos->hasPages())
    <div class="row">
        <div class="col-12">
            {{ $videos->links() }}
        </div>
    </div>
@endif
