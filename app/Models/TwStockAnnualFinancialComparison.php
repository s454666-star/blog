<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockAnnualFinancialComparison extends Model
{
    use HasFactory;

    protected $table = 'tw_stock_annual_financial_comparisons';

    protected $guarded = [];

    protected $casts = [
        'context_year' => 'integer',
        'comparison_start_year' => 'integer',
        'comparison_end_year' => 'integer',
        'revenue_yoy_sum' => 'float',
        'eps_yoy_sum' => 'float',
        'recent_net_margin_average' => 'float',
        'last_two_year_net_margin_average' => 'float',
        'revenue_filter_pass' => 'boolean',
        'eps_filter_pass' => 'boolean',
        'eps_yoy_all_positive' => 'boolean',
        'net_margin_filter_pass' => 'boolean',
        'current_revenue_billion' => 'float',
        'current_revenue_months' => 'integer',
        'current_eps' => 'float',
        'current_q1_eps_yoy_percent' => 'float',
        'current_q1_revenue_yoy_percent' => 'float',
        'end_year_revenue_yoy_percent' => 'float',
        'latest_close_price' => 'float',
        'volume_lots' => 'integer',
        'comparisons' => 'array',
        'generated_at' => 'datetime',
    ];
}
