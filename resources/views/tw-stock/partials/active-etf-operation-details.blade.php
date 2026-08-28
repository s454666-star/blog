<section class="ledger-panel operations-panel">
    <div class="ledger-head">
        <h2 class="ledger-title">操作明細</h2>
        <div class="ledger-meta">
            共 {{ number_format($items->count()) }} 筆異動，
            {{ $reports->filter(fn ($report): bool => (int) $report->items_count === 0)->count() }} 份報告無成分股異動
        </div>
    </div>

    @if ($items->isEmpty())
        <div class="empty">目前篩選條件下沒有操作異動。</div>
    @else
        <div class="table-wrap desktop-ledger">
            <table>
                <thead>
                <tr>
                    <th>
                        <a class="sort-link {{ $detailSort === 'date' ? 'active' : '' }}" href="{{ $sortUrl('detail', 'date', $detailSort, $detailDirection) }}">
                            日期 <span class="sort-mark">{{ $sortMark('date', $detailSort, $detailDirection) }}</span>
                        </a>
                    </th>
                    <th>
                        <a class="sort-link {{ $detailSort === 'etf' ? 'active' : '' }}" href="{{ $sortUrl('detail', 'etf', $detailSort, $detailDirection) }}">
                            ETF <span class="sort-mark">{{ $sortMark('etf', $detailSort, $detailDirection) }}</span>
                        </a>
                    </th>
                    <th>
                        <a class="sort-link {{ $detailSort === 'action' ? 'active' : '' }}" href="{{ $sortUrl('detail', 'action', $detailSort, $detailDirection) }}">
                            操作 <span class="sort-mark">{{ $sortMark('action', $detailSort, $detailDirection) }}</span>
                        </a>
                    </th>
                    <th class="numeric-cell">
                        <a class="sort-link {{ $detailSort === 'change_lots' ? 'active' : '' }}" href="{{ $sortUrl('detail', 'change_lots', $detailSort, $detailDirection) }}">
                            變動張數 <span class="sort-mark">{{ $sortMark('change_lots', $detailSort, $detailDirection) }}</span>
                        </a>
                    </th>
                    <th class="numeric-cell">
                        <a class="sort-link {{ $detailSort === 'amount' ? 'active' : '' }}" href="{{ $sortUrl('detail', 'amount', $detailSort, $detailDirection) }}">
                            總金額 <span class="sort-mark">{{ $sortMark('amount', $detailSort, $detailDirection) }}</span>
                        </a>
                    </th>
                    <th>
                        <a class="sort-link {{ $detailSort === 'stock' ? 'active' : '' }}" href="{{ $sortUrl('detail', 'stock', $detailSort, $detailDirection) }}">
                            成分股 <span class="sort-mark">{{ $sortMark('stock', $detailSort, $detailDirection) }}</span>
                        </a>
                    </th>
                </tr>
                </thead>
                <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td>{{ $item->operation_date->toDateString() }}</td>
                        <td>
                            <div class="etf-line">
                                <strong>{{ $item->etf_code }}</strong>
                                <span>{{ $item->etf_name }}</span>
                            </div>
                        </td>
                        <td><span class="action-badge {{ $actionClass($item->action) }}">{{ $item->action_label }}</span></td>
                        <td class="numeric-cell"><span class="change-lots {{ $changeClass($item->change_lots) }}">{{ $formatLots($item->change_lots) }}</span></td>
                        <td class="numeric-cell amount-cell">
                            <span class="amount-main {{ $changeClass($item->change_lots) }}">{{ $formatTradeValue($item->operation_total_amount) }}</span>
                            <span class="amount-sub">{{ $formatAmountPrice($item->operation_close_price, $item->operation_price_date) }}</span>
                        </td>
                        <td>
                            <div class="stock-line">
                                <strong>{{ $item->stock_name }}</strong>
                                <span>{{ $item->stock_code }}</span>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="mobile-operations">
            @foreach ($items as $item)
                <article class="operation-card">
                    <div class="operation-card-head">
                        <div class="etf-line">
                            <strong>{{ $item->etf_code }} {{ $item->etf_name }}</strong>
                            <span>{{ $item->operation_date->toDateString() }}</span>
                        </div>
                        <span class="action-badge {{ $actionClass($item->action) }}">{{ $item->action_label }}</span>
                    </div>
                    <div class="operation-card-body">
                        <div class="stock-line">
                            <strong>{{ $item->stock_name }}</strong>
                            <span>{{ $item->stock_code }}</span>
                        </div>
                        <div class="operation-metrics">
                            <div class="mobile-metric">
                                <span>張數</span>
                                <strong class="{{ $changeClass($item->change_lots) }}">{{ $formatLots($item->change_lots) }}</strong>
                            </div>
                            <div class="mobile-metric">
                                <span>總金額</span>
                                <strong class="{{ $changeClass($item->change_lots) }}">{{ $formatTradeValue($item->operation_total_amount) }}</strong>
                            </div>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</section>
