<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockDailyPrice extends Model
{
    use HasFactory;

    protected $table = 'tw_stock_daily_prices';

    protected $guarded = [];

    protected $casts = [
        'trade_date' => 'date',
        'open_price' => 'float',
        'high_price' => 'float',
        'low_price' => 'float',
        'close_price' => 'float',
        'previous_close_price' => 'float',
        'price_change_amount' => 'float',
        'price_change_percent' => 'float',
        'volume_lots' => 'integer',
        'volume_shares' => 'integer',
        'trade_value' => 'integer',
        'transaction_count' => 'integer',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
