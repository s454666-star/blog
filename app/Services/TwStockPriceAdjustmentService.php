<?php

namespace App\Services;

class TwStockPriceAdjustmentService
{
    /**
     * Convert an unadjusted historical price to the share basis on $asOfDate.
     * Only use for raw exchange history, never already-adjusted Yahoo history,
     * realtime quotes, broker inventory, or persisted portfolio snapshots.
     */
    public function historicalPrice(string $stockCode, string $tradeDate, string $asOfDate, float $price): float
    {
        foreach (config('tw_stock.share_conversions.' . $stockCode, []) as $conversion) {
            $effectiveDate = (string) ($conversion['effective_date'] ?? '');
            $ratio = (float) ($conversion['shares_per_old_share'] ?? 0);
            if ($effectiveDate !== '' && $tradeDate < $effectiveDate && $effectiveDate <= $asOfDate
                && is_finite($ratio) && $ratio > 0) {
                $price /= $ratio;
            }
        }

        return $price;
    }
}
