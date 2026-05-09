<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwStockCompanyProfile extends Model
{
    use HasFactory;

    protected $table = 'tw_stock_company_profiles';

    protected $guarded = [];

    protected $casts = [
        'valuation_group_pe' => 'float',
        'source_date' => 'date',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];
}
