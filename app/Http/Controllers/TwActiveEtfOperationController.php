<?php

namespace App\Http\Controllers;

use App\Models\TwActiveEtf;
use App\Models\TwActiveEtfOperationItem;
use App\Models\TwActiveEtfOperationReport;
use App\Models\TwStockDailyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TwActiveEtfOperationController extends Controller
{
    private const ACTIONS = [
        'all' => '全部',
        'new' => '新增',
        'add' => '加碼',
        'reduce' => '減碼',
        'remove' => '刪除',
    ];

    private const MARKET_SORTS = [
        'stock' => 'stock_code',
        'name' => 'stock_name',
        'exchange' => 'exchange',
        'price' => 'close_price',
        'change' => 'price_change_amount',
        'change_percent' => 'price_change_percent',
        'volume' => 'volume_lots',
        'trade_value' => 'trade_value',
        'quote_date' => 'quote_date',
    ];

    private const DETAIL_SORTS = [
        'date' => 'operation_date',
        'etf' => 'etf_code',
        'action' => 'action',
        'change_lots' => 'change_lots',
        'amount' => 'operation_total_amount',
        'stock' => 'stock_code',
    ];

    public function index(Request $request): View
    {
        [$from, $to] = $this->resolveDateRange($request);
        $selectedEtf = strtoupper(trim((string) $request->query('etf', '')));
        $selectedAction = (string) $request->query('action', 'all');
        if (!array_key_exists($selectedAction, self::ACTIONS)) {
            $selectedAction = 'all';
        }

        $keyword = trim((string) $request->query('q', ''));
        [$marketSort, $marketDirection] = $this->resolveSort($request, 'market_sort', 'market_dir', self::MARKET_SORTS, 'volume', 'desc');
        [$detailSort, $detailDirection] = $this->resolveSort($request, 'detail_sort', 'detail_dir', self::DETAIL_SORTS, 'amount', 'desc');

        $reportQuery = TwActiveEtfOperationReport::query()
            ->whereBetween('operation_date', [$from->toDateString(), $to->toDateString()]);

        if ($selectedEtf !== '') {
            $reportQuery->where('etf_code', $selectedEtf);
        }

        $reports = $reportQuery
            ->withCount('items')
            ->orderByDesc('operation_date')
            ->orderBy('etf_code')
            ->get();

        $cardReportQuery = TwActiveEtfOperationReport::query()
            ->whereBetween('operation_date', [$from->toDateString(), $to->toDateString()]);

        $cardReports = $cardReportQuery
            ->withCount('items')
            ->orderByDesc('operation_date')
            ->orderBy('etf_code')
            ->get();

        $itemQuery = TwActiveEtfOperationItem::query()
            ->whereBetween('operation_date', [$from->toDateString(), $to->toDateString()]);

        if ($selectedEtf !== '') {
            $itemQuery->where('etf_code', $selectedEtf);
        }

        if ($selectedAction !== 'all') {
            $itemQuery->where('action', $selectedAction);
        }

        if ($keyword !== '') {
            $itemQuery->where(function (Builder $query) use ($keyword): void {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
                $query
                    ->where('etf_code', 'like', $like)
                    ->orWhere('etf_name', 'like', $like)
                    ->orWhere('stock_code', 'like', $like)
                    ->orWhere('stock_name', 'like', $like);
            });
        }

        $cardItemQuery = TwActiveEtfOperationItem::query()
            ->whereBetween('operation_date', [$from->toDateString(), $to->toDateString()]);

        if ($selectedAction !== 'all') {
            $cardItemQuery->where('action', $selectedAction);
        }

        if ($keyword !== '') {
            $cardItemQuery->where(function (Builder $query) use ($keyword): void {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
                $query
                    ->where('etf_code', 'like', $like)
                    ->orWhere('etf_name', 'like', $like)
                    ->orWhere('stock_code', 'like', $like)
                    ->orWhere('stock_name', 'like', $like);
            });
        }

        $items = $this->sortDetailItems(
            $this->attachOperationAmounts($itemQuery->get()),
            $detailSort,
            $detailDirection,
        );
        $cardItems = $cardItemQuery->get();

        $activeEtfs = TwActiveEtf::query()
            ->where('is_active', true)
            ->orderBy('stock_code')
            ->get(['stock_code', 'stock_name', 'etf_category', 'exchange']);

        $quoteEtfs = TwActiveEtf::query()
            ->where('is_active', true)
            ->get([
                'stock_code',
                'stock_name',
                'exchange',
                'quote_date',
                'close_price',
                'previous_close_price',
                'price_change_amount',
                'price_change_percent',
                'volume_lots',
                'volume_shares',
                'trade_value',
                'transaction_count',
                'quote_source',
                'quote_fetched_at',
            ])
            ->keyBy('stock_code');

        $marketEtfQuery = TwActiveEtf::query()->where('is_active', true);
        if ($keyword !== '') {
            $marketEtfQuery->where(function (Builder $query) use ($keyword): void {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $keyword) . '%';
                $query
                    ->where('stock_code', 'like', $like)
                    ->orWhere('stock_name', 'like', $like);
            });
        }

        $marketEtfs = $this->applyMarketSort($marketEtfQuery, $marketSort, $marketDirection)
            ->get([
                'stock_code',
                'stock_name',
                'exchange',
                'etf_category',
                'quote_date',
                'close_price',
                'previous_close_price',
                'price_change_amount',
                'price_change_percent',
                'volume_lots',
                'volume_shares',
                'trade_value',
                'transaction_count',
                'quote_source',
                'quote_fetched_at',
            ]);

        $actionCounts = $items
            ->groupBy('action')
            ->map(fn (Collection $group): int => $group->count());

        $etfCards = $this->buildEtfCards($cardReports, $cardItems, $quoteEtfs);

        return view('tw-stock.active-etf-operations', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'actions' => self::ACTIONS,
            'selectedEtf' => $selectedEtf,
            'selectedAction' => $selectedAction,
            'keyword' => $keyword,
            'marketSort' => $marketSort,
            'marketDirection' => $marketDirection,
            'detailSort' => $detailSort,
            'detailDirection' => $detailDirection,
            'activeEtfs' => $activeEtfs,
            'marketEtfs' => $marketEtfs,
            'reports' => $reports,
            'items' => $items,
            'etfCards' => $etfCards,
            'summary' => [
                'report_count' => $reports->count(),
                'etf_count' => $reports->pluck('etf_code')->unique()->count(),
                'item_count' => $items->count(),
                'new_count' => (int) ($actionCounts['new'] ?? 0),
                'add_count' => (int) ($actionCounts['add'] ?? 0),
                'reduce_count' => (int) ($actionCounts['reduce'] ?? 0),
                'remove_count' => (int) ($actionCounts['remove'] ?? 0),
                'no_change_count' => $reports->filter(fn ($report): bool => (int) $report->items_count === 0)->count(),
                'latest_operation_date' => optional($reports->first())->operation_date?->toDateString(),
                'latest_fetched_at' => optional($reports->max('fetched_at'))?->format('Y-m-d H:i'),
            ],
        ]);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolveDateRange(Request $request): array
    {
        $default = CarbonImmutable::yesterday((string) config('app.timezone'));
        $from = $this->parseRequestDate((string) $request->query('from', ''), $default);
        $to = $this->parseRequestDate((string) $request->query('to', ''), $default);

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 120) {
            $from = $to->subDays(120);
        }

        return [$from, $to];
    }

    private function parseRequestDate(string $value, CarbonImmutable $default): CarbonImmutable
    {
        $value = trim(str_replace('/', '-', $value));
        if ($value === '') {
            return $default;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value, (string) config('app.timezone'));

            return $date instanceof CarbonImmutable ? $date->startOfDay() : $default;
        } catch (\Throwable) {
            return $default;
        }
    }

    /**
     * @param array<string, string> $allowed
     * @return array{string, string}
     */
    private function resolveSort(
        Request $request,
        string $sortKey,
        string $directionKey,
        array $allowed,
        string $defaultSort,
        string $defaultDirection,
    ): array {
        $sort = (string) $request->query($sortKey, $defaultSort);
        if (!array_key_exists($sort, $allowed)) {
            $sort = $defaultSort;
        }

        $direction = strtolower((string) $request->query($directionKey, $defaultDirection));
        if (!in_array($direction, ['asc', 'desc'], true)) {
            $direction = $defaultDirection;
        }

        return [$sort, $direction];
    }

    private function applyMarketSort(Builder $query, string $sort, string $direction): Builder
    {
        $column = self::MARKET_SORTS[$sort] ?? self::MARKET_SORTS['volume'];

        return $query
            ->orderBy($column, $direction)
            ->orderBy('stock_code');
    }

    /**
     * @param Collection<int, TwActiveEtfOperationItem> $items
     * @return Collection<int, TwActiveEtfOperationItem>
     */
    private function attachOperationAmounts(Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        $stockCodes = $items
            ->pluck('stock_code')
            ->filter()
            ->unique()
            ->values();

        $operationDates = $items
            ->pluck('operation_date')
            ->filter();

        $maxDate = CarbonImmutable::parse((string) $operationDates->max(), (string) config('app.timezone'));
        $minDate = CarbonImmutable::parse((string) $operationDates->min(), (string) config('app.timezone'))->subDays(21);

        $pricesByStock = TwStockDailyPrice::query()
            ->whereIn('stock_code', $stockCodes)
            ->whereBetween('trade_date', [$minDate->toDateString(), $maxDate->toDateString()])
            ->orderBy('stock_code')
            ->orderByDesc('trade_date')
            ->get(['stock_code', 'trade_date', 'close_price'])
            ->groupBy('stock_code');

        return $items->each(function (TwActiveEtfOperationItem $item) use ($pricesByStock): void {
            $operationDate = CarbonImmutable::parse($item->operation_date->toDateString(), (string) config('app.timezone'));
            $price = $pricesByStock
                ->get($item->stock_code, collect())
                ->first(fn (TwStockDailyPrice $price): bool => !$price->trade_date->gt($operationDate));

            $closePrice = $price?->close_price === null ? null : (float) $price->close_price;
            $changeLots = $item->change_lots === null ? null : (float) $item->change_lots;
            $totalAmount = $closePrice === null || $changeLots === null
                ? null
                : (int) round(abs($changeLots) * $closePrice * 1000);

            $item->setAttribute('operation_close_price', $closePrice);
            $item->setAttribute('operation_price_date', $price?->trade_date);
            $item->setAttribute('operation_total_amount', $totalAmount);
        });
    }

    /**
     * @param Collection<int, TwActiveEtfOperationItem> $items
     * @return Collection<int, TwActiveEtfOperationItem>
     */
    private function sortDetailItems(Collection $items, string $sort, string $direction): Collection
    {
        return $items
            ->sort(fn (TwActiveEtfOperationItem $left, TwActiveEtfOperationItem $right): int => $this->compareDetailItems($left, $right, $sort, $direction))
            ->values();
    }

    private function compareDetailItems(TwActiveEtfOperationItem $left, TwActiveEtfOperationItem $right, string $sort, string $direction): int
    {
        $primary = $this->compareNullable(
            $this->detailSortValue($left, $sort),
            $this->detailSortValue($right, $sort),
            $direction,
        );
        if ($primary !== 0) {
            return $primary;
        }

        foreach ([
            ['date', 'desc'],
            ['etf', 'asc'],
            ['action', 'asc'],
            ['amount', 'desc'],
            ['change_lots', 'desc'],
            ['stock', 'asc'],
        ] as [$fallbackSort, $fallbackDirection]) {
            if ($fallbackSort === $sort) {
                continue;
            }

            $comparison = $this->compareNullable(
                $this->detailSortValue($left, $fallbackSort),
                $this->detailSortValue($right, $fallbackSort),
                $fallbackDirection,
            );
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private function detailSortValue(TwActiveEtfOperationItem $item, string $sort): mixed
    {
        return match ($sort) {
            'date' => $item->operation_date?->toDateString(),
            'etf' => $item->etf_code,
            'action' => $this->actionRank((string) $item->action),
            'change_lots' => $item->change_lots,
            'amount' => $item->getAttribute('operation_total_amount'),
            'stock' => $item->stock_code,
            default => $item->getAttribute('operation_total_amount'),
        };
    }

    private function actionRank(string $action): int
    {
        return match ($action) {
            'new' => 1,
            'add' => 2,
            'reduce' => 3,
            'remove' => 4,
            default => 5,
        };
    }

    private function compareNullable(mixed $left, mixed $right, string $direction): int
    {
        $leftMissing = $left === null || $left === '';
        $rightMissing = $right === null || $right === '';

        if ($leftMissing || $rightMissing) {
            return $leftMissing === $rightMissing ? 0 : ($leftMissing ? 1 : -1);
        }

        $comparison = is_numeric($left) && is_numeric($right)
            ? ((float) $left <=> (float) $right)
            : strcmp((string) $left, (string) $right);

        return $direction === 'desc' ? -$comparison : $comparison;
    }

    /**
     * @param Collection<int, TwActiveEtfOperationReport> $reports
     * @param Collection<int, TwActiveEtfOperationItem> $items
     * @return Collection<int, array<string, mixed>>
     */
    private function buildEtfCards(Collection $reports, Collection $items, Collection $quoteEtfs): Collection
    {
        $itemsByEtf = $items->groupBy('etf_code');

        return $reports
            ->groupBy('etf_code')
            ->map(function (Collection $etfReports, string $code) use ($itemsByEtf, $quoteEtfs): array {
                $first = $etfReports->first();
                $etfItems = $itemsByEtf->get($code, collect());
                $counts = $etfItems->groupBy('action')->map(fn (Collection $group): int => $group->count());
                $quote = $quoteEtfs->get($code);

                return [
                    'etf_code' => $code,
                    'etf_name' => (string) $first->etf_name,
                    'exchange' => $quote?->exchange,
                    'quote_date' => $quote?->quote_date?->toDateString(),
                    'close_price' => $quote?->close_price,
                    'price_change_amount' => $quote?->price_change_amount,
                    'price_change_percent' => $quote?->price_change_percent,
                    'volume_lots' => $quote?->volume_lots,
                    'trade_value' => $quote?->trade_value,
                    'report_count' => $etfReports->count(),
                    'item_count' => $etfItems->count(),
                    'latest_operation_date' => $etfReports->max('operation_date')?->toDateString(),
                    'new_count' => (int) ($counts['new'] ?? 0),
                    'add_count' => (int) ($counts['add'] ?? 0),
                    'reduce_count' => (int) ($counts['reduce'] ?? 0),
                    'remove_count' => (int) ($counts['remove'] ?? 0),
                ];
            })
            ->sortByDesc('item_count')
            ->values();
    }
}
