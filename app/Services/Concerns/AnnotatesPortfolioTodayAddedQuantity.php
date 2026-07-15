<?php

namespace App\Services\Concerns;

use Carbon\CarbonImmutable;

trait AnnotatesPortfolioTodayAddedQuantity
{
    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $snapshotModel
     * @return array<int, mixed>|null
     */
    private function previousDailySnapshotRows(string $snapshotModel, CarbonImmutable $now): ?array
    {
        $snapshot = $snapshotModel::query()
            ->where('snapshot_date', '<', $now->toDateString())
            ->orderByDesc('snapshot_date')
            ->first(['rows']);

        if ($snapshot === null) {
            return null;
        }

        return is_array($snapshot->rows) ? $snapshot->rows : [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, mixed>|null $previousRows
     * @return array<int, array<string, mixed>>
     */
    private function annotateTodayAddedQuantities(array $rows, ?array $previousRows): array
    {
        if ($previousRows === null) {
            return array_map(function (array $row): array {
                $row['todayAddedQuantity'] = null;

                return $row;
            }, $rows);
        }

        $remainingPreviousQuantities = [];
        foreach ($previousRows as $previousRow) {
            if (!is_array($previousRow)) {
                continue;
            }

            $key = $this->portfolioPositionKey($previousRow);
            if ($key === null) {
                continue;
            }

            $remainingPreviousQuantities[$key] = ($remainingPreviousQuantities[$key] ?? 0.0)
                + max(0.0, $this->number($previousRow['quantity'] ?? 0));
        }

        return array_map(function (array $row) use (&$remainingPreviousQuantities): array {
            $key = $this->portfolioPositionKey($row);
            $quantity = max(0.0, $this->number($row['quantity'] ?? 0));
            if ($key === null || $quantity <= 0) {
                $row['todayAddedQuantity'] = null;

                return $row;
            }

            $previousQuantity = max(0.0, $remainingPreviousQuantities[$key] ?? 0.0);
            $coveredQuantity = min($quantity, $previousQuantity);
            $remainingPreviousQuantities[$key] = max(0.0, $previousQuantity - $coveredQuantity);
            $addedQuantity = $quantity - $coveredQuantity;
            $row['todayAddedQuantity'] = $addedQuantity > 0 ? $addedQuantity : null;

            return $row;
        }, $rows);
    }

    /**
     * Match the same displayed broker position while allowing internal position
     * flags to change without marking the entire holding as newly purchased.
     */
    private function portfolioPositionKey(array $row): ?string
    {
        $stockNo = strtoupper(trim((string) ($row['stockNo'] ?? '')));
        if ($stockNo === '') {
            return null;
        }

        return $stockNo . '|' . trim((string) ($row['tradeType'] ?? ''));
    }
}
