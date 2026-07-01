<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TwActiveEtf extends Model
{
    use HasFactory;

    protected $table = 'tw_active_etfs';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'source_payload' => 'array',
        'quote_date' => 'date',
        'close_price' => 'decimal:4',
        'previous_close_price' => 'decimal:4',
        'price_change_amount' => 'decimal:4',
        'price_change_percent' => 'decimal:4',
        'volume_lots' => 'integer',
        'volume_shares' => 'integer',
        'trade_value' => 'integer',
        'transaction_count' => 'integer',
        'quote_payload' => 'array',
        'fetched_at' => 'datetime',
        'quote_fetched_at' => 'datetime',
    ];
}
