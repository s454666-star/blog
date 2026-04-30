<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockInstitutionalFlow extends Model
{
    use HasFactory;

    protected $table = 'tw_stock_institutional_flows';

    protected $guarded = [];

    protected $casts = [
        'trade_date' => 'date',
        'taiex_open_index' => 'float',
        'taiex_high_index' => 'float',
        'taiex_low_index' => 'float',
        'taiex_close_index' => 'float',
        'twse_payload' => 'array',
        'taifex_payload' => 'array',
        'taiex_payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
