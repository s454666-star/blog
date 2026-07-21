@extends('customer-admin.layout')
@section('title', '營運總覽')
@section('top-action')<a class="btn btn-primary" href="{{ route('customer-admin.module.create', 'orders') }}">＋ 建立訂單</a>@endsection
@section('content')
    <section class="welcome panel"><h2>直接建立訂單，客戶資料一起記住 ✦</h2><p>在訂單輸入客戶資料；下次輸入部分電話，就能選取並帶回舊資料。</p></section>
    <div class="stat-grid">@foreach($stats as $stat)<div class="stat panel tone-{{ $stat['tone'] }}"><div class="stat-icon">{{ $stat['icon'] }}</div><div class="stat-label">{{ $stat['label'] }}</div><div class="stat-value">{{ $stat['value'] }}</div></div>@endforeach</div>
    <section class="panel"><div class="section-head"><h3>最近訂單</h3><a class="btn btn-sm btn-secondary" href="{{ route('customer-admin.module.index','orders') }}">查看全部 →</a></div>
        @if($recentOrders->isEmpty())<div class="empty">還沒有訂單，從右上角建立第一筆吧。</div>@else
        <div class="table-wrap"><table><thead><tr><th>訂單編號</th><th>日期</th><th>客戶</th><th>狀態</th><th>總額</th></tr></thead><tbody>
        @foreach($recentOrders as $order)<tr><td>{{ $order->order_number }}</td><td>{{ $order->order_date?->format('Y-m-d') ?: '—' }}</td><td>{{ $order->customer?->name ?: '—' }}</td><td><span class="badge">{{ $order->status ?: '未設定' }}</span></td><td>${{ number_format((float)$order->total,2) }}</td></tr>@endforeach
        </tbody></table></div>@endif
    </section>
@endsection
