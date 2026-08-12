<?php

namespace App\Services;

class PortfolioDividendCalculator
{
    /** @return array{quantity: float, method: string} */
    public function esunEligibleQuantity(string $stockCode, string $exDate, array $inventories, array $transactions): array
    {
        $quantity = 0.0;
        foreach ($inventories as $row) {
            if (!is_array($row) || $this->code($row) !== $stockCode || !$this->isLong($row)) {
                continue;
            }
            $quantity += $this->number($this->value($row, 'cost_qty', 'costQty', 'qty_b', 'qtyB', 'quantity'));
        }

        foreach ($transactions as $row) {
            if (!is_array($row) || $this->code($row) !== $stockCode || !$this->isLong($row)
                || $this->date($row, 't_date', 'tDate', 'trade_date', 'tradeDate', 'date') < $exDate) {
                continue;
            }
            $tradeQuantity = $this->number($this->value($row, 'qty', 'quantity', 'cost_qty', 'costQty'));
            $side = strtoupper((string) $this->value($row, 'buy_sell', 'buySell', 'side'));
            if ($side === 'B') {
                $quantity -= $tradeQuantity;
            } elseif ($side === 'S') {
                $quantity += $tradeQuantity;
            }
        }

        return ['quantity' => max(0.0, $quantity), 'method' => 'current_inventory_reversed_by_trades'];
    }

    /** @return array{quantity: float, method: string} */
    public function yuantaEligibleQuantity(string $stockCode, string $exDate, array $openLots, array $transactions): array
    {
        $quantity = 0.0;
        foreach ($openLots as $row) {
            $purchaseDate = is_array($row) ? $this->date($row, 'TradeDate', 'tradeDate', 'date') : '';
            if (!is_array($row) || $this->code($row) !== $stockCode || !$this->isLong($row)
                || $purchaseDate === '' || $purchaseDate >= $exDate) {
                continue;
            }
            $quantity += $this->number($this->value($row, 'StockQty', 'stockQty', 'Qty', 'qty', 'quantity'));
        }

        foreach ($transactions as $sale) {
            if (!is_array($sale) || $this->code($sale) !== $stockCode || !$this->isLong($sale)
                || $this->date($sale, 'TradeDate', 'tradeDate', 'date') < $exDate) {
                continue;
            }
            $reversals = $this->value($sale, 'ReversalReports', 'reversalReports');
            foreach (is_array($reversals) ? $reversals : [] as $row) {
                if (is_array($row) && $this->date($row, 'ReversalDate', 'reversalDate', 'TradeDate', 'tradeDate') < $exDate) {
                    $quantity += $this->number($this->value($row, 'ReversalQty', 'reversalQty', 'Qty', 'qty'));
                }
            }
        }

        return ['quantity' => max(0.0, $quantity), 'method' => 'open_lots_plus_realized_reversals'];
    }

    private function code(array $row): string
    {
        return strtoupper(preg_replace('/[^0-9A-Z]/i', '', (string) $this->value(
            $row, 'stk_no', 'stkNo', 'stockNo', 'StkCode', 'stkCode', 'stock_code',
        )) ?? '');
    }

    private function isLong(array $row): bool
    {
        $type = (string) $this->value($row, 'trade', 'tradeType', 'TradeKind', 'tradeKind');

        return $type === '' || in_array($type, ['0', '3'], true);
    }

    private function date(array $row, string ...$keys): string
    {
        $value = preg_replace('/\D+/', '', (string) $this->value($row, ...$keys)) ?? '';

        return strlen($value) >= 8 ? substr($value, 0, 4) . '-' . substr($value, 4, 2) . '-' . substr($value, 6, 2) : '';
    }

    private function value(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
