<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwFuturesHourlyPrice extends Model
{
    use HasFactory;

    protected $table = 'tw_futures_hourly_prices';

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'started_at_unix' => 'integer',
        'trade_date' => 'date',
        'open_price' => 'float',
        'high_price' => 'float',
        'low_price' => 'float',
        'close_price' => 'float',
        'volume_contracts' => 'integer',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
