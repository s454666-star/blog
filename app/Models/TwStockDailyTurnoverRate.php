<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockDailyTurnoverRate extends Model
{
    use HasFactory;

    protected $table = 'tw_stock_daily_turnover_rates';

    protected $guarded = [];

    protected $casts = [
        'trade_date' => 'date',
        'rank' => 'integer',
        'trading_shares' => 'integer',
        'issued_shares' => 'integer',
        'turnover_rate_percent' => 'float',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
