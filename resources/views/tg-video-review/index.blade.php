<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>TG 暫存影片審核</title>
    <style>
        :root { color-scheme: dark; --cyan:#22d3ee; --blue:#3b82f6; --pink:#f472b6; --green:#34d399; --red:#fb7185; }
        * { box-sizing: border-box; }
        body { margin:0; min-width:320px; min-height:100vh; color:#eaf6ff; font-family:Inter,"Microsoft JhengHei",system-ui,sans-serif; background:radial-gradient(circle at 10% 0,rgba(34,211,238,.18),transparent 30rem),radial-gradient(circle at 90% 15%,rgba(244,114,182,.15),transparent 32rem),linear-gradient(145deg,#030712,#071326 52%,#0b1020); }
        body::before { content:""; position:fixed; inset:0; pointer-events:none; opacity:.3; background-image:linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px); background-size:48px 48px; mask-image:linear-gradient(#000,transparent); }
        .orb { position:fixed; width:28rem; height:28rem; border-radius:50%; filter:blur(100px); opacity:.16; pointer-events:none; animation:float 9s ease-in-out infinite alternate; }
        .orb.a { left:-12rem; bottom:-9rem; background:var(--cyan); }.orb.b{right:-13rem;top:18rem;background:var(--pink);animation-delay:-4s}
        @keyframes float { to { transform:translate3d(40px,-30px,0) scale(1.12); } }
        .shell { width:min(1500px,calc(100% - 32px)); margin:auto; padding:42px 0 80px; position:relative; }
        header { display:flex; gap:24px; align-items:end; justify-content:space-between; margin-bottom:24px; }
        h1 { margin:0 0 8px; font-size:clamp(2rem,5vw,4rem); letter-spacing:-.05em; text-shadow:0 0 34px rgba(34,211,238,.3); }
        .eyebrow { color:var(--cyan); font-weight:800; letter-spacing:.18em; text-transform:uppercase; }.sub{margin:0;color:#94a3b8}
        .counter { padding:12px 18px; border:1px solid rgba(34,211,238,.28); border-radius:999px; background:rgba(8,25,45,.7); box-shadow:inset 0 0 24px rgba(34,211,238,.06),0 12px 30px rgba(0,0,0,.2); white-space:nowrap; }
        .toolbar { position:sticky; top:12px; z-index:20; display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding:14px; margin-bottom:18px; border:1px solid rgba(148,163,184,.18); border-radius:20px; background:rgba(6,15,29,.82); backdrop-filter:blur(18px); box-shadow:0 18px 50px rgba(0,0,0,.28); }
        button,select { border:1px solid rgba(255,255,255,.15); border-radius:13px; color:#fff; background:#101c30; font:inherit; font-weight:750; }
        button { padding:11px 18px; cursor:pointer; transition:.2s ease; box-shadow:inset 0 1px rgba(255,255,255,.08); }
        button:hover:not(:disabled) { transform:translateY(-2px); filter:brightness(1.14); box-shadow:0 10px 26px rgba(0,0,0,.3),0 0 0 1px currentColor; }
        button:disabled { opacity:.38; cursor:not-allowed; }.process{color:#e0f2fe;background:linear-gradient(135deg,rgba(8,145,178,.62),rgba(37,99,235,.58))}
        .spacer{flex:1}.page-size{display:flex;gap:8px;align-items:center;color:#aebed0}.page-size select{padding:10px 34px 10px 12px}
        .table-frame { overflow:hidden; border:1px solid rgba(34,211,238,.2); border-radius:24px; background:rgba(5,14,27,.75); box-shadow:0 30px 90px rgba(0,0,0,.38),inset 0 1px rgba(255,255,255,.05); }
        table { width:100%; border-collapse:collapse; table-layout:fixed; } th { color:#8ee9f4; text-align:left; letter-spacing:.08em; font-size:.78rem; text-transform:uppercase; background:rgba(14,35,57,.92); }
        th,td { padding:14px; border-bottom:1px solid rgba(148,163,184,.12); } th:first-child,td:first-child{width:250px;text-align:center} tbody tr { transition:.2s;background:linear-gradient(90deg,transparent,rgba(34,211,238,.018)); } tbody tr:hover{background:linear-gradient(90deg,rgba(34,211,238,.08),rgba(59,130,246,.045),rgba(244,114,182,.05));box-shadow:inset 4px 0 var(--cyan)}
        input[type=checkbox] { width:24px;height:24px;accent-color:var(--cyan);cursor:pointer;filter:drop-shadow(0 0 8px rgba(34,211,238,.45)); }
        .action-grid { display:grid;grid-template-columns:repeat(3,minmax(56px,1fr));align-items:center;gap:8px; }
        .action-option { display:inline-flex;flex-direction:column;align-items:center;gap:5px;color:#b9cce0;font-size:.72rem;letter-spacing:.03em;cursor:pointer; }
        .action-option.delete { color:#fecdd3; }.action-option.ok { color:#d1fae5; }.action-option.watermark { color:#dbeafe; }
        .action-option input[data-action="delete"] { accent-color:var(--red); }.action-option input[data-action="ok"] { accent-color:var(--green); }.action-option input[data-action="watermark"] { accent-color:var(--blue); }
        .selection-cell { cursor:pointer;user-select:none;transition:background .18s ease,box-shadow .18s ease; }
        .selection-cell:hover { background:rgba(34,211,238,.09);box-shadow:inset 0 0 24px rgba(34,211,238,.08); }
        .sheet { display:block; width:100%; max-height:420px; object-fit:contain; border:1px solid rgba(96,165,250,.25); border-radius:16px; background:#020617; box-shadow:0 14px 34px rgba(0,0,0,.35); cursor:zoom-in; transition:.25s; }
        .sheet:hover { border-color:var(--cyan); box-shadow:0 0 0 1px var(--cyan),0 16px 40px rgba(34,211,238,.16); }
        .empty { padding:80px 24px;text-align:center;color:#94a3b8 }.empty strong{display:block;color:#dff8ff;font-size:1.3rem;margin-bottom:8px}
        .pagination { display:flex;justify-content:center;gap:8px;flex-wrap:wrap;margin-top:22px}.pagination a,.pagination span{padding:10px 14px;border-radius:11px;border:1px solid rgba(148,163,184,.18);background:rgba(9,22,39,.8);color:#c9d7e6;text-decoration:none}.pagination .active{color:#041018;background:var(--cyan);font-weight:900}
        #preview { display:none; position:fixed; inset:0; z-index:1000; padding:20px; align-items:center; justify-content:center; background:rgba(0,4,12,.9); backdrop-filter:blur(16px); pointer-events:none; }
        #preview.show { display:flex; } #preview img { max-width:calc(100vw - 40px);max-height:calc(100vh - 40px);object-fit:contain;border:2px solid var(--cyan);border-radius:18px;box-shadow:0 0 70px rgba(34,211,238,.28),0 40px 100px #000; }
        #toast { position:fixed;right:22px;bottom:22px;z-index:1100;max-width:min(440px,calc(100vw - 44px));padding:14px 18px;border:1px solid rgba(255,255,255,.2);border-radius:14px;background:#0c1d31;box-shadow:0 20px 60px #000;transform:translateY(130%);transition:.25s } #toast.show{transform:none}#toast.bad{border-color:var(--red);color:#fecdd3}
        @media(max-width:680px){.shell{width:min(100% - 18px,1500px);padding-top:24px}header{align-items:start;flex-direction:column}.toolbar{top:6px}.spacer{display:none}.page-size{width:100%;justify-content:flex-end}th,td{padding:8px}th:first-child,td:first-child{width:180px}.action-grid{gap:2px}.action-option{font-size:.65rem}.sheet{border-radius:10px}}
    </style>
</head>
<body>
<div class="orb a"></div><div class="orb b"></div>
<main class="shell">
    <header><div><div class="eyebrow">Local visual triage</div><h1>TG 暫存影片審核</h1><p class="sub">移入垃圾桶、OK 或水印資料夾前，先用 20 格接觸表快速確認。</p></div><div class="counter">共 {{ $records->total() }} 筆</div></header>
    <div class="toolbar">
        <button type="button" id="selectAll" @disabled($records->isEmpty())>全選本頁</button>
        <button type="button" id="process" class="process" disabled>處理</button>
        <span class="spacer"></span>
        <label class="page-size">每頁
            <select id="perPage" aria-label="每頁筆數">
                @foreach($perPageOptions as $option)<option value="{{ $option }}" @selected($option === $perPage)>{{ $option }}</option>@endforeach
            </select>
        </label>
    </div>
    <div class="table-frame">
        @if($records->count())
            <table><thead><tr><th><div class="action-grid"><label class="action-option delete"><input id="selectPage" type="checkbox" data-action="delete" aria-label="全選本頁並設為刪除"><span>刪除</span></label><span class="action-option ok"><span aria-hidden="true">✓</span><span>OK</span></span><span class="action-option watermark"><span aria-hidden="true">✓</span><span>水印</span></span></div></th><th>圖片</th></tr></thead><tbody>
            @foreach($records as $record)
                <tr data-id="{{ $record->id }}"><td class="selection-cell"><div class="action-grid" role="group" aria-label="第 {{ $record->id }} 筆處理方式"><label class="action-option delete"><input class="action-check" type="checkbox" value="{{ $record->id }}" data-action="delete" aria-label="第 {{ $record->id }} 筆刪除"><span>刪除</span></label><label class="action-option ok"><input class="action-check" type="checkbox" value="{{ $record->id }}" data-action="ok" aria-label="第 {{ $record->id }} 筆搬到 OK"><span>OK</span></label><label class="action-option watermark"><input class="action-check" type="checkbox" value="{{ $record->id }}" data-action="watermark" aria-label="第 {{ $record->id }} 筆搬到水印"><span>水印</span></label></div></td><td><img class="sheet" src="{{ route('tg-video-review.image', $record) }}" alt="影片 5×4 接觸表" loading="lazy"></td></tr>
            @endforeach
            </tbody></table>
        @else
            <div class="empty"><strong>目前沒有待審核影片</strong>雙擊桌面的掃描腳本後，接觸表會出現在這裡。</div>
        @endif
    </div>
    @if($records->hasPages())<div class="pagination">{{ $records->onEachSide(2)->links() }}</div>@endif
</main>
<div id="preview" aria-hidden="true"><img alt="全螢幕接觸表預覽"></div><div id="toast" role="status"></div>
<script>
(() => {
    const checks=[...document.querySelectorAll('.action-check')], selectPage=document.querySelector('#selectPage'), selectAll=document.querySelector('#selectAll'), process=document.querySelector('#process'), preview=document.querySelector('#preview'), previewImage=preview.querySelector('img'), toast=document.querySelector('#toast');
    const rows=[...document.querySelectorAll('tbody tr[data-id]')];
    const activeRows=()=>rows.filter(row=>row.isConnected);
    const selected=()=>checks.filter(check=>check.isConnected&&check.checked).map(check=>({id:Number(check.value),action:check.dataset.action}));
    const allRowsSelected=()=>activeRows().length>0&&activeRows().every(row=>row.querySelector('.action-check:checked'));
    const allRowsDelete=()=>activeRows().length>0&&activeRows().every(row=>row.querySelector('.action-check[data-action="delete"]')?.checked);
    const sync=()=>{const count=selected().length,all=allRowsDelete();process.disabled=count===0;if(selectPage){selectPage.checked=all;selectPage.indeterminate=count>0&&!all;}if(selectAll)selectAll.textContent=all?'取消全選':'全選本頁';};
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
        process.disabled=true;
        try{
            const response=await fetch(@json(route('tg-video-review.actions')),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({items})});
            const data=await response.json(); if(!response.ok)throw new Error(data.message||'操作失敗');
            (data.completed_ids||[]).forEach(id=>document.querySelector(`tr[data-id="${id}"]`)?.remove());
            say(data.message,!data.ok); if(data.ok)setTimeout(()=>location.reload(),500);else sync();
        }catch(error){say(error.message||'操作失敗',true);sync();}
    });
    sync();
})();
</script>
</body>
</html>
