<?php

namespace App\Models;

use App\Services\TwStockQ1ValuationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockQ1FinancialReport extends Model
{
    use HasFactory;

    private const MIN_REASONABLE_PE_RATIO = 8.0;

    private const MAX_REASONABLE_PE_RATIO = 72.0;

    private const MAX_REVENUE_MOMENTUM_PE_ADJUSTMENT_PERCENT = 20.0;

    protected $table = 'tw_stock_q1_financial_reports';

    protected $guarded = [];

    protected $casts = [
        'fiscal_year' => 'integer',
        'quarter' => 'integer',
        'q1_revenue_billion' => 'float',
        'q1_revenue_yoy_percent' => 'float',
        'valuation_group_pe' => 'float',
        'q1_revenue_score' => 'float',
        'q1_eps' => 'float',
        'q1_eps_yoy_percent' => 'float',
        'q1_gross_margin_percent' => 'float',
        'q1_operating_margin_percent' => 'float',
        'q1_net_margin_percent' => 'float',
        'q1_net_income_billion' => 'float',
        'roe_percent' => 'float',
        'roa_percent' => 'float',
        'operating_profit_mix_percent' => 'float',
        'recent_monthly_revenues' => 'array',
        'latest_close_price' => 'float',
        'latest_price_date' => 'date',
        'volume_lots' => 'integer',
        'price_change_1d_percent' => 'float',
        'price_change_5d_percent' => 'float',
        'price_change_20d_percent' => 'float',
        'rank' => 'integer',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function annualizedQ1Eps(): ?float
    {
        if ($this->q1_eps === null) {
            return null;
        }

        return (float) $this->q1_eps * 4;
    }

    public function reasonablePeRatio(): ?float
    {
        $groupPe = $this->valuation_group_pe === null
            ? TwStockQ1ValuationService::MARKET_REFERENCE_PE
            : (float) $this->valuation_group_pe;

        if ($groupPe <= 0) {
            return null;
        }

        $scoreAdjustedPe = $groupPe;
        if ($this->q1_revenue_score !== null) {
            $score = max(0.0, min(100.0, (float) $this->q1_revenue_score));
            $scoreMultiplier = 0.80 + (($score / 100) * 0.45);
            $scoreAdjustedPe = $groupPe * $scoreMultiplier;
        }

        return max(
            self::MIN_REASONABLE_PE_RATIO,
            min(self::MAX_REASONABLE_PE_RATIO, $scoreAdjustedPe * $this->revenueMomentumPeMultiplier()),
        );
    }

    public function latestMonthlyRevenueVsQ1AveragePercent(): ?float
    {
        $q1AverageRevenue = $this->q1AverageMonthlyRevenueBillion();
        $latestMonthlyRevenue = $this->latestPostQuarterMonthlyRevenueBillion();

        if ($q1AverageRevenue === null || $q1AverageRevenue <= 0 || $latestMonthlyRevenue === null) {
            return null;
        }

        return (($latestMonthlyRevenue - $q1AverageRevenue) / $q1AverageRevenue) * 100;
    }

    public function revenueMomentumPeAdjustmentPercent(): ?float
    {
        $momentumPercent = $this->latestMonthlyRevenueVsQ1AveragePercent();

        if ($momentumPercent === null) {
            return null;
        }

        $clampedMomentum = max(-50.0, min(50.0, $momentumPercent));

        return ($clampedMomentum / 50.0) * self::MAX_REVENUE_MOMENTUM_PE_ADJUSTMENT_PERCENT;
    }

    public function expectedPrice(): ?float
    {
        $annualizedEps = $this->annualizedQ1Eps();
        $peRatio = $this->reasonablePeRatio();

        if ($annualizedEps === null || $annualizedEps <= 0 || $peRatio === null) {
            return null;
        }

        return $annualizedEps * $peRatio;
    }

    public function expectedPriceChangePercent(): ?float
    {
        $expectedPrice = $this->expectedPrice();

        if ($expectedPrice === null || $this->latest_close_price === null || (float) $this->latest_close_price <= 0) {
            return null;
        }

        return (($expectedPrice - (float) $this->latest_close_price) / (float) $this->latest_close_price) * 100;
    }

    private function revenueMomentumPeMultiplier(): float
    {
        $adjustmentPercent = $this->revenueMomentumPeAdjustmentPercent();

        if ($adjustmentPercent === null) {
            return 1.0;
        }

        return 1.0 + ($adjustmentPercent / 100.0);
    }

    private function q1AverageMonthlyRevenueBillion(): ?float
    {
        if ($this->q1_revenue_billion === null || (float) $this->q1_revenue_billion <= 0) {
            return null;
        }

        return (float) $this->q1_revenue_billion / 3.0;
    }

    private function latestPostQuarterMonthlyRevenueBillion(): ?float
    {
        if (!is_array($this->recent_monthly_revenues)) {
            return null;
        }

        $quarterEndYearMonth = $this->quarterEndYearMonth();
        if ($quarterEndYearMonth === null) {
            return null;
        }

        $latestYearMonth = null;
        $latestRevenue = null;

        foreach ($this->recent_monthly_revenues as $monthlyRevenue) {
            if (!is_array($monthlyRevenue)) {
                continue;
            }

            $yearMonth = (string) ($monthlyRevenue['year_month'] ?? '');
            if (!preg_match('/^\d{6}$/', $yearMonth) || $yearMonth <= $quarterEndYearMonth) {
                continue;
            }

            $revenue = $monthlyRevenue['revenue_billion'] ?? null;
            if ($revenue === null || !is_numeric($revenue)) {
                continue;
            }

            if ($latestYearMonth === null || $yearMonth > $latestYearMonth) {
                $latestYearMonth = $yearMonth;
                $latestRevenue = (float) $revenue;
            }
        }

        return $latestRevenue;
    }

    private function quarterEndYearMonth(): ?string
    {
        if ($this->fiscal_year === null || $this->quarter === null) {
            return null;
        }

        $quarter = max(1, min(4, (int) $this->quarter));

        return sprintf('%04d%02d', (int) $this->fiscal_year, $quarter * 3);
    }
}
