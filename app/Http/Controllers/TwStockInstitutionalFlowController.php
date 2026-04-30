<?php

namespace App\Http\Controllers;

use App\Models\TwStockInstitutionalFlow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TwStockInstitutionalFlowController extends Controller
{
    public function index(Request $request): View
    {
        $allowedDays = [20, 30, 40, 60];
        $days = (int) $request->query('days', 60);
        if (!in_array($days, $allowedDays, true)) {
            $days = 60;
        }

        $allRows = TwStockInstitutionalFlow::query()
            ->orderBy('trade_date')
            ->get();

        $foreignCumulative = 0;
        $investmentTrustCumulative = 0;

        $preparedRows = $allRows->map(function (TwStockInstitutionalFlow $row) use (&$foreignCumulative, &$investmentTrustCumulative): array {
            $foreignCumulative += (int) ($row->foreign_stock_net_amount ?? 0);
            $investmentTrustCumulative += (int) ($row->investment_trust_stock_net_amount ?? 0);

            return [
                'date' => $row->trade_date->toDateString(),
                'foreign_stock_net_amount' => $row->foreign_stock_net_amount,
                'investment_trust_stock_net_amount' => $row->investment_trust_stock_net_amount,
                'foreign_stock_net_100m' => $this->amountTo100m($row->foreign_stock_net_amount),
                'investment_trust_stock_net_100m' => $this->amountTo100m($row->investment_trust_stock_net_amount),
                'foreign_cumulative_100m' => $this->amountTo100m($foreignCumulative),
                'investment_trust_cumulative_100m' => $this->amountTo100m($investmentTrustCumulative),
                'foreign_txf_open_interest_net_contracts' => $row->foreign_txf_open_interest_net_contracts,
                'investment_trust_txf_open_interest_net_contracts' => $row->investment_trust_txf_open_interest_net_contracts,
                'fetched_at' => $row->fetched_at?->format('Y-m-d H:i:s'),
            ];
        });

        $visibleRows = $preparedRows->take(-$days)->values();
        $latest = $visibleRows->last();

        return view('tw-stock.institutional-flows', [
            'allowedDays' => $allowedDays,
            'days' => $days,
            'rows' => $visibleRows,
            'latest' => $latest,
            'firstStoredDate' => $preparedRows->first()['date'] ?? null,
            'lastStoredDate' => $preparedRows->last()['date'] ?? null,
            'totalRows' => $preparedRows->count(),
            'chartData' => [
                'windowSize' => $days,
                'initialStartIndex' => max($preparedRows->count() - $days, 0),
                'labels' => $preparedRows->pluck('date')->all(),
                'foreignNet' => $preparedRows->pluck('foreign_stock_net_100m')->all(),
                'investmentTrustNet' => $preparedRows->pluck('investment_trust_stock_net_100m')->all(),
                'foreignCumulative' => $preparedRows->pluck('foreign_cumulative_100m')->all(),
                'investmentTrustCumulative' => $preparedRows->pluck('investment_trust_cumulative_100m')->all(),
                'foreignOpenInterest' => $preparedRows->pluck('foreign_txf_open_interest_net_contracts')->all(),
                'investmentTrustOpenInterest' => $preparedRows->pluck('investment_trust_txf_open_interest_net_contracts')->all(),
            ],
        ]);
    }

    private function amountTo100m(?int $amount): ?float
    {
        if ($amount === null) {
            return null;
        }

        return round($amount / 100_000_000, 2);
    }
}
