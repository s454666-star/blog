@extends('customer-admin.layout')
@section('title', $config['title'])
@section('top-action')<a class="btn btn-primary" href="{{ route('customer-admin.module.create',$module) }}">＋ 新增{{ $config['singular'] }}</a>@endsection
@if($module==='orders')
@push('head')
<style>
    .order-overview{display:grid;grid-template-columns:repeat(2,minmax(0,220px)) minmax(0,1fr);gap:16px;margin-bottom:20px}.order-overview-total{padding:18px 20px}.order-overview-total small{display:block;color:var(--muted);margin-bottom:7px}.order-overview-total strong{font-size:28px}.order-contact-counts{padding:0;overflow:hidden}.order-contact-counts summary{padding:20px;cursor:pointer;font-size:16px;font-weight:800;list-style:none}.order-contact-counts summary::-webkit-details-marker{display:none}.order-contact-counts summary:after{content:'＋';float:right;color:var(--cyan)}.order-contact-counts[open] summary{border-bottom:1px solid var(--line)}.order-contact-counts[open] summary:after{content:'−'}.order-contact-count-grid{display:flex;flex-wrap:wrap;gap:8px;padding:16px 20px 20px}.order-contact-count{display:inline-flex;align-items:center;gap:8px;padding:7px 10px;border-radius:10px;background:rgba(54,217,239,.07);border:1px solid rgba(54,217,239,.14);font-size:13px}.order-contact-count b{color:var(--cyan)}@media(max-width:1000px){.order-overview{grid-template-columns:1fr 1fr}.order-contact-counts{grid-column:1/-1}}@media(max-width:700px){.order-overview{grid-template-columns:1fr}.order-contact-counts{grid-column:auto}}
</style>
@endpush
@endif
@section('content')
@if($module==='orders')
<div class="order-overview">
    <section class="panel order-overview-total"><small>目前客戶總數</small><strong>{{ number_format($orderOverview['customer_count']) }} 個</strong></section>
    <section class="panel order-overview-total"><small>目前訂單總數</small><strong>{{ number_format($orderOverview['order_count']) }} 張</strong></section>
    <details class="panel order-contact-counts">
        <summary>每位接洽人訂單數（點擊展開）</summary>
        <div class="order-contact-count-grid">
            @foreach($orderOverview['contact_order_counts'] as $contact)
                <span class="order-contact-count"><span>{{ $contact->name }}</span><b>{{ number_format($contact->orders_count) }} 張</b></span>
            @endforeach
            @if($orderOverview['unassigned_order_count'] > 0)
                <span class="order-contact-count"><span>未指定接洽人</span><b>{{ number_format($orderOverview['unassigned_order_count']) }} 張</b></span>
            @endif
        </div>
    </details>
