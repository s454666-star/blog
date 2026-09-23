@extends('video-journal.layout')
@section('title', '我的映像庫')
@section('content')
<section class="hero">
    <div class="hero-copy"><p class="eyebrow"><span></span> PASTEL CRT · LOCAL ONLY</p>
        <h1>把喜歡的畫面，<br>收進<span class="serif-accent">映像管。</span><span class="title-spark">✿</span></h1>
        <p class="hero-description">本機私人映像目錄，柔柔粉光、輕輕掃描線。<br>不上傳、不外連——只陪你慢慢重溫那些心動瞬間。</p>
        <button class="button primary" data-open="create-dialog"><span>＋</span> 新增映像 <span>↗</span></button>
        <div class="hero-footnote"><span class="tiny-line"></span> A DREAMY LITTLE ARCHIVE FOR YOU</div>
    </div>
    <div class="hero-art" aria-hidden="true"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><span class="art-spark">✿</span><span class="art-label">SOFT GIRL TUBE</span>
        <div class="art-frame back-frame"></div><div class="art-frame front-frame"><div class="art-landscape"><div class="sun"></div><div class="hill hill-back"></div><div class="hill hill-front"></div><span class="art-play">▶</span><span class="frame-rec"><i></i> ON AIR · DREAM MODE</span><span class="frame-corner">01 / ♡</span></div><div class="frame-caption"><span>螢光粉，輕輕閃。</span><span>↗</span></div></div>
        <div class="art-sticker">Keep it soft.<br><em>Play it cute.</em></div>
    </div>
</section>
<section class="library" aria-labelledby="library-title">
    <div class="library-heading"><div><p class="eyebrow">THE COLLECTION</p><h2 id="library-title">我的映像庫 <span class="count">{{ $total }}</span></h2></div>
        <form class="search" method="get" action="{{ route('video-journal.index') }}"><span aria-hidden="true">⌕</span><input aria-label="搜尋標題或標籤" name="q" value="{{ $search }}" placeholder="搜尋標題或標籤…" maxlength="200">@if($search)<a href="{{ route('video-journal.index') }}" aria-label="清除搜尋">×</a>@endif<button type="submit">搜尋</button></form>
    </div>
    <div class="collection-meta"><span>{{ $search ? '搜尋結果 · '.$entries->total().' 則' : '全部收藏 · '.$total.' 則甜蜜映像' }}</span><span>最近編輯 <span aria-hidden="true">↓</span></span></div>
    @if($entries->count())
    <div class="entry-grid">
        @foreach($entries as $entry)
        <article class="entry-card palette-{{ $entry->id % 4 }}">
            <a class="card-art {{ $entry->cover_count ? 'has-cover' : '' }}" href="{{ route('video-journal.show', $entry->id) }}" aria-label="開啟 {{ $entry->title }}">@if($entry->cover_count)<span class="card-covers" style="--cover-count: {{ $entry->cover_count }}">@for($position = 0; $position < $entry->cover_count; $position++)<img class="card-cover" src="{{ route('video-journal.cover', [$entry->id, $position]) }}" alt="{{ $entry->title }} 的封面 {{ $position + 1 }}" loading="lazy" decoding="async">@endfor</span>@else<span class="card-orbit"></span><span class="card-number">TUBE {{ str_pad($entry->id, 3, '0', STR_PAD_LEFT) }}</span><span class="card-caption">A SOFT FRAME TO KEEP</span>@endif<span class="card-play">↗</span></a>
            <div class="card-body"><div class="card-identity"><div><span class="card-date">{{ $entry->updated_at->format('Y.m.d') }} <span>・ 映像小卡</span></span><h3><a href="{{ route('video-journal.show', $entry->id) }}">{{ $entry->title }}</a></h3></div>@if($entry->has_portrait)<a href="{{ route('video-journal.show', $entry->id) }}" class="card-portrait"><img src="{{ route('video-journal.portrait', [$entry->id, 0]) }}" alt="{{ $entry->title }} 的大頭照" loading="lazy" decoding="async"></a>@endif</div>
            @if($entry->tags)<div class="tag-list card-tags">@foreach($entry->tags as $tag)<a class="tag-chip" href="{{ route('video-journal.index', ['q' => $tag]) }}"># {{ $tag }}</a>@endforeach</div>@endif
            <div class="card-bottom"><a href="{{ route('video-journal.show', $entry->id) }}">打開看看 <span>↗</span></a><button type="button" class="delete-trigger" data-delete-url="{{ route('video-journal.destroy', $entry->id) }}" data-delete-title="{{ $entry->title }}" aria-label="刪除 {{ $entry->title }}">刪除</button></div></div>
        </article>
        @endforeach
    </div>
    @if($entries->hasPages())<nav class="pagination" aria-label="分頁">@if($entries->previousPageUrl())<a class="button secondary" href="{{ $entries->previousPageUrl() }}">← 上一頁</a>@endif<span>{{ $entries->currentPage() }} / {{ $entries->lastPage() }}</span>@if($entries->nextPageUrl())<a class="button secondary" href="{{ $entries->nextPageUrl() }}">下一頁 →</a>@endif</nav>@endif
    @else
    <div class="empty-state"><div class="empty-symbol" aria-hidden="true">▤<span>＋</span></div><p class="eyebrow">{{ $search ? 'KEEP LOOKING' : 'THE TUBE IS WAITING' }}</p><h3>{{ $search ? '還沒找到這段映像' : '第一則映像，從這裡開始' }}</h3><p>{{ $search ? '換個關鍵字，再輕輕找一次吧。' : '拖入本機影片，寫下小心情，讓粉紅映像管開始發亮。' }}</p>@if($search)<a class="text-link" href="{{ route('video-journal.index') }}">查看全部映像 ↗</a>@else<button class="text-link" data-open="create-dialog">新增我的第一則映像 <span>↗</span></button>@endif</div>
    @endif
