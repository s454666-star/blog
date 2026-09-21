@extends('video-journal.layout')
@section('title', '我的影片誌')
@section('content')
<section class="hero">
    <div class="hero-copy"><p class="eyebrow"><span></span> YOUR LIFE, FRAME BY FRAME</p>
        <h1>讓每一段映像，<br>都有<span class="serif-accent">故事。</span><span class="title-spark">✳</span></h1>
        <p class="hero-description">收藏喜歡的片刻，寫下當時的心情。<br>在影像與文字之間，留下屬於你的生活切片。</p>
        <button class="button primary" data-open="create-dialog"><span>＋</span> 新增影片 <span>↗</span></button>
        <div class="hero-footnote"><span class="tiny-line"></span> A LITTLE SPACE FOR YOUR BIG MOMENTS</div>
    </div>
    <div class="hero-art" aria-hidden="true"><div class="orbit orbit-one"></div><div class="orbit orbit-two"></div><span class="art-spark">✳</span><span class="art-label">THE EVERYDAY ARCHIVE</span>
        <div class="art-frame back-frame"></div><div class="art-frame front-frame"><div class="art-landscape"><div class="sun"></div><div class="hill hill-back"></div><div class="hill hill-front"></div><span class="art-play">▶</span><span class="frame-rec"><i></i> MOMENTS IN MOTION</span><span class="frame-corner">01 / ∞</span></div><div class="frame-caption"><span>日常，就是最好的電影。</span><span>↗</span></div></div>
        <div class="art-sticker">Keep the moment.<br><em>Tell the story.</em></div>
    </div>
</section>
<section class="library" aria-labelledby="library-title">
    <div class="library-heading"><div><p class="eyebrow">THE COLLECTION</p><h2 id="library-title">我的影片誌 <span class="count">{{ $total }}</span></h2></div>
        <form class="search" method="get" action="{{ route('video-journal.index') }}"><span aria-hidden="true">⌕</span><input aria-label="搜尋影片標題" name="q" value="{{ $search }}" placeholder="尋找某個片刻…" maxlength="200">@if($search)<a href="{{ route('video-journal.index') }}" aria-label="清除搜尋">×</a>@endif<button type="submit">搜尋</button></form>
    </div>
    <div class="collection-meta"><span>{{ $search ? '搜尋結果 · '.$entries->total().' 篇' : '所有收藏 · '.$total.' 篇故事' }}</span><span>最近編輯 <span aria-hidden="true">↓</span></span></div>
    @if($entries->count())
    <div class="entry-grid">
        @foreach($entries as $entry)
        <article class="entry-card palette-{{ $entry->id % 4 }}">
            <a class="card-art" href="{{ route('video-journal.show', $entry->id) }}" aria-label="開啟 {{ $entry->title }}"><span class="card-orbit"></span><span class="card-number">FRAME {{ str_pad($entry->id, 3, '0', STR_PAD_LEFT) }}</span><span class="card-play">↗</span><span class="card-caption">A STORY WORTH KEEPING</span></a>
            <div class="card-body"><span class="card-date">{{ $entry->updated_at->format('Y.m.d') }} <span>・ 影片手記</span></span><h3><a href="{{ route('video-journal.show', $entry->id) }}">{{ $entry->title }}</a></h3><div class="card-bottom"><a href="{{ route('video-journal.show', $entry->id) }}">閱讀故事 <span>↗</span></a><button type="button" class="delete-trigger" data-delete-url="{{ route('video-journal.destroy', $entry->id) }}" data-delete-title="{{ $entry->title }}" aria-label="刪除 {{ $entry->title }}">刪除</button></div></div>
        </article>
        @endforeach
    </div>
    @if($entries->hasPages())<nav class="pagination" aria-label="分頁">@if($entries->previousPageUrl())<a class="button secondary" href="{{ $entries->previousPageUrl() }}">← 上一頁</a>@endif<span>{{ $entries->currentPage() }} / {{ $entries->lastPage() }}</span>@if($entries->nextPageUrl())<a class="button secondary" href="{{ $entries->nextPageUrl() }}">下一頁 →</a>@endif</nav>@endif
    @else
    <div class="empty-state"><div class="empty-symbol" aria-hidden="true">▤<span>＋</span></div><p class="eyebrow">{{ $search ? 'KEEP EXPLORING' : 'EVERY STORY STARTS SOMEWHERE' }}</p><h3>{{ $search ? '還沒找到這個片刻' : '第一篇故事，從這裡開始' }}</h3><p>{{ $search ? '試試其他標題關鍵字，重新尋找你的收藏。' : '加入一段影片，再用文字和圖片，為回憶多留一點溫度。' }}</p>@if($search)<a class="text-link" href="{{ route('video-journal.index') }}">查看所有影片 ↗</a>@else<button class="text-link" data-open="create-dialog">新增我的第一段影片 <span>↗</span></button>@endif</div>
    @endif