</div>
@endif
<section class="panel">
    <form class="table-tools" method="get" data-search-form>
        @if(request()->filled('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
        @if(request()->filled('direction'))<input type="hidden" name="direction" value="{{ request('direction') }}">@endif
        <div class="search"><span>⌕</span><input name="search" value="{{ request('search') }}" placeholder="{{ $module === 'orders' ? '搜尋客戶、接洽人、市話、手機電話、地址或訂單資料…' : '搜尋'.$config['singular'].'資料…' }}" data-search-input></div>
        <div class="table-tool-actions">
            <label class="per-page-control">每頁顯示
                <select name="per_page" data-per-page aria-label="每頁顯示筆數">
                    @foreach($perPageOptions as $option)
                        <option value="{{ $option }}" @selected($perPage === $option)>{{ $option }} 筆</option>
                    @endforeach
                </select>
            </label>
            <button class="btn btn-secondary" type="submit">搜尋</button>
        </div>
    </form>
    @if($records->isEmpty())<div class="empty"><div style="font-size:34px;margin-bottom:12px">✦</div>目前沒有資料<br><small>按右上角新增第一筆{{ $config['singular'] }}</small></div>@else
    <div class="table-wrap"><table><thead><tr>
        @foreach($config['columns'] as $key=>$label)
            @php
                $sortable = isset($config['sortable'][$key]);
                $activeSort = request('sort') === $key;
                $nextDirection = $activeSort && request('direction') === 'asc' ? 'desc' : 'asc';
                $sortUrl = route('customer-admin.module.index', array_merge(
                    ['module' => $module],
                    request()->except(['page', 'sort', 'direction']),
                    ['sort' => $key, 'direction' => $nextDirection]
                ));
            @endphp
            <th @if($sortable) aria-sort="{{ $activeSort ? (request('direction') === 'desc' ? 'descending' : 'ascending') : 'none' }}" @endif>
                @if($sortable)
                    <a class="sort-link {{ $activeSort ? 'active' : '' }}" href="{{ $sortUrl }}">
                        <span>{{ $label }}</span><b aria-hidden="true">{{ $activeSort ? (request('direction') === 'desc' ? '↓' : '↑') : '↕' }}</b>
                    </a>
                @else
                    {{ $label }}
                @endif
            </th>
        @endforeach
        <th style="text-align:right">操作</th></tr></thead><tbody>
        @foreach($records as $record)<tr>
            @foreach($config['columns'] as $key=>$label)
                @php
                    $value = data_get($record, $key);
                @endphp
                <td>
                    @if($module==='products' && $key==='name' && $record->image_path)<img class="thumb" src="{{ Storage::url($record->image_path) }}" alt="">@endif
                    @if($value instanceof \Carbon\CarbonInterface){{ $value->format('Y-m-d') }}
                    @elseif($key==='is_default')<span class="badge">{{ $value ? '是' : '否' }}</span>
                    @elseif(in_array($key,['status','payment_status']))<span class="badge">{{ $value ?: '未設定' }}</span>
                    @elseif(in_array($key,['price','total']))${{ number_format((float)$value,0) }}
                    @else{{ filled($value) ? $value : '—' }}@endif
                </td>
            @endforeach
            <td><div class="actions">
                @if($module==='products')
                    <form method="post" action="{{ route('customer-admin.products.move',$record->id) }}">@csrf<input type="hidden" name="direction" value="up"><button class="btn btn-sm btn-secondary product-move" type="submit" title="上移並自動儲存" aria-label="上移 {{ $record->name }}">↑</button></form>
                    <form method="post" action="{{ route('customer-admin.products.move',$record->id) }}">@csrf<input type="hidden" name="direction" value="down"><button class="btn btn-sm btn-secondary product-move" type="submit" title="下移並自動儲存" aria-label="下移 {{ $record->name }}">↓</button></form>
                @endif
                <a class="btn btn-sm btn-secondary" href="{{ route('customer-admin.module.edit',[$module,$record->id]) }}">編輯</a><form method="post" action="{{ route('customer-admin.module.destroy',[$module,$record->id]) }}" onsubmit="return confirm('確定刪除這筆資料？')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">刪除</button></form>
            </div></td>
        </tr>@endforeach
    </tbody></table></div><div class="pagination">{{ $records->onEachSide(2)->links('customer-admin.pagination') }}</div>@endif
</section>
@endsection
@push('scripts')
<script>
(() => {
    const form=document.querySelector('[data-search-form]'), input=form?.querySelector('[data-search-input]'), perPage=form?.querySelector('[data-per-page]');
    if(!form||!input)return;
    const initialValue=input.value;
    let submitting=false;
    const submitSearch=()=>{if(submitting)return;submitting=true;form.requestSubmit()};
    perPage?.addEventListener('change',submitSearch);
    input.addEventListener('change',submitSearch);
    input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();submitSearch()}});
    document.addEventListener('pointerdown',event=>{
        if(!form.contains(event.target)&&input.value!==initialValue){
            event.preventDefault();
            submitSearch();
        }
    },true);
})();
</script>
@endpush
