<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YuantaPortfolioDailySnapshot extends Model
{
    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'date',
        'captured_at' => 'datetime',
        'queried_at' => 'datetime',
        'source_age_seconds' => 'integer',
        'stock_count' => 'integer',
        'share_count' => 'float',
        'market_value' => 'float',
        'cost_basis' => 'float',
        'today_pnl' => 'float',
        'unrealized_pnl' => 'float',
        'bank_balance' => 'float',
        'margin_used_amount' => 'float',
        'margin_available_amount' => 'float',
        'summary' => 'array',
        'rows' => 'array',
        'payload' => 'array',
    ];
}
