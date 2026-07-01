<?php

namespace App\Http\Controllers;

use App\Models\TwActiveEtf;
use App\Models\TwActiveEtfOperationItem;
use App\Models\TwActiveEtfOperationReport;
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

    public function index(Request $request): View
    {
        [$from, $to] = $this->resolveDateRange($request);
        $selectedEtf = strtoupper(trim((string) $request->query('etf', '')));
        $selectedAction = (string) $request->query('action', 'all');
        if (!array_key_exists($selectedAction, self::ACTIONS)) {
            $selectedAction = 'all';
        }

        $keyword = trim((string) $request->query('q', ''));

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

        $items = $itemQuery
            ->orderByDesc('operation_date')
            ->orderBy('etf_code')
            ->orderByRaw("CASE action WHEN 'new' THEN 1 WHEN 'add' THEN 2 WHEN 'reduce' THEN 3 WHEN 'remove' THEN 4 ELSE 5 END")
            ->orderByDesc('change_lots')
            ->get();

        $activeEtfs = TwActiveEtf::query()
            ->where('is_active', true)
            ->orderBy('stock_code')
            ->get(['stock_code', 'stock_name', 'etf_category']);

        $actionCounts = $items
            ->groupBy('action')
            ->map(fn (Collection $group): int => $group->count());

        $etfCards = $this->buildEtfCards($reports, $items);

        return view('tw-stock.active-etf-operations', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'actions' => self::ACTIONS,
            'selectedEtf' => $selectedEtf,
            'selectedAction' => $selectedAction,
            'keyword' => $keyword,
            'activeEtfs' => $activeEtfs,
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
     * @param Collection<int, TwActiveEtfOperationReport> $reports
     * @param Collection<int, TwActiveEtfOperationItem> $items
     * @return Collection<int, array<string, mixed>>
     */
    private function buildEtfCards(Collection $reports, Collection $items): Collection
    {
        $itemsByEtf = $items->groupBy('etf_code');

        return $reports
            ->groupBy('etf_code')
            ->map(function (Collection $etfReports, string $code) use ($itemsByEtf): array {
                $first = $etfReports->first();
                $etfItems = $itemsByEtf->get($code, collect());
                $counts = $etfItems->groupBy('action')->map(fn (Collection $group): int => $group->count());

                return [
                    'etf_code' => $code,
                    'etf_name' => (string) $first->etf_name,
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
