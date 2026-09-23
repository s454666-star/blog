@extends('video-journal.layout')
@section('title', $entry->title)
@section('content')
<div class="detail-top"><a class="back-link" href="{{ route('video-journal.index') }}">← 返回映像庫</a><span class="eyebrow">TUBE {{ str_pad($entry->id, 3, '0', STR_PAD_LEFT) }} / SOFT PLAYBACK</span></div>
<section class="player-section" aria-label="影片播放器">
    <div class="player-shell"><video id="journal-video" controls playsinline preload="metadata" src="{{ $remote ? $entry->source : route('video-journal.media', $entry->id) }}" data-subtitles="{{ $remote ? '' : route('video-journal.subtitles', $entry->id) }}" data-remote="{{ $remote ? '1' : '0' }}">你的瀏覽器不支援 HTML5 影片。</video><div id="player-error" class="player-error" role="alert" hidden>暫時無法播放。請確認來源還在、網路正常，且編碼可由瀏覽器播放（建議 MP4 / H.264）。</div></div>
    <div class="player-caption"><span><i class="status-dot"></i><span id="subtitle-status">正在尋找同名字幕…</span></span><div class="player-caption-actions"><button type="button" id="capture-frame" class="capture-button" title="截取目前畫面到剪貼簿（快捷鍵 -）">▣ 截圖</button><label class="subtitle-button" for="subtitle-file">CC <span>載入字幕</span><input type="file" id="subtitle-file" accept=".srt,.vtt" class="visually-hidden"></label></div></div>
</section>
<section class="story-section" id="story" data-save-url="{{ route('video-journal.update', $entry->id) }}">
    <aside class="story-aside"><span class="eyebrow">THE NOTE</span><span class="aside-line"></span><p>映像之外，<br>還有一點點心動備忘。</p><span class="aside-flower">✿</span><p class="aside-date">建立於<br>{{ $entry->created_at->format('Y.m.d') }}</p></aside>
    <div class="story-main"><div class="story-toolbar"><span class="story-date">最後編輯 <span id="updated-at">{{ $entry->updated_at->format('Y.m.d H:i') }}</span></span><button id="edit-toggle" class="button secondary small" type="button">✎ 編輯小記</button></div>
        <label class="visually-hidden" for="story-title">映像標題</label><input id="story-title" class="story-title" value="{{ $entry->title }}" maxlength="200" readonly aria-label="映像標題">
        <script type="application/json" id="journal-metadata">@json(['tags' => $entry->tags ?? [], 'portraits' => $entry->portraits ?? [], 'covers' => $entry->covers ?? []])</script>
        <section class="journal-metadata" aria-label="封面、標籤與大頭照">
            <div class="metadata-heading"><h3>自選封面</h3><span id="cover-count">0 / 2</span></div>
            <div id="cover-list" class="portrait-list cover-list"></div><p id="covers-empty" class="muted">尚未設定封面</p>
            <div id="cover-controls" hidden><button type="button" id="add-cover" class="button secondary small">▧ 選擇封面</button><input type="file" id="cover-file" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden><p class="field-help">可自選 2 張，查詢時會一起顯示。每張最多 20 MB，自動等比例縮至最高 1080P。</p></div>
            <div class="metadata-heading"><h3>標籤</h3><span id="tag-count">0 / 5</span></div>
            <div id="tag-list" class="tag-list"></div><p id="tags-empty" class="muted">尚未加入標籤</p>
            <div id="tag-controls" class="tag-controls" hidden><input id="tag-input" class="field" maxlength="40" placeholder="輸入標籤，按 Enter 新增" aria-label="新增標籤"><button id="add-tag" class="button secondary small" type="button">＋ 加入標籤</button></div>
            <div class="metadata-heading"><h3>人臉特寫</h3><span id="portrait-count">0 / 5</span></div>
            <div id="portrait-list" class="portrait-list"></div><p id="portraits-empty" class="muted">尚未加入大頭照</p>
            <div id="portrait-controls" hidden><button type="button" id="add-portrait" class="button secondary small">▧ 加入大頭照</button><input type="file" id="portrait-file" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden><p class="field-help">最多 5 張，每張最多 20 MB；自動等比例縮至 1080P。第一張會顯示在查詢卡片，可指定其他照片。</p></div>
        </section>
        <div id="editor-tools" class="editor-tools" hidden role="toolbar" aria-label="文字格式"><button type="button" data-command="bold" aria-label="粗體"><b>B</b></button><button type="button" data-command="italic" aria-label="斜體"><i>I</i></button><button type="button" data-command="underline" aria-label="底線"><u>U</u></button><span class="tool-divider"></span><button type="button" data-command="formatBlock" data-value="h2" aria-label="段落標題">H₂</button><button type="button" data-command="formatBlock" data-value="p" aria-label="一般段落">¶</button><button type="button" data-command="insertUnorderedList" aria-label="項目清單">☷</button><button type="button" data-command="formatBlock" data-value="blockquote" aria-label="引言">❞</button><span class="tool-divider"></span><button type="button" id="insert-image">▧ 插入圖片</button><input type="file" id="image-file" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden><span class="paste-hint">也可以直接貼上文字與圖片</span></div>
        <div id="story-body" class="prose" role="textbox" aria-label="小記內文" aria-multiline="true" contenteditable="false" data-placeholder="寫下這段映像的小心情，或貼上喜歡的圖片…">{!! $entry->body !!}</div>
        <p id="empty-body" class="body-empty" @if($entry->body) hidden @endif>小記還空著。點「編輯小記」，為這段映像留下第一句軟軟的話。</p>
        <div id="save-bar" class="save-bar" hidden><span id="save-state" aria-live="polite">儲存時圖片自動縮至 1080P，維持比例</span><button type="button" id="save-button" class="button primary">儲存小記 <span>↗</span></button></div>
        <div class="story-end"><span></span><i>✿</i><span></span></div>
    </div>
</section>
@endsection
