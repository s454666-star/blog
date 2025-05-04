<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>影片列表</title>

    <!-- ===== 依賴 ===== -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">

    <!-- ===== 樣式 ===== -->
    <style>
        :root { --video-width: 70%; }

        /* 影片列 -------------------------------------------------- */
        .video-row{
            display:flex;margin-bottom:20px;border:1px solid #ddd;padding:10px;border-radius:5px;
            cursor:pointer;user-select:none;transition:background-color .3s,border-color .3s}
        .video-row.selected{border-color:#007bff;background:#e7f1ff}
        .video-row.focused {border-color:#28a745;background:#e6ffe6}

        /* 左半影片 */
        .video-container{width:var(--video-width);padding-right:10px}
        .video-wrapper{position:relative}
        .fullscreen-btn{
            position:absolute;top:10px;right:10px;background:rgba(255,255,255,.7);
            border:none;padding:5px;cursor:pointer}

        /* 右半截圖 */
        .images-container{width:calc(100% - var(--video-width));padding-left:10px}
        .screenshot,.face-screenshot{
            width:100px;height:56px;object-fit:cover;margin:5px;
            transition:transform .3s,border .3s,box-shadow .3s}
        .face-screenshot.master{border:3px solid #f00}

        /* 放大圖片 */
        .image-modal{
            display:none;position:fixed;inset:0;z-index:2000;
            background:rgba(0,0,0,.8);justify-content:center;align-items:center;pointer-events:none}
        .image-modal img{
            max-width:90%;max-height:90%;border:5px solid #fff;border-radius:5px;pointer-events:none}
        .image-modal.active{display:flex}

        /* 底部控制 */
        .controls{
            position:fixed;inset-inline-start:30%;inset-inline-end:0;bottom:0;z-index:1000;
            background:#fff;padding:20px 30px;border-top:1px solid #ddd;box-shadow:0 -2px 5px rgba(0,0,0,.1);
            display:flex;flex-wrap:wrap;align-items:center}
        .controls .control-group{margin:0 30px 10px 0;display:flex;align-items:center}
        .controls label{margin-inline-end:10px;font-weight:700;white-space:nowrap}
        #play-mode{width:50px;height:10px}

        /* 主面人臉側欄 */
        .master-faces{
            position:fixed;inset-block-start:0;inset-inline-start:0;width:30%;height:100%;
            overflow-y:auto;background:#f8f9fa;border-inline-end:1px solid #ddd;padding:10px;box-sizing:border-box;z-index:100}
        .master-face-images{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
        .master-face-img{
            width:100%;aspect-ratio:1/1;object-fit:cover;cursor:pointer;border:2px solid transparent;border-radius:5px;
            transition:border-color .3s,box-shadow .3s,transform .3s}
        .master-face-img.landscape{grid-column:span 2;aspect-ratio:2/1}
        .master-face-img:hover  {border-color:#007bff;transform:scale(1.05)}
        .master-face-img.focused{border-color:#28a745;box-shadow:0 0 15px rgba(40,167,69,.7);transform:scale(1.1)}

        /* 全螢幕下隱藏 */
        .fullscreen-mode .controls,
        .fullscreen-mode .master-faces,
        .fullscreen-mode .container{display:none}

        /* 全螢幕左右鈕 */
        .fullscreen-controls{position:fixed;inset:0;z-index:2000;display:none}
        .fullscreen-controls.show{display:block}
        .fullscreen-controls .prev-video-btn,
        .fullscreen-controls .next-video-btn{
            position:absolute;top:50%;translate:0 -50%;
            background:rgba(0,0,0,.5);border:none;color:#fff;padding:20px;font-size:24px;border-radius:50%;cursor:pointer;
            opacity:0;transition:opacity .3s}
        .fullscreen-controls .prev-video-btn{inset-inline-start:20px}
        .fullscreen-controls .next-video-btn{inset-inline-end:20px}
        .fullscreen-controls .show{opacity:1}

        /* RWD */
        @media(max-width:768px){
            .video-container,.images-container{width:100%;padding:0}
            .controls{inset-inline-start:0;flex-direction:column;align-items:flex-start}
            .master-faces{position:relative;width:100%;height:auto;border-right:none;border-bottom:1px solid #ddd}
            .container{margin-inline-start:0}
        }

        /* 主容器 */
        .container{margin-inline-start:30%;padding-block:20px 80px}

        /* 訊息氣泡 */
        .message-container{position:fixed;top:20px;right:20px;z-index:3000}
        .message{
            padding:10px 20px;border-radius:5px;margin-bottom:10px;color:#fff;opacity:.9;animation:fadeOut 1s forwards}
        .message.success{background:#28a745}
        .message.error  {background:#dc3545}
        @keyframes fadeOut{to{opacity:0}}

        /* 刪除、設主面按鈕（提高層級） */
        .delete-icon,.set-master-btn{
            position:absolute;top:5px;right:5px;border:none;border-radius:50%;width:20px;height:20px;
            color:#fff;text-align:center;line-height:18px;cursor:pointer;display:none;font-size:14px;padding:0;z-index:3001}
        .delete-icon{background:rgba(220,53,69,.8)}
        .set-master-btn{right:30px;background:rgba(40,167,69,.8)}

        .screenshot-container,.face-screenshot-container{position:relative}   /* 讓按鈕絕對定位 */
        .screenshot-container:hover .delete-icon,
        .face-screenshot-container:hover .delete-icon,
        .face-screenshot-container:hover .set-master-btn{display:block}

        /* jQuery‑UI placeholder */
        .ui-state-highlight{height:120px;border:2px dashed #ccc;background:#f9f9f9;margin-bottom:20px}

        @media(min-width:1200px){
            .container,.container-lg,.container-md,.container-sm,.container-xl{max-width:1750px}
        }
    </style>
</head>

<body>
<!-- ===== 主面人臉側欄 ===== -->
<div class="master-faces">
    <h5>主面人臉</h5>
    <div class="master-face-images">
        @foreach($masterFaces as $masterFace)
            @php
                $p=public_path($masterFace->face_image_path);
                $ori=(file_exists($p)&&list($w,$h)=getimagesize($p)&&$w>=$h)?'landscape':'';
            @endphp
            <img src="{{ config('app.video_base_url') }}/{{ $masterFace->face_image_path }}"
                 class="master-face-img {{ $ori }}"
                 data-video-id="{{ $masterFace->videoScreenshot->videoMaster->id }}"
                 data-duration="{{ $masterFace->videoScreenshot->videoMaster->duration }}" alt="">
        @endforeach
    </div>
</div>

<!-- ===== 影片清單 ===== -->
<div class="container">
    <div id="message-container" class="message-container"></div>
    <div id="videos-list">
        @include('video.partials.video_rows',['videos'=>$videos])
    </div>
    <div id="load-more" class="text-center my-4" style="display:none;">
        <p>正在載入更多影片...</p>
    </div>
</div>

<!-- ===== 全螢幕左右鈕 ===== -->
<div id="fullscreen-controls" class="fullscreen-controls">
    <button id="prev-video-btn" class="prev-video-btn">❮</button>
    <button id="next-video-btn" class="next-video-btn">❯</button>
</div>

<!-- ===== 底部控制列 ===== -->
<div class="controls">
    <form id="controls-form" class="d-flex flex-wrap w-100">
        <div class="control-group flex-grow-1">
            <label for="video-size">影片大小:</label>
            <input type="range" id="video-size" name="video_size" min="10" max="50"
                   value="{{ request('video_size',25) }}">
        </div>
        <div class="control-group flex-grow-1">
            <label for="image-size">截圖大小:</label>
            <input type="range" id="image-size" name="image_size" min="100" max="300"
                   value="{{ request('image_size',200) }}">
        </div>
        <div class="control-group flex-grow-1">
            <label for="video-type">影片類別:</label>
            <select id="video-type" name="video_type" class="form-control">
                @for($i=1;$i<=4;$i++)
                    <option value="{{ $i }}" {{ request('video_type','1')==$i?'selected':'' }}>{{ $i }}</option>
                @endfor
            </select>
        </div>
        <div class="control-group flex-grow-1">
            <label for="play-mode">播放模式:</label>
            <input type="range" id="play-mode" name="play_mode" min="0" max="1" step="1"
                   value="{{ request('play_mode','0') }}">
            <span id="play-mode-label">{{ request('play_mode','0')=='0'?'循環':'自動' }}</span>
        </div>
        <div class="control-group flex-grow-1">
            <button id="delete-focused-btn" type="button" class="btn btn-warning">刪除聚焦的影片</button>
        </div>
    </form>
</div>

<!-- ===== 模板 ===== -->
<template id="video-row-template">
    <div class="video-row" data-id="{id}" data-duration="{duration}">
        <div class="video-container">
            <div class="video-wrapper">
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
                <div class="d-flex flex-wrap face-upload-area" data-video-id="{video_id}"
                     style="position:relative;border:2px dashed #007bff;border-radius:5px;padding:10px;min-height:120px;">
                    {face_screenshot_images}
                    <div class="upload-instructions"
                         style="width:100%;text-align:center;color:#aaa;margin-top:10px;">
                        拖曳圖片到此處上傳
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<template id="screenshot-template">
    <div class="screenshot-container">
        <img src="{{ config('app.video_base_url') }}/{{ '{screenshot_path}' }}"
             class="screenshot hover-zoom" data-id="{{ '{screenshot_id}' }}" data-type="screenshot" alt="">
        <button class="delete-icon" data-id="{{ '{screenshot_id}' }}" data-type="screenshot">&times;</button>
    </div>
</template>

<template id="face-screenshot-template">
    <div class="face-screenshot-container">
        <img src="{{ config('app.video_base_url') }}/{{ '{face_image_path}' }}"
             class="face-screenshot hover-zoom {master_class}" data-id="{{ '{face_id}' }}"
             data-video-id="{{ '{video_id}' }}" data-type="face-screenshot" alt="">
        <button class="set-master-btn" data-id="{{ '{face_id}' }}" data-video-id="{{ '{video_id}' }}">★</button>
        <button class="delete-icon" data-id="{{ '{face_id}' }}" data-type="face-screenshot">&times;</button>
    </div>
</template>

<!-- 放大圖片 -->
<div id="image-modal" class="image-modal"><img src="" alt=""></div>

<!-- ===== Scripts ===== -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>
<script>
    /* ---------- 全域變數 ---------- */
    let lastPage       = {{ $lastPage ?? 1 }};
    let loadedPages    = [{{ $videos->currentPage() }}];
    let nextPage       = {{ $next_page ?? 'null' }};
    let prevPage       = {{ $prev_page ?? 'null' }};
    let loading        = false;

    let videoList      = [];
    let currentVideoIndex = 0;
    let playMode       = {{ request('play_mode') ? 1 : 0 }};
    let currentFSVideo = null;
    let videoSize      = {{ request('video_size',25) }};
    let imageSize      = {{ request('image_size',200) }};
    let videoType      = '{{ request('video_type','1') }}';

    /* ---------- ResizeObserver 置中 ---------- */
    const ro=new ResizeObserver(e=>{e.forEach(ent=>{if(ent.target.classList.contains('focused'))ent.target.scrollIntoView({behavior:'auto',block:'center'});});});
    function watchFocusedRow(){ro.disconnect();const f=document.querySelector('.video-row.focused');if(f)ro.observe(f);}

    /* ---------- 工具 ---------- */
    function showMessage(type,text){
        const $m=$('<div class="message">').addClass(type==='success'?'success':'error').text(text);
        $('#message-container').append($m);setTimeout(()=>{$m.fadeOut(500,()=>$m.remove());},1000);
    }

    /* ---------- 分頁計算 ---------- */
    function recalcPages(){
        const min=Math.min(...loadedPages),max=Math.max(...loadedPages);
        prevPage=min>1?min-1:null;nextPage=max<lastPage?max+1:null;
    }

    /* ---------- Ajax 載入更多 ---------- */
    function loadMore(dir='down',targetPage=null){
        if(loading) return;
        if(!targetPage){
            if(dir==='down' && !nextPage) return;
            if(dir==='up'   && !prevPage) return;
        }
        loading=true;$('#load-more').show();
        $.get("{{ route('video.loadMore') }}",{
            page: targetPage ?? (dir==='down'?nextPage:prevPage),video_type:videoType
        },res=>{
            if(res?.success && res.data.trim()){
                (dir==='down'?$('#videos-list').append:$('#videos-list').prepend)($(res.data));
                if(!loadedPages.includes(res.current_page))loadedPages.push(res.current_page);
                lastPage=res.last_page||lastPage; rebuildSort();
            }else{dir==='down'?nextPage=null:prevPage=null;}
            loading=false;$('#load-more').hide();
        }).fail(()=>{showMessage('error','載入失敗');loading=false;$('#load-more').hide();});
    }

    /* ---------- 動態載入指定頁並聚焦 ---------- */
    function loadPageAndFocus(videoId,page){
        if(loading) return;
        loading=true;$('#load-more').show();
        $.get("{{ route('video.loadMore') }}",{page:page,video_type:videoType},res=>{
            if(res?.success && res.data.trim()){
                $('#videos-list').append($(res.data));
                if(!loadedPages.includes(res.current_page))loadedPages.push(res.current_page);
                lastPage=res.last_page||lastPage; rebuildSort();
                const $row=$(`.video-row[data-id="${videoId}"]`);
                if($row.length){
                    $('.video-row').removeClass('focused');
                    $row.addClass('focused');
                    focusMaster(videoId);
                    $row[0].scrollIntoView({behavior:'smooth',block:'center'});
                }
            }else showMessage('error','無法載入影片所在頁面');
            loading=false;$('#load-more').hide();
        }).fail(()=>{showMessage('error','載入失敗');loading=false;$('#load-more').hide();});
    }

    /* ---------- 建立 / 排序清單 ---------- */
    function buildList(){
        videoList=[];
        $('.video-row').each(function(){
            videoList.push({id:$(this).data('id'),video:$(this).find('video')[0],$row:$(this)});
        });
    }
    function applySize(){
        $('.video-container').css('width',videoSize+'%');
        $('.images-container').css('width',(100-videoSize)+'%');
        $('.screenshot,.face-screenshot').css({width:imageSize+'px',height:(imageSize*0.56)+'px'});
    }
    function rebuildSort(){
        const rows=$('.video-row').get().sort((a,b)=>$(a).data('duration')-$(b).data('duration'));
        $('#videos-list').empty().append(rows);buildList();applySize();recalcPages();watchFocusedRow();
    }

    /* ---------- 主面人臉同步 ---------- */
    function focusMaster(id){
        $('.master-face-img').removeClass('focused');
        const $t=$(`.master-face-img[data-video-id="${id}"]`);
        if(!$t.length) return;
        $t.addClass('focused');
        document.querySelector('.master-faces')
            .scrollTo({top:$t[0].offsetTop-$t[0].clientHeight/2,behavior:'smooth'});
    }

    /* ---------- 重新載入主面人臉清單 ---------- */
    function loadMasterFaces(){
        $.get("{{ route('video.loadMasterFaces') }}",{video_type:videoType},res=>{
            if(res?.success){
                let html='<h5>主面人臉</h5><div class="master-face-images">';
                res.data.sort((a,b)=>a.video_screenshot.video_master.duration-b.video_screenshot.video_master.duration)
                    .forEach(face=>{
                        const land=(parseInt(face.width)>=parseInt(face.height))?'landscape':'';
                        html+=`<img src="{{ config('app.video_base_url') }}/${face.face_image_path}"
                                class="master-face-img ${land}"
                                data-video-id="${face.video_screenshot.video_master.id}"
                                data-duration="${face.video_screenshot.video_master.duration}">`;
                    });
                html+='</div>';
                $('.master-faces').html(html);
                focusMaster($('.video-row.focused').data('id'));
            }
        });
    }

    /* ---------- 全螢幕相關 ---------- */
    function enterFS(v){
        (v.requestFullscreen||v.webkitRequestFullscreen||v.msRequestFullscreen||(()=>Promise.resolve()))()
            .then(()=>$('body').addClass('fullscreen-mode'));
    }
    function onFSC(){
        const fs=document.fullscreenElement||document.webkitFullscreenElement;
        if(fs && fs.tagName==='VIDEO'){
            currentFSVideo=fs; $(fs).addClass('is-fullscreen');
            currentVideoIndex=videoList.findIndex(x=>x.video===fs);
            fs.loop=playMode===0; fs.addEventListener('ended',onEnded);
            fs.addEventListener('mousemove',onVMouse);
            fs.addEventListener('touchstart',tStart,{passive:true});
            fs.addEventListener('touchend',tEnd,{passive:true});
            $('#fullscreen-controls').addClass('show');
        }else{
            $('video.is-fullscreen').removeClass('is-fullscreen');
            $('#fullscreen-controls').removeClass('show');
            if(currentFSVideo){
                currentFSVideo.removeEventListener('ended',onEnded);
                currentFSVideo.removeEventListener('mousemove',onVMouse);
                currentFSVideo.removeEventListener('touchstart',tStart);
                currentFSVideo.removeEventListener('touchend',tEnd);
                currentFSVideo.loop=false;currentFSVideo=null;
            }
            $('body').removeClass('fullscreen-mode');
        }
    }
    function onEnded(e){
        if(e.target.loop)e.target.play();
        else if(playMode===1){
            currentVideoIndex<videoList.length-1 ? playIdx(currentVideoIndex+1) : showMessage('error','已經是最後一部');
        }
    }
    function playIdx(i){
        if(i<0||i>=videoList.length){showMessage('error','索引超出範圍');return;}
        currentVideoIndex=i; const {$row,video}=videoList[i];
        $('html,body').animate({scrollTop:$row.offset().top-100},500);
        video.currentTime=0;video.play();enterFS(video);
    }

    /* ---------- 全螢幕滑鼠/觸控 ---------- */
    let timer,prevVis=false,nextVis=false;
    function onVMouse(e){
        const r=e.currentTarget.getBoundingClientRect(),x=e.clientX-r.left,edge=50;
        x<edge?($('.prev-video-btn').addClass('show'),prevVis=true):prevVis&&($('.prev-video-btn').removeClass('show'),prevVis=false);
        x>r.width-edge?($('.next-video-btn').addClass('show'),nextVis=true):nextVis&&($('.next-video-btn').removeClass('show'),nextVis=false);
        clearTimeout(timer);$('#fullscreen-controls').addClass('show');
        timer=setTimeout(()=>{$('#fullscreen-controls').removeClass('show');$('.prev-video-btn,.next-video-btn').removeClass('show');prevVis=nextVis=false;},3000);
    }
    let sx=null,sy=null;
    function tStart(e){sx=e.changedTouches[0].clientX;sy=e.changedTouches[0].clientY;}
    function tEnd(e){
        const dx=e.changedTouches[0].clientX-sx,dy=e.changedTouches[0].clientY-sy;
        if(Math.abs(dx)>Math.abs(dy)){
            dx>50?playIdx(currentVideoIndex+1):dx<-50&&playIdx(currentVideoIndex-1);
        }else{
            dy>50?toggleLoop():dy<-50&&randPlay();
        }
    }
    function randPlay(){
        if(!videoList.length)return;
        let r=Math.floor(Math.random()*videoList.length);
        if(r===currentVideoIndex) r=(r+1)%videoList.length;
        playIdx(r);
    }
    function toggleLoop(){
        if(currentFSVideo){
            currentFSVideo.loop=!currentFSVideo.loop;
            showMessage('success',currentFSVideo.loop?'單部循環開':'單部循環關');
        }
    }

    /* ---------- DOM Ready ---------- */
    $(function(){
        /* Range & Select */
        $('#video-size').val(videoSize).on('input',function(){videoSize=this.value;applySize();});
        $('#image-size').val(imageSize).on('input',function(){imageSize=this.value;applySize();});
        $('#play-mode').val(playMode).on('input',function(){playMode=this.value;$('#play-mode-label').text(playMode==0?'循環':'自動');});
        $('#video-type').on('change',()=>$('#controls-form').submit());

        /* Scroll 懶載入 */
        $(window).on('scroll',()=>{
            if($(window).scrollTop()<=100) loadMore('up');
            if($(window).scrollTop()+$(window).height()>=$(document).height()-100) loadMore('down');
        });

        /* 影片列點擊聚焦 */
        $(document).on('click','.video-row',function(){
            $('.video-row').removeClass('focused');$(this).addClass('focused');
            focusMaster($(this).data('id'));
            this.scrollIntoView({behavior:'smooth',block:'center'});
        });

        /* 刪除聚焦影片 */
        $('#delete-focused-btn').on('click',()=>{
            const $f=$('.video-row.focused');
            if(!$f.length)return showMessage('error','沒有聚焦影片');
            if(!confirm('確定要刪除聚焦影片?'))return;
            $.post("{{ route('video.deleteSelected') }}",{ids:[$f.data('id')],_token:'{{ csrf_token() }}'},res=>{
                if(res?.success){
                    $f.remove();showMessage('success',res.message);
                    $('.video-row').first().addClass('focused');rebuildSort();
                }else showMessage('error',res.message);
            }).fail(()=>showMessage('error','刪除失敗'));
        });

        /* 刪除單張截圖 / 人臉截圖 */
        $(document).on('click','.delete-icon',function(e){
            e.stopPropagation();
            const id=$(this).data('id'), type=$(this).data('type');
            $.post("{{ route('video.deleteScreenshot') }}",{id:id,type:type,_token:'{{ csrf_token() }}'},res=>{
                if(res?.success){
                    $(`img[data-id="${id}"][data-type="${type}"]`).closest(type==='screenshot'?'.screenshot-container':'.face-screenshot-container').remove();
                    if(type==='face-screenshot') loadMasterFaces(); // 若刪除人臉需更新左側
                    showMessage('success','刪除成功');
                }else showMessage('error',res.message);
            }).fail(()=>showMessage('error','刪除失敗'));
        });

        /* 主面人臉點兩下 / 按★ 設定新主面 */
        $(document).on('dblclick','.face-screenshot, .set-master-btn',function(e){
            e.stopPropagation();
            const faceId=$(this).data('id')||$(this).closest('.face-screenshot').data('id');
            const videoId=$(this).data('video-id')||$(this).closest('.face-screenshot').data('video-id');
            $.post("{{ route('video.setMasterFace') }}",{face_id:faceId,_token:'{{ csrf_token() }}'},res=>{
                if(res?.success){
                    $('.face-screenshot[data-video-id="'+videoId+'"]').removeClass('master');
                    $('.face-screenshot[data-id="'+faceId+'"]').addClass('master');
                    loadMasterFaces();
                    showMessage('success','主面人臉已更新');
                }else showMessage('error',res.message);
            }).fail(()=>showMessage('error','更新失敗'));
        });

        /* 影片全螢幕 */
        $(document).on('click','.fullscreen-btn',function(e){
            e.stopPropagation();enterFS($(this).siblings('video')[0]);
        });

        /* Hover 顯示大圖 */
        const $modal=$('#image-modal'),$img=$modal.find('img');
        $(document).on('mouseenter','.hover-zoom',function(){$img.attr('src',this.src);$modal.addClass('active');})
            .on('mouseleave','.hover-zoom',()=>{$modal.removeClass('active');$img.attr('src','');});

        /* jQuery UI Sortable */
        $('#videos-list').sortable({placeholder:"ui-state-highlight",delay:150,cancel:"video,.fullscreen-btn,img,button"})
            .disableSelection();

        /* 全螢幕左右鈕 */
        $('#prev-video-btn').on('click',()=>playIdx(currentVideoIndex-1));
        $('#next-video-btn').on('click',()=>playIdx(currentVideoIndex+1));

        /* List 高度變化—保持聚焦置中 */
        new ResizeObserver(()=>{const f=document.querySelector('.video-row.focused');if(!f)return;
            const r=f.getBoundingClientRect(),vp=window.innerHeight/2;
            if(Math.abs(r.top+r.height/2-vp)>10)f.scrollIntoView({behavior:'auto',block:'center'});
        }).observe(document.getElementById('videos-list'));

        /* 主面人臉點擊 → 聚焦影片 */
        $(document).on('click','.master-face-img',function(){
            const vid=$(this).data('video-id');
            let $row=$(`.video-row[data-id="${vid}"]`);
            if($row.length){
                $('.video-row').removeClass('focused');$row.addClass('focused');
                focusMaster(vid);$row[0].scrollIntoView({behavior:'smooth',block:'center'});
            }else{
                $.get("{{ route('video.findPage') }}",{video_id:vid,video_type:videoType},res=>{
                    if(res?.success&&res.page) loadPageAndFocus(vid,res.page);
                    else showMessage('error','找不到影片位置');
                }).fail(()=>showMessage('error','查詢失敗'));
            }
        });

        /* 初始排序 + 聚焦最新一部 (id 最大) */
        rebuildSort();
        const $latest=$('.video-row').sort((a,b)=>$(b).data('id')-$(a).data('id')).first();
        $latest.addClass('focused');focusMaster($latest.data('id'));
        $latest[0].scrollIntoView({behavior:'smooth',block:'center'});
    });

    /* ---------- 全螢幕事件監聽 ---------- */
    document.addEventListener('fullscreenchange',onFSC);
    document.addEventListener('webkitfullscreenchange',onFSC);
</script>
</body>
</html>
