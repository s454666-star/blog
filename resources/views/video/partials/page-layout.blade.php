<!-- === 主面人臉開關按鈕 === -->
<button id="toggle-master-faces" title="展開 / 收合主面人臉">☰</button>

<!-- ===== 主面人臉側欄 ===== -->
<div class="master-faces">
    <h5>主面人臉</h5>
    <div id="master-faces-status" class="master-faces-status">主面人臉載入中...</div>
    <div class="master-face-images"></div>
</div>

<!-- ===== 內容區 ===== -->
<div class="container mt-4">
    <div id="message-container" class="message-container"></div>

    <div id="videos-list">
        @include('video.partials.video_rows', ['videos' => $videos])
    </div>

    <div id="load-more" class="text-center my-4" style="display:none">
        <p>正在載入更多影片...</p>
    </div>
</div>

<!-- ===== 全螢幕控制按鈕 ===== -->
<div id="fullscreen-controls" class="fullscreen-controls">
    <button id="prev-video-btn" class="prev-video-btn">❮</button>
    <button id="next-video-btn" class="next-video-btn">❯</button>
</div>

<!-- ===== 底部控制列 ===== -->
<div class="controls">
    <form id="controls-form" class="controls-form" method="GET">
        <input type="hidden" id="focus-id" name="focus_id" value="{{ $focusId }}">

        <div class="control-group control-group--slider">
            <div class="control-heading">
                <label class="control-label" for="video-size">影片大小</label>
                <span id="video-size-value" class="control-status">{{ request('video_size', 25) }}%</span>
            </div>
            <input
                id="video-size"
                class="control-range"
                type="range"
                name="video_size"
                min="10"
                max="50"
                value="{{ request('video_size', 25) }}"
            >
        </div>

        <div class="control-group control-group--slider">
            <div class="control-heading">
                <label class="control-label" for="image-size">截圖大小</label>
                <span id="image-size-value" class="control-status">{{ request('image_size', 200) }}px</span>
            </div>
            <input
                id="image-size"
                class="control-range"
                type="range"
                name="image_size"
                min="100"
                max="300"
                value="{{ request('image_size', 200) }}"
            >
        </div>

        <div class="control-group control-group--select">
            <div class="control-heading">
                <label class="control-label" for="video-type">影片類別</label>
            </div>
            <div class="control-select-wrap">
                <select id="video-type" name="video_type" class="form-control control-select">
                    @for($i = 1; $i <= 4; $i++)
                        <option value="{{ $i }}" {{ request('video_type', '1') == $i ? 'selected' : '' }}>{{ $i }}</option>
                    @endfor
                </select>
            </div>
        </div>

        <div class="control-group control-group--toggle">
            <div class="control-heading">
                <label class="control-label" for="play-mode">播放模式</label>
                <span id="play-mode-label" class="control-status control-status--toggle"></span>
            </div>
            <input
                id="play-mode"
                class="control-range control-range--toggle"
                type="range"
                name="play_mode"
                min="0"
                max="1"
                value="{{ request('play_mode', '0') }}"
                step="1"
            >
        </div>

        <div class="control-group control-group--select">
            <div class="control-heading">
                <label class="control-label" for="sort-by">排序方式</label>
            </div>
            <div class="control-select-wrap">
                <select id="sort-by" name="sort_by" class="form-control control-select">
                    <option value="duration" {{ $sortBy === 'duration' ? 'selected' : '' }}>依時長</option>
                    <option value="id" {{ $sortBy === 'id' ? 'selected' : '' }}>依先後</option>
                </select>
            </div>
        </div>

        <div class="control-group control-group--select">
            <div class="control-heading">
                <label class="control-label" for="sort-dir">排序方向</label>
            </div>
            <div class="control-select-wrap">
                <select id="sort-dir" name="sort_dir" class="form-control control-select">
                    <option value="asc" {{ $sortDir === 'asc' ? 'selected' : '' }}>由小到大</option>
                    <option value="desc" {{ $sortDir === 'desc' ? 'selected' : '' }}>由大到小</option>
                </select>
            </div>
        </div>

        <div class="control-group control-group--toggle">
            <div class="control-heading">
                <label class="control-label" for="missing-only">未選主面</label>
                <span id="missing-only-label" class="control-status control-status--toggle"></span>
            </div>
            <input
                id="missing-only"
                class="control-range control-range--toggle"
                type="range"
                name="missing_only"
                min="0"
                max="1"
                step="1"
                value="{{ $missingOnly ? 1 : 0 }}"
            >
        </div>

        <div class="control-group control-group--action">
            <div class="control-heading">
                <span class="control-label control-label--ghost">操作</span>
            </div>
            <button id="delete-focused-btn" class="btn btn-warning control-action-btn" type="button">刪除聚焦的影片</button>
        </div>
    </form>
</div>

<!-- ===== Blade 模板 (人臉截圖) ===== -->
<template id="face-screenshot-template">
    <div class="face-screenshot-container">
        <img
            src="{{ rtrim(config('app.video_base_url'), '/') }}/{{ '{face_image_path}' }}"
            class="face-screenshot hover-zoom {{ '{is_master_class}' }}"
            alt="人臉截圖"
            data-id="{{ '{face_id}' }}"
            data-video-id="{{ '{video_id}' }}"
            data-type="face-screenshot"
            loading="lazy"
            decoding="async"
            fetchpriority="low"
        >
        <button class="set-master-btn" data-id="{{ '{face_id}' }}" data-video-id="{{ '{video_id}' }}">★</button>
        <button class="delete-icon" data-id="{{ '{face_id}' }}" data-type="face-screenshot">&times;</button>
    </div>
</template>

<!-- ===== 放大圖片容器 ===== -->
<div id="image-modal" class="image-modal">
    <img src="" alt="放大圖片">
</div>
