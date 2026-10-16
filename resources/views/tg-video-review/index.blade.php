<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>TG 暫存影片審核</title>
    <style>
        :root { color-scheme: dark; --cyan:#22d3ee; --blue:#60a5fa; --pink:#f472b6; --green:#34d399; --red:#fb7185; --line:rgba(148,163,184,.16); --panel:rgba(8,18,34,.78); --muted:#8ea0b8; }
        * { box-sizing: border-box; }
        html { scroll-padding-top: 110px; }
        body { margin:0; min-width:320px; min-height:100vh; color:#eaf6ff; font-family:Inter,"Microsoft JhengHei",system-ui,sans-serif; background:radial-gradient(circle at 10% 0,rgba(34,211,238,.16),transparent 30rem),radial-gradient(circle at 90% 15%,rgba(244,114,182,.12),transparent 32rem),linear-gradient(145deg,#030712,#071326 52%,#0b1020); background-attachment:fixed; }
        body::before { content:""; position:fixed; inset:0; pointer-events:none; opacity:.3; background-image:linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px); background-size:48px 48px; mask-image:linear-gradient(#000,transparent 60%); }
        .shell { width:min(1500px,calc(100% - 32px)); margin:auto; padding:40px 0 120px; position:relative; }

        /* Scroll progress */
        #progressTrack { position:fixed; top:0; left:0; right:0; height:4px; z-index:60; background:rgba(148,163,184,.12); }
        #progressBar { height:100%; width:0; background:linear-gradient(90deg,var(--cyan),var(--blue),var(--pink)); box-shadow:0 0 14px rgba(34,211,238,.7); transition:width .12s linear; }
        #progressHud { position:fixed; left:22px; bottom:22px; z-index:50; display:flex; align-items:center; gap:14px; padding:12px 14px 12px 12px; border:1px solid rgba(34,211,238,.28); border-radius:18px; background:rgba(6,15,29,.88); backdrop-filter:blur(16px); box-shadow:0 18px 50px rgba(0,0,0,.45); font-variant-numeric:tabular-nums; }
        #progressHud[hidden] { display:none; }
        .ring { --p:0; width:54px; height:54px; flex:none; display:grid; place-items:center; border-radius:50%; background:conic-gradient(var(--cyan) calc(var(--p) * 1%),rgba(148,163,184,.16) 0); }
        .ring span { width:44px; height:44px; display:grid; place-items:center; border-radius:50%; background:#081324; font-size:.82rem; font-weight:850; }
        .hud-text { display:grid; gap:3px; line-height:1.25; }
        .hud-main { font-weight:800; } .hud-main b { color:var(--cyan); } .hud-main em { font-style:normal; color:var(--muted); font-weight:700; margin-left:8px; }
        .hud-sub { color:var(--muted); font-size:.8rem; }
        #toTop { width:36px; height:36px; padding:0; border-radius:11px; display:grid; place-items:center; font-size:1rem; }

        /* Header */
        header { display:flex; gap:24px; align-items:end; justify-content:space-between; margin-bottom:22px; }
        h1 { margin:0 0 8px; font-size:clamp(2rem,5vw,3.6rem); letter-spacing:-.04em; text-shadow:0 0 34px rgba(34,211,238,.28); }
        .eyebrow { color:var(--cyan); font-weight:800; font-size:.78rem; letter-spacing:.2em; text-transform:uppercase; margin-bottom:6px; }
        .sub { margin:0; color:var(--muted); }
        .stats { display:flex; gap:10px; flex-wrap:wrap; justify-content:flex-end; }
        .stat { min-width:118px; padding:12px 16px; border:1px solid var(--line); border-radius:16px; background:var(--panel); box-shadow:inset 0 1px rgba(255,255,255,.05); }
        .stat small { display:block; color:var(--muted); font-size:.74rem; letter-spacing:.06em; margin-bottom:3px; }
        .stat strong { font-size:1.25rem; font-variant-numeric:tabular-nums; }

        /* Toolbar */
        .toolbar { position:sticky; top:12px; z-index:20; display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding:12px; margin-bottom:18px; border:1px solid var(--line); border-radius:20px; background:rgba(6,15,29,.86); backdrop-filter:blur(18px); box-shadow:0 18px 50px rgba(0,0,0,.3); }
        button, select, .pager-jump input { border:1px solid rgba(255,255,255,.14); border-radius:12px; color:#fff; background:#101c30; font:inherit; font-weight:750; }
        button { padding:10px 18px; cursor:pointer; transition:transform .15s ease, filter .15s ease, box-shadow .15s ease; box-shadow:inset 0 1px rgba(255,255,255,.08); }
        button:hover:not(:disabled) { transform:translateY(-1px); filter:brightness(1.15); }
        button:focus-visible, select:focus-visible, a:focus-visible, input:focus-visible { outline:2px solid var(--cyan); outline-offset:2px; }
        button:disabled { opacity:.38; cursor:not-allowed; }
        .process { color:#e0f2fe; background:linear-gradient(135deg,rgba(8,145,178,.75),rgba(37,99,235,.7)); border-color:rgba(34,211,238,.4); }
        .process:not(:disabled) { box-shadow:0 0 0 1px rgba(34,211,238,.3),0 8px 24px rgba(34,211,238,.18); }
        .tally { display:flex; gap:6px; flex-wrap:wrap; }
        .chip { display:inline-flex; align-items:center; gap:6px; padding:7px 11px; border-radius:999px; border:1px solid var(--line); background:rgba(15,28,48,.7); color:var(--muted); font-size:.82rem; font-weight:750; font-variant-numeric:tabular-nums; }
        .chip i { width:8px; height:8px; border-radius:50%; background:currentColor; }
        .chip.delete { color:#fda4af; } .chip.ok { color:#6ee7b7; } .chip.watermark { color:#93c5fd; }
        .chip.zero { opacity:.45; }
        .spacer { flex:1; }
        .page-size { display:flex; gap:8px; align-items:center; color:#aebed0; }
        .page-size select { padding:9px 30px 9px 12px; }

        /* Table */
        .table-frame { overflow:hidden; border:1px solid rgba(34,211,238,.18); border-radius:24px; background:rgba(5,14,27,.75); box-shadow:0 30px 90px rgba(0,0,0,.38),inset 0 1px rgba(255,255,255,.05); }
        table { width:100%; border-collapse:collapse; table-layout:fixed; }
        th { color:#8ee9f4; text-align:left; letter-spacing:.08em; font-size:.78rem; text-transform:uppercase; background:rgba(14,35,57,.92); }
        th, td { padding:14px; border-bottom:1px solid var(--line); vertical-align:middle; }
        th:first-child, td:first-child { width:260px; text-align:center; }
        tbody tr { transition:background .18s ease, box-shadow .18s ease; }
        tbody tr:hover { background:rgba(34,211,238,.045); }
        tbody tr:has(input[data-action="delete"]:checked) { background:rgba(251,113,133,.07); box-shadow:inset 4px 0 var(--red); }
        tbody tr:has(input[data-action="ok"]:checked) { background:rgba(52,211,153,.07); box-shadow:inset 4px 0 var(--green); }
        tbody tr:has(input[data-action="watermark"]:checked) { background:rgba(96,165,250,.07); box-shadow:inset 4px 0 var(--blue); }
        .selection-cell { cursor:pointer; user-select:none; }
        .row-no { display:block; margin-bottom:10px; color:#5f7390; font-size:.76rem; font-weight:800; letter-spacing:.06em; font-variant-numeric:tabular-nums; }
        .action-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:6px; }
        .action-option { position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:3px; min-height:58px; padding:8px 4px; border:1px solid var(--line); border-radius:14px; background:rgba(15,28,48,.65); color:#9fb2c8; font-size:.8rem; font-weight:800; cursor:pointer; transition:background .15s ease, border-color .15s ease, color .15s ease, transform .15s ease; }
        .action-option:hover { border-color:rgba(255,255,255,.28); transform:translateY(-1px); }
        .action-option input { position:absolute; inset:0; width:100%; height:100%; margin:0; opacity:0; cursor:pointer; }
        .action-option .icon { font-size:1.05rem; line-height:1; }
        .action-option:has(input:focus-visible) { outline:2px solid var(--cyan); outline-offset:2px; }
        .action-option.delete:has(input:checked) { color:#fff; border-color:var(--red); background:linear-gradient(160deg,rgba(251,113,133,.5),rgba(190,18,60,.45)); box-shadow:0 6px 18px rgba(251,113,133,.25); }
        .action-option.ok:has(input:checked) { color:#fff; border-color:var(--green); background:linear-gradient(160deg,rgba(52,211,153,.45),rgba(4,120,87,.45)); box-shadow:0 6px 18px rgba(52,211,153,.22); }
        .action-option.watermark:has(input:checked) { color:#fff; border-color:var(--blue); background:linear-gradient(160deg,rgba(96,165,250,.45),rgba(29,78,216,.45)); box-shadow:0 6px 18px rgba(96,165,250,.22); }
        .action-option.delete:has(input:indeterminate) { color:#fecdd3; border-color:rgba(251,113,133,.55); border-style:dashed; }
        th .action-option { min-height:44px; text-transform:none; letter-spacing:.02em; }
        th .action-option.static { cursor:default; opacity:.75; }
        th .action-option.static:hover { transform:none; border-color:var(--line); }
        .sheet { display:block; width:100%; max-height:420px; object-fit:contain; border:1px solid rgba(96,165,250,.22); border-radius:16px; background:#020617; box-shadow:0 14px 34px rgba(0,0,0,.35); cursor:zoom-in; transition:border-color .2s ease, box-shadow .2s ease; }
        .sheet:hover { border-color:var(--cyan); box-shadow:0 0 0 1px var(--cyan),0 16px 40px rgba(34,211,238,.16); }
        .empty { padding:80px 24px; text-align:center; color:var(--muted); }
        .empty strong { display:block; color:#dff8ff; font-size:1.3rem; margin-bottom:8px; }

        /* Pagination */
        .pager { display:flex; flex-direction:column; align-items:center; gap:14px; margin-top:26px; }
        .pager-links { display:flex; align-items:center; justify-content:center; gap:8px; flex-wrap:wrap; }
        .pager-pages { display:flex; align-items:center; gap:6px; flex-wrap:wrap; justify-content:center; }
        .pager-btn { display:inline-flex; align-items:center; justify-content:center; min-width:42px; height:42px; padding:0 14px; border:1px solid var(--line); border-radius:12px; background:rgba(9,22,39,.85); color:#c9d7e6; text-decoration:none; font-weight:750; font-variant-numeric:tabular-nums; transition:background .15s ease, border-color .15s ease, transform .15s ease; }
        a.pager-btn:hover { border-color:rgba(34,211,238,.55); background:rgba(14,40,64,.9); color:#fff; transform:translateY(-1px); }
        .pager-btn.is-current { color:#041018; background:var(--cyan); border-color:var(--cyan); font-weight:900; box-shadow:0 0 22px rgba(34,211,238,.35); }
        .pager-btn.is-disabled { opacity:.35; cursor:not-allowed; }
        .pager-gap { min-width:24px; text-align:center; color:#5f7390; }
        .pager-jump { display:flex; align-items:center; gap:8px; color:var(--muted); font-size:.9rem; }
        .pager-jump input { width:76px; height:38px; padding:0 10px; text-align:center; font-variant-numeric:tabular-nums; }
        .pager-jump button { height:38px; padding:0 14px; }

        #preview { display:none; position:fixed; inset:0; z-index:1000; padding:20px; align-items:center; justify-content:center; background:rgba(0,4,12,.9); backdrop-filter:blur(16px); pointer-events:none; }
        #preview.show { display:flex; }
        #preview img { max-width:calc(100vw - 40px); max-height:calc(100vh - 40px); object-fit:contain; border:2px solid var(--cyan); border-radius:18px; box-shadow:0 0 70px rgba(34,211,238,.28),0 40px 100px #000; }
        #toast { position:fixed; right:22px; bottom:22px; z-index:1100; max-width:min(440px,calc(100vw - 44px)); padding:14px 18px; border:1px solid rgba(255,255,255,.2); border-radius:14px; background:#0c1d31; box-shadow:0 20px 60px #000; transform:translateY(160%); transition:transform .25s ease; }
        #toast.show { transform:none; } #toast.bad { border-color:var(--red); color:#fecdd3; }

        @media (max-width:760px) {
            .shell { width:calc(100% - 20px); padding-top:24px; }
            header { align-items:stretch; flex-direction:column; }
            .stats { justify-content:stretch; } .stat { flex:1; min-width:0; }
            .toolbar { top:8px; gap:6px; padding:8px; border-radius:16px; } .spacer { display:none; }
            .toolbar button { padding:8px 12px; }
            .chip { padding:5px 8px; font-size:.74rem; }
            .page-size { margin-left:auto; font-size:.85rem; } .page-size select { padding:6px 24px 6px 8px; }
            th, td { padding:8px; } th:first-child, td:first-child { width:150px; }
            .action-grid { grid-template-columns:1fr; } .action-option { min-height:40px; flex-direction:row; gap:6px; }
            .sheet { border-radius:10px; }
            #progressHud { left:10px; right:10px; bottom:10px; }
            .hud-text { flex:1; }
            .pager-step { flex:1; }
        }
    </style>
</head>
<body>
<div id="progressTrack" aria-hidden="true"><div id="progressBar"></div></div>
<main class="shell">
    <header>
        <div>
            <div class="eyebrow">Local visual triage</div>
            <h1>TG 暫存影片審核</h1>
            <p class="sub">移入垃圾桶、OK 或水印資料夾前，先用 20 格接觸表快速確認。</p>
        </div>
        <div class="stats">
            <div class="stat"><small>待審核</small><strong>{{ number_format($records->total()) }}</strong></div>
            @if($records->count())
                <div class="stat"><small>本頁範圍</small><strong>{{ number_format($records->firstItem()) }}–{{ number_format($records->lastItem()) }}</strong></div>
                <div class="stat"><small>頁數</small><strong>{{ $records->currentPage() }} / {{ $records->lastPage() }}</strong></div>
            @endif
        </div>
    </header>
    <div class="toolbar">
        <button type="button" id="selectAll" @disabled($records->isEmpty())>全選本頁</button>
        <button type="button" id="process" class="process" disabled>處理</button>
        <div class="tally" aria-live="polite">
            <span class="chip delete zero" data-tally="delete"><i></i>刪除 <b>0</b></span>
            <span class="chip ok zero" data-tally="ok"><i></i>OK <b>0</b></span>
            <span class="chip watermark zero" data-tally="watermark"><i></i>水印 <b>0</b></span>
        </div>
        <span class="spacer"></span>
        <label class="page-size">每頁
            <select id="perPage" aria-label="每頁筆數">
                @foreach($perPageOptions as $option)<option value="{{ $option }}" @selected($option === $perPage)>{{ $option }}</option>@endforeach
            </select>
        </label>
    </div>
    <div class="table-frame">
        @if($records->count())
            <table><thead><tr><th><div class="action-grid"><label class="action-option delete"><input id="selectPage" type="checkbox" data-action="delete" aria-label="全選本頁並設為刪除"><span class="icon" aria-hidden="true">🗑</span><span>全部刪除</span></label><span class="action-option ok static"><span class="icon" aria-hidden="true">✓</span><span>OK</span></span><span class="action-option watermark static"><span class="icon" aria-hidden="true">◎</span><span>水印</span></span></div></th><th>圖片</th></tr></thead><tbody>
            @foreach($records as $record)
                <tr data-id="{{ $record->id }}"><td class="selection-cell"><span class="row-no">#{{ number_format($records->firstItem() + $loop->index) }}</span><div class="action-grid" role="group" aria-label="第 {{ $record->id }} 筆處理方式"><label class="action-option delete"><input class="action-check" type="checkbox" value="{{ $record->id }}" data-action="delete" aria-label="第 {{ $record->id }} 筆刪除"><span class="icon" aria-hidden="true">🗑</span><span>刪除</span></label><label class="action-option ok"><input class="action-check" type="checkbox" value="{{ $record->id }}" data-action="ok" aria-label="第 {{ $record->id }} 筆搬到 OK"><span class="icon" aria-hidden="true">✓</span><span>OK</span></label><label class="action-option watermark"><input class="action-check" type="checkbox" value="{{ $record->id }}" data-action="watermark" aria-label="第 {{ $record->id }} 筆搬到水印"><span class="icon" aria-hidden="true">◎</span><span>水印</span></label></div></td><td><img class="sheet" src="{{ route('tg-video-review.image', $record) }}" alt="影片 5×4 接觸表" loading="lazy"></td></tr>
            @endforeach
            </tbody></table>
        @else
            <div class="empty"><strong>目前沒有待審核影片</strong>雙擊桌面的掃描腳本後，接觸表會出現在這裡。</div>
        @endif
    </div>
    {{ $records->onEachSide(2)->links('tg-video-review.pagination') }}
</main>
<div id="progressHud" hidden>
    <div class="ring" id="progressRing"><span id="progressPct">0%</span></div>
    <div class="hud-text">
        <div class="hud-main">已看 <b id="progressDone">0%</b><em>剩 <span id="progressLeft">100%</span></em></div>
        <div class="hud-sub" id="progressRows"></div>
    </div>
    <button type="button" id="toTop" aria-label="回到頂部">↑</button>
</div>
<div id="preview" aria-hidden="true"><img alt="全螢幕接觸表預覽"></div><div id="toast" role="status"></div>
<script>
(() => {
    const checks=[...document.querySelectorAll('.action-check')], selectPage=document.querySelector('#selectPage'), selectAll=document.querySelector('#selectAll'), process=document.querySelector('#process'), preview=document.querySelector('#preview'), previewImage=preview.querySelector('img'), toast=document.querySelector('#toast');
    const rows=[...document.querySelectorAll('tbody tr[data-id]')];
    const activeRows=()=>rows.filter(row=>row.isConnected);
    const selected=()=>checks.filter(check=>check.isConnected&&check.checked).map(check=>({id:Number(check.value),action:check.dataset.action}));
    const allRowsSelected=()=>activeRows().length>0&&activeRows().every(row=>row.querySelector('.action-check:checked'));
    const allRowsDelete=()=>activeRows().length>0&&activeRows().every(row=>row.querySelector('.action-check[data-action="delete"]')?.checked);
    const tally=items=>{const counts=items.reduce((result,item)=>({...result,[item.action]:(result[item.action]||0)+1}),{});document.querySelectorAll('[data-tally]').forEach(chip=>{const n=counts[chip.dataset.tally]||0;chip.querySelector('b').textContent=n.toLocaleString();chip.classList.toggle('zero',n===0);});};
    const sync=()=>{const items=selected(),count=items.length,all=allRowsDelete();process.disabled=count===0;process.title=count?`處理 ${count} 筆`:'';if(selectPage){selectPage.checked=all;selectPage.indeterminate=count>0&&!all;}if(selectAll)selectAll.textContent=all?'取消全選':'全選本頁';tally(items);};
    const togglePage=checked=>{rows.forEach(row=>{row.querySelectorAll('.action-check').forEach(check=>check.checked=false);if(checked)row.querySelector('.action-check[data-action="delete"]').checked=true;});sync();};
    checks.forEach(check=>check.addEventListener('change',()=>{if(check.checked)check.closest('tr').querySelectorAll('.action-check').forEach(other=>{if(other!==check)other.checked=false;});sync();}));
    selectPage?.addEventListener('change',()=>togglePage(selectPage.checked));
    selectAll?.addEventListener('click',()=>togglePage(!allRowsSelected()||!allRowsDelete()));
    document.querySelector('#perPage').addEventListener('change',event=>{const url=new URL(location.href);url.searchParams.set('per_page',event.target.value);url.searchParams.delete('page');location.href=url;});
    document.querySelectorAll('.sheet').forEach(img=>{img.addEventListener('pointerenter',()=>{previewImage.src=img.src;preview.classList.add('show');});img.addEventListener('pointerleave',()=>preview.classList.remove('show'));});
    const say=(message,bad=false)=>{toast.textContent=message;toast.classList.toggle('bad',bad);toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),4500)};
    process?.addEventListener('click',async()=>{
        const items=selected(); if(!items.length)return;
        const counts=items.reduce((result,item)=>({...result,[item.action]:(result[item.action]||0)+1}),{});
        if(!confirm(`確定處理 ${items.length} 筆？刪除 ${counts.delete||0}、OK ${counts.ok||0}、水印 ${counts.watermark||0}。`))return;
        process.disabled=true; process.textContent='處理中…';
        try{
            const response=await fetch(@json(route('tg-video-review.actions')),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({items})});
            const data=await response.json(); if(!response.ok)throw new Error(data.message||'操作失敗');
            (data.completed_ids||[]).forEach(id=>document.querySelector(`tr[data-id="${id}"]`)?.remove());
            say(data.message,!data.ok); if(data.ok)setTimeout(()=>location.reload(),500);else sync();
        }catch(error){say(error.message||'操作失敗',true);sync();}
        finally{process.textContent='處理';updateProgress();}
    });

    // Scroll progress: which row sits under the sticky toolbar, as a share of this page's rows.
    const toolbar=document.querySelector('.toolbar'), hud=document.querySelector('#progressHud'), bar=document.querySelector('#progressBar'), ring=document.querySelector('#progressRing');
    const pctText=document.querySelector('#progressPct'), doneText=document.querySelector('#progressDone'), leftText=document.querySelector('#progressLeft'), rowsText=document.querySelector('#progressRows');
    const firstItem=@json($records->firstItem() ?? 0), total=@json($records->total());
    const currentRow=list=>{const line=toolbar.getBoundingClientRect().bottom+8;if(list[0].getBoundingClientRect().top>line)return 0;let lo=0,hi=list.length-1,found=0;while(lo<=hi){const mid=(lo+hi)>>1;if(list[mid].getBoundingClientRect().top<=line){found=mid;lo=mid+1;}else hi=mid-1;}return found+1;};
    function updateProgress(){
        const list=activeRows(), max=document.documentElement.scrollHeight-innerHeight, atBottom=max<=0||scrollY>=max-4;
        if(!list.length){hud.hidden=true;bar.style.width='0';return;}
        const seen=atBottom?list.length:currentRow(list), pct=Math.round(seen/list.length*100);
        hud.hidden=false; bar.style.width=pct+'%'; ring.style.setProperty('--p',pct);
        pctText.textContent=pct+'%'; doneText.textContent=pct+'%'; leftText.textContent=(100-pct)+'%';
        const position=Math.max(seen,1);
        rowsText.textContent=`本頁第 ${position.toLocaleString()} / ${list.length.toLocaleString()} 列`+(total>list.length?` · 全部第 ${(firstItem+position-1).toLocaleString()} / ${total.toLocaleString()} 筆`:'');
    }
    let ticking=false;
    const schedule=()=>{if(ticking)return;ticking=true;requestAnimationFrame(()=>{ticking=false;updateProgress();});};
    addEventListener('scroll',schedule,{passive:true}); addEventListener('resize',schedule);
    document.querySelectorAll('.sheet').forEach(img=>img.addEventListener('load',schedule,{once:true}));
    document.querySelector('#toTop').addEventListener('click',()=>scrollTo({top:0,behavior:'smooth'}));
    sync(); updateProgress();
})();
</script>
</body>
</html>
