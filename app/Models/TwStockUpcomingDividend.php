<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockUpcomingDividend extends Model
{
    use HasFactory;

    protected $table = 'tw_stock_upcoming_dividends';

    protected $guarded = [];

    protected $casts = [
        'ex_dividend_date' => 'date',
        'cash_dividend' => 'float',
        'stock_dividend' => 'float',
        'latest_close_price' => 'float',
        'latest_price_date' => 'date',
        'price_20_days_ago' => 'float',
        'price_20_days_ago_date' => 'date',
        'price_change_20_days_percent' => 'float',
        'dividend_yield_percent' => 'float',
        'days_until_ex_dividend' => 'integer',
        'last_ex_dividend_date' => 'date',
        'last_ex_dividend_cash_dividend' => 'float',
        'last_ex_dividend_before_price' => 'float',
        'last_fill_date' => 'date',
        'last_fill_days' => 'integer',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
