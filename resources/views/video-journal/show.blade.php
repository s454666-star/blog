@extends('video-journal.layout')
@section('title', $entry->title)
@section('content')
<div class="detail-top"><a class="back-link" href="{{ route('video-journal.index') }}">← 返回影片誌</a><span class="eyebrow">FRAME {{ str_pad($entry->id, 3, '0', STR_PAD_LEFT) }} / YOUR PERSONAL STORY</span></div>
<section class="player-section" aria-label="影片播放器">
    <div class="player-shell"><video id="journal-video" controls playsinline preload="metadata" src="{{ $remote ? $entry->source : route('video-journal.media', $entry->id) }}" data-subtitles="{{ $remote ? '' : route('video-journal.subtitles', $entry->id) }}" data-remote="{{ $remote ? '1' : '0' }}">你的瀏覽器不支援 HTML5 影片。</video><div id="player-error" class="player-error" role="alert" hidden>暫時無法播放影片。請確認來源仍存在、網路連線正常，且影片編碼可由瀏覽器播放（建議 MP4 / H.264）。</div></div>
    <div class="player-caption"><span><i class="status-dot"></i><span id="subtitle-status">正在尋找同名字幕…</span></span><label class="subtitle-button" for="subtitle-file">CC <span>載入字幕</span><input type="file" id="subtitle-file" accept=".srt,.vtt" class="visually-hidden"></label></div>
</section>
<section class="story-section" id="story" data-save-url="{{ route('video-journal.update', $entry->id) }}">
    <aside class="story-aside"><span class="eyebrow">THE STORY</span><span class="aside-line"></span><p>映像之外，<br>還有想記住的事。</p><span class="aside-flower">✳</span><p class="aside-date">建立於<br>{{ $entry->created_at->format('Y.m.d') }}</p></aside>
    <div class="story-main"><div class="story-toolbar"><span class="story-date">最後編輯 <span id="updated-at">{{ $entry->updated_at->format('Y.m.d H:i') }}</span></span><button id="edit-toggle" class="button secondary small" type="button">✎ 編輯文章</button></div>
        <label class="visually-hidden" for="story-title">文章標題</label><input id="story-title" class="story-title" value="{{ $entry->title }}" maxlength="200" readonly aria-label="文章標題">
        <div id="editor-tools" class="editor-tools" hidden role="toolbar" aria-label="文字格式"><button type="button" data-command="bold" aria-label="粗體"><b>B</b></button><button type="button" data-command="italic" aria-label="斜體"><i>I</i></button><button type="button" data-command="underline" aria-label="底線"><u>U</u></button><span class="tool-divider"></span><button type="button" data-command="formatBlock" data-value="h2" aria-label="段落標題">H₂</button><button type="button" data-command="formatBlock" data-value="p" aria-label="一般段落">¶</button><button type="button" data-command="insertUnorderedList" aria-label="項目清單">☷</button><button type="button" data-command="formatBlock" data-value="blockquote" aria-label="引言">❞</button><span class="tool-divider"></span><button type="button" id="insert-image">▧ 插入圖片</button><input type="file" id="image-file" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden><span class="paste-hint">也可以直接貼上文字與圖片</span></div>
        <div id="story-body" class="prose" role="textbox" aria-label="文章內文" aria-multiline="true" contenteditable="false" data-placeholder="寫下這段影片的故事，或貼上喜歡的圖片…">{!! $entry->body !!}</div>
        <p id="empty-body" class="body-empty" @if($entry->body) hidden @endif>故事還留著空白。點選「編輯文章」，為這段映像寫下第一句話。</p>
        <div id="save-bar" class="save-bar" hidden><span id="save-state" aria-live="polite">文字與圖片會一起儲存</span><button type="button" id="save-button" class="button primary">儲存文章 <span>↗</span></button></div>
        <div class="story-end"><span></span><i>✳</i><span></span></div>
    </div>
</section>
@endsection