</section>
<dialog id="create-dialog" @if($errors->any()) data-reopen @endif><form action="{{ route('video-journal.store') }}" method="post">@csrf
    <div class="dialog-top"><span class="eyebrow">A NEW FRAME</span><button class="icon-button" type="button" data-close aria-label="關閉">×</button></div><h2>收進一則新映像<span class="coral">。</span></h2><p class="muted">從本機影片開始，溫柔地放進你的映像管。</p>
    @if($errors->any())<div class="error-box" role="alert">{{ $errors->first() }}</div>@endif
    <div id="video-dropzone" class="video-dropzone" tabindex="0" role="button" aria-label="拖入多部影片或開啟影片選擇" data-resolve-url="{{ route('video-journal.resolve-drop') }}" data-batch-url="{{ route('video-journal.batch') }}"><span class="drop-icon" aria-hidden="true">⇩</span><strong>把影片拖曳到這裡</strong><span>或點選多部影片，最多 50 部 · 不上傳、不複製</span></div><input id="dropped-files" type="file" accept=".mp4,.webm,.ogv,.mov,.m4v" multiple hidden><p id="video-drop-status" class="field-help" role="status" aria-live="polite"></p><div id="drop-matches" class="drop-matches"></div>
    <div id="drop-folder-controls" hidden><label class="field-label" for="drop-folder">影片所在資料夾</label><div class="source-picker"><input id="drop-folder" class="field" placeholder="貼上資料夾完整路徑，例如 D:\Movies" aria-describedby="drop-folder-help"><button type="button" id="confirm-drop-folder" class="button secondary">確認位置</button></div><p id="drop-folder-help" class="field-help">從檔案總管複製上方位址列的資料夾路徑貼到這裡，同一資料夾的影片會一起確認。也可以點清單中的「確認資料夾」瀏覽磁碟。影片會保留在原位置。</p></div>
    <label class="field-label" for="new-source">影片來源</label><div class="source-picker"><input class="field" id="new-source" name="source" placeholder="尚未選擇影片" value="{{ old('source') }}" readonly><button class="button secondary" id="choose-video" type="button" autofocus>▧ 選擇影片</button></div><p class="field-help">選擇本機或已連接磁碟中的影片，同名 .srt 字幕會自動載入。<br>影片保留在原位置，不會被複製或上傳。</p>
    <label class="field-label" for="new-title">映像標題</label><input class="field" id="new-title" name="title" placeholder="選擇影片後，自動使用原始檔名" value="{{ old('title') }}" maxlength="200"><p class="field-help">預設使用原始檔案名稱，之後仍可編輯。</p>
    <div class="dialog-actions"><button class="button secondary" type="button" data-close>取消</button><button class="button primary" type="submit">建立映像 <span>↗</span></button></div>
</form></dialog>
<dialog id="video-picker" data-browse-url="{{ route('video-journal.browse') }}" aria-labelledby="picker-title"><div class="dialog-top"><span class="eyebrow">PICK A FRAME</span><button class="icon-button" type="button" data-close aria-label="關閉影片選擇">×</button></div><h2 id="picker-title">選擇影片<span class="coral">。</span></h2><p class="muted">開啟資料夾，點選要收進映像管的影片。</p><form id="picker-location" class="picker-location"><input id="picker-path" class="field" aria-label="資料夾位置" placeholder="磁碟與資料夾位置，例如 D:\Movies"><button class="button secondary small" type="submit">前往</button></form><div class="picker-controls"><button type="button" id="picker-roots">所有磁碟</button><button type="button" id="picker-parent">↑ 上一層</button><input class="field" id="picker-search" aria-label="篩選檔名" placeholder="篩選此資料夾的檔名" maxlength="200"></div><p id="picker-status" class="muted" role="status"></p><div id="picker-items" class="picker-items" aria-label="資料夾與影片"></div></dialog>
<dialog id="delete-dialog"><form method="post" id="delete-form">@csrf @method('DELETE')<div class="dialog-top"><span class="eyebrow">PUT AWAY</span><button class="icon-button" type="button" data-close aria-label="關閉">×</button></div><h2>從映像管拿掉這則？</h2><p id="delete-title" class="delete-title"></p><p class="muted">標題與圖文會刪除，原始影片和字幕檔案會好好留在原位。</p><div class="dialog-actions"><button class="button secondary" type="button" data-close>先留著</button><button class="button danger" type="submit">確認刪除</button></div></form></dialog>
@endsection
