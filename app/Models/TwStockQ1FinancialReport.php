<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockQ1FinancialReport extends Model
{
    use HasFactory;

    private const MIN_REASONABLE_PE_RATIO = 8.0;

    private const MAX_REASONABLE_PE_RATIO = 30.0;

    protected $table = 'tw_stock_q1_financial_reports';

    protected $guarded = [];

    protected $casts = [
        'fiscal_year' => 'integer',
        'quarter' => 'integer',
        'q1_revenue_billion' => 'float',
        'q1_revenue_yoy_percent' => 'float',
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
        if ($this->q1_revenue_score === null) {
            return null;
        }

        $score = max(0.0, min(100.0, (float) $this->q1_revenue_score));

        return self::MIN_REASONABLE_PE_RATIO
            + (($score / 100) * (self::MAX_REASONABLE_PE_RATIO - self::MIN_REASONABLE_PE_RATIO));
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
}
