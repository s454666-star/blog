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
        'twse_payload' => 'array',
        'taifex_payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
