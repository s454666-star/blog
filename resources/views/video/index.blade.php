<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>影片列表</title>

    <!-- ===== 依賴 ===== -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">

    <!-- ===== 樣式 (依功能分區) ===== -->
    <style>

        :root{ --video-width:70%; }

        /* === 影片列 ================================================= */
        .video-row{display:flex;margin-bottom:20px;border:1px solid #ddd;padding:10px;border-radius:5px;cursor:pointer;user-select:none;transition:background-color .3s,border-color .3s;position:relative;box-sizing:border-box}
        .video-row.selected{border-color:#007bff;background:#e7f1ff}
        .video-row.focused{border-color:#28a745;background:#e6ffe6}

        /* === 影片標題動畫 =========================================== */
        .video-title{font-size:1.1rem;font-weight:700;margin-bottom:6px;overflow:hidden;position:relative;
            background:linear-gradient(90deg,#007bff 0%,#00d4ff 50%,#007bff 100%);
            background-size:200% auto;-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;color:transparent;
            animation:shine 3s linear infinite,slideIn .6s cubic-bezier(.25,.8,.25,1) forwards;
            opacity:0;transform:translateY(-10px);}
        @keyframes shine{to{background-position:-200% center;}}
        @keyframes slideIn{to{opacity:1;transform:translateY(0);}}
        .video-path{font-size:.85em;color:#666;font-weight:400;}

        /* 讓路徑字體稍微小一點、變灰色，不跟著流光 */
        .video-path{
            font-size:.85em;
            color:#666;
            font-weight:400;
        }

        /* === 小節標題美化：漸層滑動底線 + 微動畫 ===================== */
        .screenshot-images h5,
        .face-screenshot-images h5{
            position:relative;
            margin-bottom:8px;
            font-size:1rem;
            font-weight:700;
            letter-spacing:1px;
            color:#333;
        }
        .screenshot-images h5::after,
        .face-screenshot-images h5::after{
            content:'';
            position:absolute;
            left:0;
            bottom:-2px;
            width:100%;
            height:3px;
            background:linear-gradient(90deg,#007bff 0%,#00d4ff 100%);
            border-radius:2px;

            /* 底線左右流動動畫 */
            background-size:200% auto;
            animation:slideBar 3s linear infinite;
        }

        @keyframes slideBar{
            0%  {background-position:0   0;}
            100%{background-position:-200% 0;}
        }

        /* === 上傳提示美化：藍色字 + 左側雲端上傳圖示 =============== */
        .upload-instructions{
            font-size:.9rem;
            font-weight:600;
            color:#007bff;
            letter-spacing:.5px;
            position:relative;
            padding-left:26px;          /* 預留圖示空間 */
        }

        /* 加入 SVG 圖示（純 CSS，不需額外檔案） */
        .upload-instructions::before{
            content:'';
            position:absolute;
            left:3px;
            top:50%;
            width:18px;
            height:18px;
            transform:translateY(-50%);
            background:url('data:image/svg+xml;utf8,\
<svg xmlns="http://www.w3.org/2000/svg" viewBox=\"0 0 24 24\" fill=\"%23007bff\">\
<path d=\"M12 16v-6m0 0l-3 3m3-3l3 3m6 1v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4m16-4h-3.586a1 1 0 01-.707-.293l-3.414-3.414a2 2 0 00-2.828 0L6.707 11.707a1 1 0 01-.707.293H4\"/>\
</svg>') center/18px 18px no-repeat;
            opacity:.85;
        }

        /* === 主面人臉縮圖：常態柔光 + Hover 漸層光環 =================== */
        .master-face-img{
            position:relative;
            border-radius:6px;
            overflow:hidden;
        }

        /* 1. 常態：薄白框（用內凹 box‑shadow） */
        .master-face-img::after{
            content:'';
            position:absolute;
            inset:0;
            border-radius:inherit;
            box-shadow:0 0 0 2px rgba(255,255,255,.35) inset;
            transition:opacity .4s;
            pointer-events:none;
            z-index:1;                       /* 避免被下方 :before 蓋掉 */
        }

        /* 2. Hover：漸層流動光環（放在 :before，避免蓋到白框） */
        .master-face-img::before{
            content:'';
            position:absolute;
            inset:-2px;                      /* 稍微蓋出邊界，光環更顯眼 */
            border-radius:inherit;
            background:linear-gradient(135deg,#00d4ff 0%,#007bff 50%,#00d4ff 100%);
            background-size:300% 300%;
            opacity:0;
            transition:opacity .4s;
            pointer-events:none;
            z-index:0;
        }

        /* 啟動畫面：滑入才點亮並流動 */
        .master-face-img:hover::before{
            opacity:1;
            animation:borderFlow 3s linear infinite;
        }

        /* 已聚焦（.focused）的永遠保持亮光 */
        .master-face-img.focused::before{
            opacity:1;
            animation:borderFlow 3s linear infinite;
        }

        /* 流動關鍵影格 */
        @keyframes borderFlow{
            0%  {background-position:0% 50%;}
            100%{background-position:200% 50%;}
        }

        /* === 影片與截圖容器 === */
        .video-container{width:var(--video-width);padding-right:10px}
        .images-container{width:calc(100% - var(--video-width));padding-left:10px;overflow:hidden}
        .screenshot,.face-screenshot{width:100px;height:56px;object-fit:cover;margin:5px;transition:transform .3s,border .3s,box-shadow .3s}
        .face-screenshot.master{border:3px solid #f00}

        /* === 新增：截圖清單捲軸 === */
        .screenshot-images .d-flex,
        .face-screenshot-images .d-flex{max-height:250px;overflow-y:auto;overflow-x:hidden}

        /* === 放大圖片 (hover) === */
        .image-modal{display:none;position:fixed;z-index:2000;inset:0;overflow:hidden;background:rgba(0,0,0,.8);justify-content:center;align-items:center;pointer-events:none}
        .image-modal img{max-width:90%;max-height:90%;border:5px solid #fff;border-radius:5px;pointer-events:none}
        .image-modal.active{display:flex}

        /* === 底部控制列 === */
        .controls{position:fixed;bottom:0;left:30%;right:0;background:#fff;padding:20px 30px;border-top:1px solid #ddd;box-shadow:0 -2px 5px rgba(0,0,0,.1);z-index:1000;display:flex;align-items:center;flex-wrap:wrap}
        .controls .control-group{margin-right:30px;display:flex;align-items:center;margin-bottom:10px;flex-grow:1}
        .controls label{margin-right:10px;font-weight:700;white-space:nowrap}
        #play-mode{width:50px;height:10px}

        /* === 上傳框 === */
        .upload-area{border:2px dashed #007bff;border-radius:5px;padding:30px;text-align:center;color:#aaa;transition:.3s;margin-bottom:20px}
        .upload-area.dragover{background:#f0f8ff;border-color:#0056b3;color:#0056b3}

        /* === 主面人臉側欄 === */
        .master-faces{position:fixed;top:0;left:0;width:30%;height:100%;overflow-y:auto;background:#f8f9fa;border-right:1px solid #ddd;padding:10px;box-sizing:border-box;z-index:100;display:flex;flex-direction:column;align-items:center}
        .master-faces h5{text-align:center;width:100%}
        .master-face-images{display:grid;grid-template-columns:repeat(4,1fr);grid-auto-rows:1fr;gap:10px;width:100%}
        .master-face-img{width:100%;height:auto;aspect-ratio:1/1;object-fit:cover;cursor:pointer;border:2px solid transparent;border-radius:5px;transition:border-color .3s,box-shadow .3s,transform .3s}
        .master-face-img.landscape{grid-column:span 2;aspect-ratio:2/1}
        .master-face-img:hover{border-color:#007bff;transform:scale(1.05)}
        .master-face-img.focused{border-color:#28a745;box-shadow:0 0 15px rgba(40,167,69,.7);transform:scale(1.1)}

        /* === 主要內容區 === */
        .container{margin-left:30%;padding-top:20px;padding-bottom:80px}

        /* === 快訊訊息 === */
        .message-container{position:fixed;top:20px;right:20px;z-index:3000}
        .message{padding:10px 20px;border-radius:5px;margin-bottom:10px;color:#fff;opacity:.9;animation:fadeOut 1s forwards}
        .message.success{background:#28a745}
        .message.error{background:#dc3545}
        @keyframes fadeOut{0%{opacity:.9}100%{opacity:0}}

        /* === 刪除與設為主面按鈕 === */
        .delete-icon,.set-master-btn{position:absolute;top:5px;right:5px;background:rgba(220,53,69,.8);color:#fff;border:none;border-radius:50%;width:20px;height:20px;text-align:center;line-height:18px;cursor:pointer;display:none;font-size:14px;padding:0}
        .set-master-btn{right:30px;background:rgba(40,167,69,.8)}
        .screenshot-container,.face-screenshot-container{position:relative;display:inline-block}
        .screenshot-container:hover .delete-icon,.face-screenshot-container:hover .delete-icon,.face-screenshot-container:hover .set-master-btn{display:block}

        /* === 全螢幕模式切換 === */
        .fullscreen-mode .controls,.fullscreen-mode .master-faces,.fullscreen-mode .container{display:none}
        .fullscreen-controls{position:fixed;inset:0;z-index:2000;display:none}
        .fullscreen-controls.show{display:block}
        .fullscreen-controls .prev-video-btn,.fullscreen-controls .next-video-btn{position:absolute;top:50%;transform:translateY(-50%);background:rgba(0,0,0,.5);border:none;color:#fff;padding:20px;font-size:24px;border-radius:50%;cursor:pointer;opacity:0;transition:opacity .3s}
        .fullscreen-controls .prev-video-btn{left:20px}
        .fullscreen-controls .next-video-btn{right:20px}
        .fullscreen-controls .prev-video-btn.show,.fullscreen-controls .next-video-btn.show{opacity:1}

        /* === RWD 調整 === */
        @media(max-width:768px){
            .video-container,.images-container{width:100%;padding:0}
            .controls{left:0;flex-direction:column;align-items:flex-start}
            .controls .control-group{margin-right:0;margin-bottom:10px}
            .master-faces{width:100%;height:auto;position:relative;border-right:none;border-bottom:1px solid #ddd}
            .container{margin-left:0}
            .master-face-images{grid-template-columns:repeat(4,1fr)}
        }

        /* === Bootstrap Container 寬度上限調整 (大螢幕) === */
        @media(min-width:1200px){.container,.container-lg,.container-md,.container-sm,.container-xl{max-width:1750px}}
    </style>
</head>
<body>

<!-- ===== 主面人臉側欄 ===== -->
<div class="master-faces">
    <h5>主面人臉</h5>
    <div class="master-face-images">
        @foreach($masterFaces as $mf)
            @php
                $imgPath = public_path($mf->face_image_path);
                $orientation = '';
                if (file_exists($imgPath)) {
                    [$w,$h] = getimagesize($imgPath);
                    if ($w >= $h) $orientation = 'landscape';
                }
            @endphp
            <img src="{{ config('app.video_base_url') }}/{{ $mf->face_image_path }}"
                 class="master-face-img {{ $orientation }}"
                 alt="主面人臉"
                 data-video-id="{{ $mf->videoScreenshot->videoMaster->id }}"
                 data-duration="{{ $mf->videoScreenshot->videoMaster->duration }}">
        @endforeach
    </div>
</div>

<!-- ===== 內容區 ===== -->
<div class="container mt-4">
    <div id="message-container" class="message-container"></div>

    <div id="videos-list">
        @include('video.partials.video_rows',['videos'=>$videos])
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
    <form id="controls-form" class="d-flex flex-wrap w-100">
        <div class="control-group">
            <label for="video-size">影片大小:</label>
            <input id="video-size" type="range" name="video_size" min="10" max="50" value="{{ request('video_size',25) }}">
        </div>
        <div class="control-group">
            <label for="image-size">截圖大小:</label>
            <input id="image-size" type="range" name="image_size" min="100" max="300" value="{{ request('image_size',200) }}">
        </div>
        <div class="control-group">
            <label for="video-type">影片類別:</label>
            <select id="video-type" name="video_type" class="form-control">
                @for($i=1;$i<=4;$i++)
                    <option value="{{ $i }}" {{ request('video_type','1')==$i? 'selected':'' }}>{{ $i }}</option>
                @endfor
            </select>
        </div>
        <div class="control-group">
            <label for="play-mode">播放模式:</label>
            <input id="play-mode" type="range" name="play_mode" min="0" max="1" value="{{ request('play_mode','0') }}" step="1">
            <span id="play-mode-label"></span>
        </div>
        {{-- 排序依據 --}}
        <div class="control-group">
            <label for="sort-by">排序方式：</label>
            <select id="sort-by" name="sort_by" class="form-control">
                <option value="duration" {{ $sortBy==='duration' ? 'selected':'' }}>依時長</option>
                <option value="id"       {{ $sortBy==='id'       ? 'selected':'' }}>依先後</option>
            </select>
        </div>
        {{-- 排序方向 --}}
        <div class="control-group">
            <label for="sort-dir">排序方向：</label>
            <select id="sort-dir" name="sort_dir" class="form-control">
                <option value="asc"  {{ $sortDir==='asc'  ? 'selected':'' }}>由小到大</option>
                <option value="desc" {{ $sortDir==='desc' ? 'selected':'' }}>由大到小</option>
            </select>
        </div>
        <div class="control-group">
            <button id="delete-focused-btn" class="btn btn-warning" type="button">刪除聚焦的影片</button>
        </div>
    </form>
</div>

<!-- ===== Blade 模板 (影片列 / 截圖 / 人臉截圖) ===== -->
<template id="video-row-template">
    <div class="video-row" data-id="{id}" data-duration="{duration}">
        <div class="video-container">
            <div class="video-wrapper">
                <div class="video-title">
                    @{{video_name}}
                    <span class="video-path">(@{{video_path}})</span>
                </div>
                <video width="100%" controls>
                    <source src="{{ config('app.video_base_url') }}/{video_path}" type="video/mp4">
                    您的瀏覽器不支援影片播放。
                </video>
                <button class="fullscreen-btn">⤢</button>
            </div>
        </div>
        <div class="images-container">
            <div class="screenshot-images mb-2">
                <h5>影片截圖</h5>
                <div class="d-flex flex-wrap">{screenshot_images}</div>
            </div>
            <div class="face-screenshot-images">
                <h5>人臉截圖</h5>
                <div class="d-flex flex-wrap face-upload-area" data-video-id="{video_id}" style="position:relative;border:2px dashed #007bff;border-radius:5px;padding:10px;min-height:120px;">
                    {face_screenshot_images}
                    <div class="upload-instructions" style="width:100%;text-align:center;color:#aaa;margin-top:10px;">拖曳圖片到此處上傳</div>
                </div>
            </div>
        </div>
    </div>
</template>

<template id="screenshot-template">
    <div class="screenshot-container">
        <img src="{{ config('app.video_base_url') }}/{{ '{screenshot_path}' }}" class="screenshot hover-zoom" alt="截圖" data-id="{{ '{screenshot_id}' }}" data-type="screenshot">
        <button class="delete-icon" data-id="{{ '{screenshot_id}' }}" data-type="screenshot">&times;</button>
    </div>
</template>

<template id="face-screenshot-template">
    <div class="face-screenshot-container">
        <img src="{{ config('app.video_base_url') }}/{{ '{face_image_path}' }}" class="face-screenshot hover-zoom {master_class}" alt="人臉截圖" data-id="{{ '{face_id}' }}" data-video-id="{{ '{video_id}' }}" data-type="face-screenshot">
        <button class="set-master-btn" data-id="{{ '{face_id}' }}" data-video-id="{{ '{video_id}' }}">★</button>
        <button class="delete-icon" data-id="{{ '{face_id}' }}" data-type="face-screenshot">&times;</button>
    </div>
</template>

<!-- ===== 放大圖片容器 ===== -->
<div id="image-modal" class="image-modal"><img src="" alt="放大圖片"></div>

<!-- ===== JS 依賴 ===== -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>

<!-- ===== 主腳本 ===== -->
<script>
    /* --------------------------------------------------
     * 全域變數
     * -------------------------------------------------- */
    let lastPage       = {{ $lastPage ?? 1 }};
    let loadedPages    = [{{ $videos->currentPage() }}];
    let nextPage       = {{ $next_page ?? 'null' }};
    let prevPage       = {{ $prev_page ?? 'null' }};
    let loading        = false;

    let videoList      = [];
    let currentVideoIndex = 0;
    let playMode       = {{ request('play_mode') ? '1' : '0' }};
    let currentFSVideo = null;

    let videoSize      = {{ request('video_size',25) }};
    let imageSize      = {{ request('image_size',200) }};
    let videoType      = '{{ request('video_type','1') }}';
    let focusId = {{ $focusId ?? 'null' }};

    $('#video-type, #sort-by, #sort-dir').on('change', function(){
        $('#controls-form').submit();
    });

    function refreshMasterFaces() {
        $.get("{{ route('video.loadMasterFaces') }}", {
            video_type: $('#video-type').val(),
            sort_by:    $('#sort-by').val(),
            sort_dir:   $('#sort-dir').val()
        }, res => {
            if (!res.success) return;
            const $wrap = $('.master-face-images').empty();
            res.data.forEach(face => {
                $wrap.append(
                    `<img src="${face.path}"
              class="master-face-img"
              data-video-id="${face.video_id}"
              data-duration="${face.duration}">`
                );
            });
        });
    }

    // 頁面載入時＋每次排序選項改變時都重整
    $(function(){
        refreshMasterFaces();
        $('#sort-by,#sort-dir,#video-type').on('change', refreshMasterFaces);
    });

    /* --------------------------------------------------
     * 快訊訊息
     * -------------------------------------------------- */
    function showMessage(type, text){
        const $mc = $('#message-container');
        const $msg = $('<div class="message"></div>')
            .addClass(type === 'success' ? 'success' : 'error')
            .text(text);
        $mc.append($msg);
        setTimeout(()=>{$msg.fadeOut(500,()=>{$msg.remove();});},1000);
    }

    /* --------------------------------------------------
     * 分頁載入 / 排序
     * -------------------------------------------------- */
    function recalcPages(){
        const min = Math.min.apply(null, loadedPages);
        const max = Math.max.apply(null, loadedPages);
        prevPage = min > 1       ? (min - 1) : null;
        nextPage = max < lastPage? (max + 1) : null;
    }

    function loadMoreVideos(dir='down', target=null){
        if(loading) return;
        if(!target){
            if(dir==='down' && !nextPage) return;
            if(dir==='up'   && !prevPage) return;
        }
        loading = true;
        $('#load-more').show();

        const data = { video_type: videoType };
        data.page = target ?? (dir==='down' ? nextPage : prevPage);

        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data,
            success(res){
                if(res && res.success && res.data.trim()){
                    const $temp = $('<div>').html(res.data);
                    dir==='down'
                        ? $('#videos-list').append($temp.children())
                        : $('#videos-list').prepend($temp.children());

                    if(!loadedPages.includes(res.current_page))
                        loadedPages.push(res.current_page);
                    lastPage = res.last_page || lastPage;
                    rebuildAndSort();
                }else{
                    if(!target){
                        dir==='down'? nextPage=null : prevPage=null;
                    }
                    $('#load-more').html('<p>沒有更多資料了。</p>');
                }
                loading=false;$('#load-more').hide();
            },
            error(){
                showMessage('error','載入失敗，請稍後再試。');
                loading=false;$('#load-more').hide();
            }
        });
    }

    function loadPageAndFocus(videoId, page){
        if(!page){showMessage('error','找不到該影片所在的頁面。');return;}
        loading=true;$('#load-more').show();
        $.ajax({
            url: "{{ route('video.loadMore') }}",
            method: 'GET',
            data:{page,video_type:videoType},
            success(res){
                if(res && res.success && res.data.trim()){
                    const $temp=$('<div>').html(res.data);
                    $('#videos-list').append($temp.children());
                    if(!loadedPages.includes(res.current_page))
                        loadedPages.push(res.current_page);
                    lastPage=res.last_page||lastPage;
                    rebuildAndSort();
                    const $target=$('.video-row[data-id="'+videoId+'"]');
                    if($target.length){
                        $('.video-row').removeClass('focused');
                        $target.addClass('focused');
                        focusMasterFace(videoId);
                        $target[0].scrollIntoView({behavior:'smooth',block:'center'});
                    }
                }else{
                    showMessage('error','無法載入該頁資料。');
                }
                loading=false;$('#load-more').hide();
            },
            error(){
                showMessage('error','載入失敗，請稍後再試。');
                loading=false;$('#load-more').hide();
            }
        });
    }

    function rebuildAndSort(){
        const rows = $('.video-row').get().sort((a,b)=>+$(a).data('duration')-+$(b).data('duration'));
        $('#videos-list').empty().append(rows);
        buildVideoList();
        applySizes();
        recalcPages();
        const $f=$('.video-row.focused').first();
        if($f.length)setTimeout(()=>{$f[0].scrollIntoView({behavior:'smooth',block:'center'});},0);
        watchFocusedRow();
    }

    /* --------------------------------------------------
     * 影片列表 / 尺寸
     * -------------------------------------------------- */
    function buildVideoList(){
        videoList=[];
        $('.video-row').each(function(){
            videoList.push({
                id:$(this).data('id'),
                video:$(this).find('video')[0],
                row:$(this)
            });
        });
    }
    function applySizes(){
        $('.video-container').css('width',videoSize+'%');
        $('.images-container').css('width',(100-videoSize)+'%');
        $('.screenshot,.face-screenshot').css({
            width:imageSize+'px',
            height:(imageSize*0.56)+'px'
        });
    }

    /* --------------------------------------------------
     * 主面人臉同步
     * -------------------------------------------------- */
    function focusMasterFace(id){
        $('.master-face-img').removeClass('focused');
        const $t = $(`.master-face-img[data-video-id="${id}"]`).addClass('focused');
        if(!$t.length) return;
        const c = document.querySelector('.master-faces');
        c.scrollTo({
            top: $t[0].offsetTop - c.clientHeight/2 + $t[0].clientHeight/2,
            behavior: 'smooth'
        });
    }

    /* --------------------------------------------------
     * 全螢幕播放
     * -------------------------------------------------- */
    function enterFullScreen(video){
        /* ------- 全螢幕時一律循環 ------- */
        video.loop = true;                 // JS 屬性
        video.setAttribute('loop', '');    // HTML 屬性，兼容所有瀏覽器

        try{
            if (video.requestFullscreen){
                video.requestFullscreen().then(()=>{
                    $('body').addClass('fullscreen-mode');
                    video.play();          // 重新播放，確保 loop 生效
                });
            }else if(video.webkitRequestFullscreen){
                video.webkitRequestFullscreen();
                $('body').addClass('fullscreen-mode');
                video.play();
            }else if(video.msRequestFullscreen){
                video.msRequestFullscreen();
                $('body').addClass('fullscreen-mode');
                video.play();
            }else{
                $('body').addClass('fullscreen-mode');
                video.play();
            }
        }catch(err){
            console.error(err);
        }
    }
    function exitFullScreen(){
        if(document.fullscreenElement) document.exitFullscreen();
        $('body').removeClass('fullscreen-mode');
    }
    function onVideoEnded(e){
        const v=e.target;
        if(v.loop){v.play();return;}
        if(playMode==='1'){
            if(currentVideoIndex<videoList.length-1) playAt(currentVideoIndex+1);
            else showMessage('error','已經是最後一部影片');
        }
    }
    function playAt(idx){
        if(idx<0||idx>=videoList.length){showMessage('error','索引超出範圍');return;}
        currentVideoIndex=idx;
        const {video,row}=videoList[idx];
        $('html,body').animate({scrollTop:row.offset().top-100},500);
        const isFS=document.fullscreenElement===video;
        if(isFS){
            video.currentTime=0;video.play();video.loop=playMode==='0';
        }else{
            video.currentTime=0;video.play();enterFullScreen(video);
        }
    }

    /* --------------------------------------------------
     * DOM Ready
     * -------------------------------------------------- */
    $(function(){
        /* --- 初始顯示文字 --- */
        $('#play-mode-label').text(playMode==='0'?'循環':'自動');

        /* --- Range 調整 --- */
        $('#video-size').on('input',e=>{videoSize=e.target.value;applySizes();});
        $('#image-size').on('input',e=>{imageSize=e.target.value;applySizes();});
        $('#play-mode').on('input',e=>{playMode=e.target.value;$('#play-mode-label').text(playMode==='0'?'循環':'自動');});
        $('#video-type').change(()=>$('#controls-form').submit());

        /* --- 聚焦影片刪除 --- */
        $('#delete-focused-btn').click(()=>{
            const $f=$('.video-row.focused');if(!$f.length){showMessage('error','沒有聚焦的影片');return;}
            if(!confirm('確定要刪除聚焦的影片嗎？此操作無法撤銷。'))return;
            $.post("{{ route('video.deleteSelected') }}",{ids:[$f.data('id')],_token:'{{ csrf_token() }}'},res=>{
                if(res?.success){$f.remove();showMessage('success',res.message);rebuildAndSort();$('.video-row').first().addClass('focused');}
                else showMessage('error',res.message);
            }).fail(()=>showMessage('error','刪除失敗，請稍後再試。'));
        });

        /* --- 影片列點擊 --- */
        $(document).on('click','.video-row',function(){
            $('.video-row').removeClass('focused');$(this).addClass('focused');const id=$(this).data('id');
            focusMasterFace(id);this.scrollIntoView({behavior:'smooth',block:'center'});
        });

        /* --- Hover 放大截圖 --- */
        const $modal=$('#image-modal'),$modalImg=$modal.find('img');
        $(document).on('mouseenter','.hover-zoom',function(){
            $modalImg.attr('src',$(this).attr('src'));$modal.addClass('active');
        }).on('mouseleave','.hover-zoom',function(){
            $modal.removeClass('active');$modalImg.attr('src','');
        });

        /* --- 全螢幕按鈕 --- */
        $(document).on('click','.fullscreen-btn',function(e){
            e.stopPropagation();enterFullScreen($(this).siblings('video')[0]);
        });

        /* --- 影片結束事件 --- */
        $(document).on('ended','video',onVideoEnded);

        /* --- 捲動載入更多 --- */
        $(window).scroll(()=>{
            if($(window).scrollTop()<=100) loadMoreVideos('up');
            if($(window).scrollTop()+$(window).height()>=$(document).height()-100) loadMoreVideos('down');
        });

        /* --- 全螢幕變動 --- */
        document.addEventListener('fullscreenchange', () => {
            const fs = document.fullscreenElement;

            if (fs && $(fs).is('video')) {             // 進入全螢幕
                currentFSVideo = fs;
                fs.addEventListener('ended', onVideoEnded);

                /* ------- 全螢幕一定循環 ------- */
                fs.loop = true;
                fs.setAttribute('loop', '');

            } else if (currentFSVideo) {               // 離開全螢幕
                /* ------- 恢復 playMode (0=循環、1=自動下一部) ------- */
                const shouldLoop = (playMode === '0');
                currentFSVideo.loop = shouldLoop;
                if (shouldLoop) {
                    currentFSVideo.setAttribute('loop', '');
                } else {
                    currentFSVideo.removeAttribute('loop');
                }

                currentFSVideo = null;
            }
        });

        /* --- 滑鼠範圍左右鈕 & 觸控 --- */
        let ctrlTimeout,ctrlVisible=false,prevVisible=false,nextVisible=false;
        function showFSControls(){$('#fullscreen-controls').addClass('show');ctrlVisible=true;}
        function hideFSControls(){$('#fullscreen-controls').removeClass('show');ctrlVisible=false;}
        function onVideoMouseMove(e){
            const v=e.currentTarget,rect=v.getBoundingClientRect(),x=e.clientX-rect.left,edge=50;
            if(x<edge){!prevVisible&&($('.prev-video-btn').addClass('show'),prevVisible=true);}
            else{prevVisible&&($('.prev-video-btn').removeClass('show'),prevVisible=false);}
            if(x>rect.width-edge){!nextVisible&&($('.next-video-btn').addClass('show'),nextVisible=true);}
            else{nextVisible&&($('.next-video-btn').removeClass('show'),nextVisible=false);}
            if(!ctrlVisible) showFSControls();
            clearTimeout(ctrlTimeout);
            ctrlTimeout=setTimeout(()=>{hideFSControls();$('.prev-video-btn,.next-video-btn').removeClass('show');prevVisible=nextVisible=false;},3000);
        }
        function onTouchStart(e){this._tx=e.changedTouches[0].clientX;this._ty=e.changedTouches[0].clientY;}
        function onTouchEnd(e){
            const dx=e.changedTouches[0].clientX-this._tx,dy=e.changedTouches[0].clientY-this._ty;
            if(Math.abs(dx)>Math.abs(dy)){
                dx>50?playAt(Math.min(videoList.length-1,currentVideoIndex+1))
                    :dx<-50?playAt(Math.max(0,currentVideoIndex-1)):0;
            }else{
                dy>50?toggleLoop():dy<-50?playRandom():0;
            }
        }
        function toggleLoop(){
            if(currentFSVideo){currentFSVideo.loop=!currentFSVideo.loop;
                showMessage('success',currentFSVideo.loop?'單部循環已開啟':'單部循環已關閉');}
        }
        function playRandom(){let r=Math.floor(Math.random()*videoList.length);if(r===currentVideoIndex)r=(r+1)%videoList.length;playAt(r);}
        $(document).on('mousemove','video',function(e){if(document.fullscreenElement===this) onVideoMouseMove(e);});
        $(document).on('touchstart','video',function(e){if(document.fullscreenElement===this) onTouchStart.call(this,e);},{passive:true});
        $(document).on('touchend','video',function(e){if(document.fullscreenElement===this) onTouchEnd.call(this,e);},{passive:true});

        $('#prev-video-btn').click(()=>currentVideoIndex>0?playAt(currentVideoIndex-1):showMessage('error','已經是第一部影片'));
        $('#next-video-btn').click(()=>currentVideoIndex<videoList.length-1?playAt(currentVideoIndex+1):showMessage('error','已經是最後一部影片'));

        /* --- 拖拉上傳人臉截圖 --- */
        $(document).on('dragover','.face-upload-area',function(e){e.preventDefault();$(this).addClass('dragover');})
            .on('dragleave','.face-upload-area',function(){$(this).removeClass('dragover');})
            .on('drop','.face-upload-area',function(e){
                e.preventDefault();$(this).removeClass('dragover');
                const files=e.originalEvent.dataTransfer.files;if(!files.length)return;
                const vid=$(this).data('video-id');const fd=new FormData();
                [...files].forEach(f=>fd.append('face_images[]',f));fd.append('video_id',vid);
                $.ajax({
                    url:"{{ route('video.uploadFaceScreenshot') }}",
                    method:'POST',data:fd,contentType:false,processData:false,
                    headers:{'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    success(res){
                        if(res&&res.success){
                            const tpl=$('#face-screenshot-template').html();
                            res.data.forEach(f=>{
                                $('.face-upload-area[data-video-id="'+vid+'"]').prepend(
                                    tpl.replace('{master_class}',f.is_master?'master':'')
                                        .replace(/{face_image_path}/g,f.face_image_path)
                                        .replace(/{face_id}/g,f.id)
                                        .replace(/{video_id}/g,vid)
                                );
                            });
                            applySizes();showMessage('success','人臉截圖上傳成功！');
                        }else showMessage('error',res.message);
                    },
                    error(){showMessage('error','上傳失敗，請稍後再試。');}
                });
            });

        /* --- 刪除截圖 --- */
        $(document).on('click','.delete-icon',function(e){
            e.stopPropagation();
            const id=$(this).data('id'),type=$(this).data('type');
            $.post("{{ route('video.deleteScreenshot') }}",{id,type,_token:'{{ csrf_token() }}'},res=>{
                if(res&&res.success){
                    $(this).closest(type==='screenshot'?'.screenshot-container':'.face-screenshot-container').remove();
                    applySizes();showMessage('success','圖片刪除成功。');
                }else showMessage('error',res.message);
            }).fail(()=>showMessage('error','刪除失敗，請稍後再試。'));
        });

        /* --- 設為主面人臉 --- */
        function setMaster(faceId,vid){
            $.post("{{ route('video.setMasterFace') }}",{face_id:faceId,_token:'{{ csrf_token() }}'},res=>{
                if(res&&res.success){
                    $(`.face-screenshot[data-video-id="${vid}"]`).removeClass('master');
                    $(`.face-screenshot[data-id="${faceId}"]`).addClass('master');
                    updateMasterFace(res.data);showMessage('success','主面人臉已更新。');
                }else showMessage('error',res.message);
            }).fail(()=>showMessage('error','更新失敗，請稍後再試。'));
        }
        $(document).on('dblclick','.face-screenshot',function(e){e.stopPropagation();setMaster($(this).data('id'),$(this).data('video-id'));});
        $(document).on('click','.set-master-btn',function(e){e.stopPropagation();setMaster($(this).data('id'),$(this).data('video-id'));});

        /* --- 主面人臉側欄 → 聚焦影片 --- */
        $(document).on('click','.master-face-img',function(){
            const vid=$(this).data('video-id');
            const $row=$('.video-row[data-id="'+vid+'"]');
            if($row.length){
                $('.video-row').removeClass('focused');$row.addClass('focused');
                focusMasterFace(vid);$row[0].scrollIntoView({behavior:'smooth',block:'center'});
            }else{
                $.get("{{ route('video.findPage') }}",{video_id:vid,video_type:videoType},res=>{
                    res&&res.success&&res.page?loadPageAndFocus(vid,res.page)
                        :showMessage('error','找不到該影片所在的頁面。');
                }).fail(()=>showMessage('error','查詢失敗，請稍後再試。'));
            }
        });

        /* --- 監聽排列拖曳 --- */
        $("#videos-list").sortable({
            placeholder:"ui-state-highlight",delay:150,cancel:"video, .fullscreen-btn, img, button"
        }).disableSelection();

        /* --- 初始建構 --- */
        buildVideoList(); applySizes(); watchFocusedRow();

        if (focusId) {
            focusVideoById(focusId);
        }
    });

    /* --------------------------------------------------
     * ResizeObserver
     * -------------------------------------------------- */
    const ro=new ResizeObserver(entries=>{
        entries.forEach(ent=>{
            if($(ent.target).hasClass('focused'))
                ent.target.scrollIntoView({behavior:'auto',block:'center'});
        });
    });
    function watchFocusedRow(){
        ro.disconnect();
        const f=document.querySelector('.video-row.focused');
        if(f) ro.observe(f);
    }
    const listRO=new ResizeObserver(()=>{
        const f=document.querySelector('.video-row.focused');if(!f)return;
        const rect=f.getBoundingClientRect(),vp=window.innerHeight/2;
        if(Math.abs(rect.top+rect.height/2-vp)>10)
            f.scrollIntoView({behavior:'auto',block:'center'});
    });
    listRO.observe(document.getElementById('videos-list'));

    /* --------------------------------------------------
     * 其他輔助
     * -------------------------------------------------- */
    function focusMaxId(){
        const $rows=$('.video-row');if(!$rows.length)return;
        let $max=null,max=-Infinity;
        $rows.each(function(){const id=parseInt($(this).data('id'),10);if(id>max){max=id;$max=$(this);}});
        if($max){
            $('.video-row').removeClass('focused');$max.addClass('focused');
            focusMasterFace(max);$max[0].scrollIntoView({behavior:'smooth',block:'center'});
        }
    }
    function focusVideoById(vid){
        const $target = $('.video-row[data-id="'+vid+'"]');
        if($target.length){
            $('.video-row').removeClass('focused');
            $target.addClass('focused');
            focusMasterFace(vid);
            $target[0].scrollIntoView({behavior:'smooth',block:'center'});
        }
    }
    function updateMasterFace(face){
        const ori=(face.width&&face.height&&parseInt(face.width)>=parseInt(face.height))?'landscape':'';
        const vid=face.video_screenshot.video_master.id;
        const $img=$('.master-face-img[data-video-id="'+vid+'"]');
        if($img.length){
            $img.attr('src','{{ config("app.video_base_url") }}/'+face.face_image_path)
                .toggleClass('landscape',!!ori);
        }else{
            const html=`<img src="{{ config("app.video_base_url") }}/${face.face_image_path}" class="master-face-img ${ori}" data-video-id="${vid}" data-duration="${face.video_screenshot.video_master.duration}">`;
            let inserted=false;
            $('.master-face-images img').each(function(){
                if(face.video_screenshot.video_master.duration < +$(this).data('duration')){
                    $(this).before(html);inserted=true;return false;
                }
            });
            if(!inserted) $('.master-face-images').append(html);
        }
        applySizes();
    }


    /* === 取代原有對 video mousemove 的綁定，加入快轉邏輯 === */
    $(document).off('mousemove','video');
    $(document).on('mousemove','video',function(e){
        // 全螢幕時維持原控制條邏輯
        if(document.fullscreenElement===this){
            onVideoMouseMove(e);
            return;
        }
        // 非全螢幕：左右移動即時快轉
        const rect=this.getBoundingClientRect();
        const x=e.clientX-rect.left;
        const percent=x/rect.width;
        if(percent>=0&&percent<=1&&this.duration){
            this.currentTime=percent*this.duration;
        }
    });
</script>
</body>
</html>
