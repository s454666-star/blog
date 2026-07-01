<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockMonthlyRevenue extends Model
{
    use HasFactory;

    protected $table = 'tw_stock_monthly_revenues';

    protected $guarded = [];

    protected $casts = [
        'revenue_year' => 'integer',
        'revenue_month' => 'integer',
        'announced_date' => 'date',
        'monthly_revenue_thousands' => 'integer',
        'previous_month_revenue_thousands' => 'integer',
        'last_year_month_revenue_thousands' => 'integer',
        'month_over_month_percent' => 'float',
        'year_over_year_percent' => 'float',
        'mom_yoy_sum_percent' => 'float',
        'cumulative_revenue_thousands' => 'integer',
        'last_year_cumulative_revenue_thousands' => 'integer',
        'cumulative_yoy_percent' => 'float',
        'latest_price_date' => 'date',
        'latest_close_price' => 'float',
        'one_day_change_percent' => 'float',
        'five_day_change_percent' => 'float',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
