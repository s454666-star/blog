<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwFuturesDailyPrice extends Model
{
    use HasFactory;

    protected $table = 'tw_futures_daily_prices';

    protected $guarded = [];

    protected $casts = [
        'trade_date' => 'date',
        'open_price' => 'float',
        'high_price' => 'float',
        'low_price' => 'float',
        'close_price' => 'float',
        'settlement_price' => 'float',
        'volume_contracts' => 'integer',
        'open_interest' => 'integer',
        'source_payload' => 'array',
        'verified_sources' => 'array',
        'fetched_at' => 'datetime',
    ];
}
