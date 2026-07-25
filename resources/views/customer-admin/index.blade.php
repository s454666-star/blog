@extends('customer-admin.layout')
@section('title', $config['title'])
@section('top-action')<a class="btn btn-primary" href="{{ route('customer-admin.module.create',$module) }}">＋ 新增{{ $config['singular'] }}</a>@endsection
@section('content')
<section class="panel">
    <form class="table-tools" method="get" data-search-form><div class="search"><span>⌕</span><input name="search" value="{{ request('search') }}" placeholder="{{ $module === 'orders' ? '搜尋姓名、市話、手機電話或訂單資料…' : '搜尋'.$config['singular'].'資料…' }}" data-search-input></div><button class="btn btn-secondary" type="submit">搜尋</button></form>
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
    const form=document.querySelector('[data-search-form]'), input=form?.querySelector('[data-search-input]');
    if(!form||!input)return;
    const initialValue=input.value;
    let submitting=false;
    const submitSearch=()=>{if(submitting)return;submitting=true;form.requestSubmit()};
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