</section>
<dialog id="create-dialog" @if($errors->any()) data-reopen @endif><form action="{{ route('video-journal.store') }}" method="post">@csrf
    <div class="dialog-top"><span class="eyebrow">A NEW CHAPTER</span><button class="icon-button" type="button" data-close aria-label="關閉">×</button></div><h2>加入一個新故事<span class="coral">。</span></h2><p class="muted">從一段影片開始，把片刻變成收藏。</p>
    @if($errors->any())<div class="error-box" role="alert">{{ $errors->first() }}</div>@endif
    <label class="field-label" for="new-source">影片來源</label><div class="source-picker"><input class="field" id="new-source" name="source" placeholder="尚未選擇影片" value="{{ old('source') }}" readonly required><button class="button secondary" id="choose-video" type="button" autofocus>▧ 選擇影片</button></div><p class="field-help">選擇本機或已連接磁碟中的影片，同名 .srt 字幕會自動載入。<br>影片保留在原位置，不會被複製或上傳。</p>
    <label class="field-label" for="new-title">影片標題</label><input class="field" id="new-title" name="title" placeholder="選擇影片後，自動使用原始檔名" value="{{ old('title') }}" maxlength="200"><p class="field-help">預設使用原始檔案名稱，之後仍可編輯。</p>
    <div class="dialog-actions"><button class="button secondary" type="button" data-close>取消</button><button class="button primary" type="submit">建立影片誌 <span>↗</span></button></div>
</form></dialog>
<dialog id="video-picker" data-browse-url="{{ route('video-journal.browse') }}" aria-labelledby="picker-title"><div class="dialog-top"><span class="eyebrow">CHOOSE A MOMENT</span><button class="icon-button" type="button" data-close aria-label="關閉影片選擇">×</button></div><h2 id="picker-title">選擇影片<span class="coral">。</span></h2><p class="muted">開啟資料夾，點選要加入的影片。</p><form id="picker-location" class="picker-location"><input id="picker-path" class="field" aria-label="資料夾位置" placeholder="磁碟與資料夾位置，例如 D:\Movies"><button class="button secondary small" type="submit">前往</button></form><div class="picker-controls"><button type="button" id="picker-roots">所有磁碟</button><button type="button" id="picker-parent">↑ 上一層</button><input class="field" id="picker-search" aria-label="篩選檔名" placeholder="篩選此資料夾的檔名" maxlength="200"></div><p id="picker-status" class="muted" role="status"></p><div id="picker-items" class="picker-items" aria-label="資料夾與影片"></div></dialog>
<dialog id="delete-dialog"><form method="post" id="delete-form">@csrf @method('DELETE')<div class="dialog-top"><span class="eyebrow">REMOVE A STORY</span><button class="icon-button" type="button" data-close aria-label="關閉">×</button></div><h2>刪除這篇影片誌？</h2><p id="delete-title" class="delete-title"></p><p class="muted">標題與圖文內容將被刪除，原始影片和字幕檔案會保留。</p><div class="dialog-actions"><button class="button secondary" type="button" data-close>保留文章</button><button class="button danger" type="submit">確認刪除</button></div></form></dialog>
@endsection
