<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockQ1FinancialReport extends Model
{
    use HasFactory;

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
}
