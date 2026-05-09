<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockValuationGroup extends Model
{
    use HasFactory;

    protected $table = 'tw_stock_valuation_groups';

    protected $guarded = [];

    protected $casts = [
        'average_pe' => 'float',
        'market_reference_pe' => 'float',
        'source_date' => 'date',
        'sort_order' => 'integer',
    ];
}
