<!-- === 主面人臉開關按鈕 === -->
<a class="library-skip-link" href="#videos-list">跳至影片列表</a>
<button id="toggle-master-faces" type="button" title="展開 / 收合主面人臉" aria-label="展開 / 收合主面人臉" aria-controls="master-faces-panel">☰</button>

<!-- ===== 主面人臉側欄 ===== -->
<aside class="master-faces" id="master-faces-panel" aria-label="主面人臉">
    <div class="master-faces-header">
        <div class="master-faces-title-wrap">
            <h5>主面人臉</h5>
        </div>
    </div>
    <div id="master-faces-status" class="master-faces-status">主面人臉載入中...</div>
    <div class="master-face-images"></div>
</aside>

<!-- ===== 內容區 ===== -->
<main class="container mt-4">
    <header class="library-header">
        <div>
            <span class="library-eyebrow">VIDEO LIBRARY</span>
            <h1>影片資料庫</h1>
            <p>瀏覽、搜尋與整理，在同一個地方完成。</p>
        </div>
        <nav class="library-actions" aria-label="列表工具">
            <button type="button" id="library-search" class="library-button library-button--primary">搜尋影片</button>
            <button type="button" id="library-settings" class="library-button">顯示設定</button>
        </nav>
    </header>
    <div class="library-summary" aria-label="目前篩選條件">
        <strong>{{ number_format($videos->total()) }} 部影片</strong>
        <span>類別 {{ $videoType }}</span>
        <span>{{ $sortBy === 'duration' ? '依時長' : '依先後' }} · {{ $sortDir === 'asc' ? '由小到大' : '由大到小' }}</span>
        @if($missingOnly)<span>未選主面</span>@endif
        @if($keyword !== '')<span>搜尋：{{ $keyword }}</span>@endif
    </div>
    <div id="message-container" class="message-container" role="status" aria-live="polite" aria-relevant="additions"></div>

    <div id="videos-list" tabindex="-1" aria-label="影片列表" aria-busy="false">
        @if($videos->isEmpty())
            <section class="library-empty">
                <span class="library-empty-mark" aria-hidden="true">⌕</span>
                <h2>目前沒有符合條件的影片</h2>
                <p>試試其他關鍵字，或調整類別與篩選條件。</p>
                <a class="library-button" href="{{ route('video.index') }}">重設篩選</a>
            </section>
        @endif
        @include('video.partials.video_rows', ['videos' => $videos])
    </div>

    <div id="load-more" class="text-center my-4" role="status" aria-live="polite" style="display:none">
        <p>正在載入更多影片...</p>
    </div>
    <div id="load-retry" class="library-load-retry" hidden>
        <span>暫時無法載入影片，已保留目前的列表。</span>
        <button type="button" class="library-button">重新載入</button>
    </div>
</main>

<!-- ===== 全螢幕控制按鈕 ===== -->
<div id="fullscreen-controls" class="fullscreen-controls">
    <button id="prev-video-btn" class="prev-video-btn" aria-label="上一部影片">❮</button>
    <button id="next-video-btn" class="next-video-btn" aria-label="下一部影片">❯</button>
</div>

<!-- ===== 控制列開關 ===== -->
<div id="master-search-shell" class="master-search-shell {{ $keyword !== '' ? 'is-active' : '' }}">
    <div
        id="master-search-panel"
        class="master-search-panel {{ $keyword !== '' ? 'is-open' : '' }}"
        aria-hidden="{{ $keyword !== '' ? 'false' : 'true' }}"
    >
        <form id="master-search-form" class="master-search-form" autocomplete="off">
            <label class="sr-only" for="master-search-input">搜尋影片關鍵字</label>
            <div class="master-search-input-wrap">
                <svg class="master-search-input-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M10.5 4.75a5.75 5.75 0 1 0 0 11.5a5.75 5.75 0 0 0 0-11.5Z"></path>
                    <path d="m15.1 15.1 4.15 4.15"></path>
                </svg>
                <input
                    id="master-search-input"
                    class="master-search-input"
                    type="text"
                    value="{{ $keyword }}"
                    placeholder="搜尋檔名 / 路徑"
                    maxlength="120"
                >
            </div>
            <div class="master-search-actions">
                <button
                    id="master-search-context-toggle"
                    class="master-search-mode-toggle {{ $expandContextMode ? 'is-active' : '' }}"
                    type="button"
                    aria-pressed="{{ $expandContextMode ? 'true' : 'false' }}"
                >
                    <span class="master-search-mode-label">列出更多影片</span>
                    <span id="master-search-context-state" class="master-search-mode-state">{{ $expandContextMode ? '開啟' : '關閉' }}</span>
                </button>

                <div class="master-search-actions-row">
                    <button id="master-search-submit" class="master-search-submit" type="submit">搜尋</button>
                    <button
                        id="master-search-clear"
                        class="master-search-clear"
                        type="button"
                        {{ $keyword === '' ? 'disabled' : '' }}
                    >清除</button>
                </div>
            </div>
        </form>
    </div>

    <button
        id="toggle-master-search"
        class="master-search-toggle {{ $keyword !== '' ? 'is-active' : '' }}"
        type="button"
        title="搜尋關鍵字"
        aria-label="搜尋關鍵字"
        aria-expanded="{{ $keyword !== '' ? 'true' : 'false' }}"
        aria-controls="master-search-panel"
    >
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M10.5 4.75a5.75 5.75 0 1 0 0 11.5a5.75 5.75 0 0 0 0-11.5Z"></path>
            <path d="m15.1 15.1 4.15 4.15"></path>
            <path d="M8.25 10.5h4.5"></path>
            <path d="M10.5 8.25v4.5"></path>
        </svg>
    </button>
</div>

<button
    id="toggle-controls"
    class="controls-toggle"
    type="button"
    title="顯示控制列"
    aria-label="顯示控制列"
    aria-expanded="false"
    aria-controls="controls-panel"
>
    <svg class="controls-toggle-icon" viewBox="0 0 24 24" aria-hidden="true">
        <path d="M4 7h6m4 0h6M4 12h10m4 0h2M4 17h3m4 0h9"></path>
        <circle cx="12" cy="7" r="2"></circle>
        <circle cx="16" cy="12" r="2"></circle>
        <circle cx="9" cy="17" r="2"></circle>
    </svg>
</button>

<!-- ===== 底部控制列 ===== -->
<div id="controls-panel" class="controls">
    <form id="controls-form" class="controls-form" method="GET">
        <input type="hidden" id="focus-id" name="focus_id" value="{{ $focusId }}">
        <input type="hidden" id="keyword-value" name="keyword" value="{{ $keyword }}">
        <input type="hidden" id="expand-context-value" name="expand_context" value="{{ $expandContextMode ? 1 : 0 }}">

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
    <div class="face-screenshot-container" data-screenshot-id="{{ '{video_screenshot_id}' }}" data-video-id="{{ '{video_id}' }}">
        <img
            src="{{ rtrim(config('app.video_base_url'), '/') }}/{{ '{face_image_path}' }}?v={{ '{face_id}' }}"
            class="face-screenshot hover-zoom {{ '{is_master_class}' }}"
            alt="人臉截圖"
            data-id="{{ '{face_id}' }}"
            data-video-id="{{ '{video_id}' }}"
            data-screenshot-id="{{ '{video_screenshot_id}' }}"
            data-type="face-screenshot"
            loading="lazy"
            decoding="async"
            fetchpriority="low"
        >
    </div>
</template>

<!-- ===== 放大圖片容器 ===== -->
<div id="image-modal" class="image-modal">
    <img src="" alt="放大圖片">
</div>
