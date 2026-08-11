@extends('customer-admin.layout')
@section('title', '查詢與匯出')

@push('head')
<style>
    .export-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:24px}.export-card{padding:20px;display:flex;flex-direction:column;gap:14px}.export-card h3{margin:0;font-size:17px}.export-card p{margin:0;color:var(--muted);font-size:13px;line-height:1.6}.export-card .btn{margin-top:auto}.query-panel{padding:20px;margin-bottom:24px}.query-fields{display:grid;grid-template-columns:1fr 1fr auto auto;gap:12px;align-items:end}.query-fields label{display:block;margin-bottom:8px;color:#cbd2e8;font-size:13px;font-weight:750}.query-summary{color:var(--muted);font-size:13px}.export-help{padding:14px 16px;border-radius:12px;background:rgba(54,217,239,.07);border:1px solid rgba(54,217,239,.16);color:#bceef5;font-size:13px}.export-table-head{display:flex;justify-content:space-between;align-items:center;padding:18px 20px;border-bottom:1px solid var(--line)}.export-table-head h3{margin:0}.export-table-head span{color:var(--muted);font-size:13px}@media(max-width:1000px){.export-grid{grid-template-columns:1fr}.query-fields{grid-template-columns:1fr 1fr}}@media(max-width:700px){.query-fields{grid-template-columns:1fr}.query-fields .btn{width:100%}}
</style>
@endpush

@section('content')
<div class="export-help">先在下方選擇年份與接洽人，可查詢預覽；匯出按鈕會依各自標示的範圍產生 XLSX。</div>

<form class="panel query-panel" method="get" action="{{ route('customer-admin.export.index') }}">
    <div class="query-fields">
        <div><label for="year">訂單年份</label><select id="year" name="year"><option value="">全部年份</option>@foreach($years as $option)<option value="{{ $option }}" @selected($year === $option)>{{ $option }} 年</option>@endforeach</select></div>
        <div><label for="contact_id">接洽人</label><select id="contact_id" name="contact_id"><option value="">全部接洽人</option>@foreach($contacts as $contact)<option value="{{ $contact->id }}" @selected($contactId === $contact->id)>{{ $contact->name }}</option>@endforeach</select></div>
        <button class="btn btn-primary" type="submit">⌕ 查詢</button>
        <a class="btn btn-secondary" href="{{ route('customer-admin.export.index') }}">清除</a>
    </div>
</form>

<div class="export-grid">
    <section class="panel export-card"><h3>依照年份匯出</h3><p>匯出所選年份內，全部接洽人的訂單與相關資料。</p>@if($year)<a class="btn btn-primary" href="{{ route('customer-admin.export.download', ['mode'=>'year','year'=>$year]) }}">⇩ 匯出 {{ $year }} 年</a>@else<button class="btn btn-secondary" type="button" disabled>請先選擇年份</button>@endif</section>
    <section class="panel export-card"><h3>依照接洽人匯出</h3><p>匯出所選接洽人在指定年份內的訂單與相關資料。</p>@if($year && $contactId)<a class="btn btn-primary" href="{{ route('customer-admin.export.download', ['mode'=>'year_contact','year'=>$year,'contact_id'=>$contactId]) }}">⇩ 匯出年份＋接洽人</a>@else<button class="btn btn-secondary" type="button" disabled>請選擇年份與接洽人</button>@endif</section>
    <section class="panel export-card"><h3>依照接洽人全部匯出</h3><p>匯出所選接洽人的全部年份訂單與相關資料。</p>@if($contactId)<a class="btn btn-primary" href="{{ route('customer-admin.export.download', ['mode'=>'contact_all','contact_id'=>$contactId]) }}">⇩ 匯出接洽人全部年份</a>@else<button class="btn btn-secondary" type="button" disabled>請先選擇接洽人</button>@endif</section>
    <section class="panel export-card"><h3>接洽人分頁匯出</h3><p>有選接洽人時只匯出該人；未選接洽人時全部匯出，一位接洽人一個 Sheet。</p><a class="btn btn-primary" href="{{ route('customer-admin.export.download', array_filter(['mode'=>'contact_sheets','contact_id'=>$contactId])) }}">⇩ {{ $contactId ? '匯出所選接洽人' : '全部接洽人分頁匯出' }}</a></section>
</div>

@if($orders)
<section class="panel">
    <div class="export-table-head"><h3>查詢結果</h3><span>共 {{ number_format($orders->total()) }} 筆訂單</span></div>
    @if($orders->isEmpty())
        <div class="empty">沒有符合條件的訂單</div>
    @else
        <div class="table-wrap">
            <table>
                <thead><tr><th>訂單編號</th><th>日期</th><th>客戶</th><th>接洽人</th><th>總額</th></tr></thead>
                <tbody>
                @foreach($orders as $order)
                    <tr><td>{{ $order->order_number }}</td><td>{{ $order->order_date?->format('Y-m-d') ?: '—' }}</td><td>{{ $order->customer?->name ?: '—' }}</td><td>{{ $order->contact?->name ?: '—' }}</td><td>${{ number_format((float)$order->total, 0) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if($orders->hasPages())
            <div class="pagination">{{ $orders->links('customer-admin.pagination') }}</div>
        @endif
    @endif
</section>
@endif

<div style="margin-top:18px;text-align:right"><a class="btn btn-secondary" href="{{ route('customer-admin.export.download', ['mode'=>'all']) }}">⇩ 匯出完整資料</a></div>
@endsection
