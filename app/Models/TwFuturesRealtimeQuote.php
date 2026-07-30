<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TwFuturesRealtimeQuote extends Model
{
    protected $fillable = [
        'symbol',
        'price',
        'volume',
        'quote_at',
        'written_at',
        'bar_started_at',
        'bar_open',
        'bar_high',
        'bar_low',
        'source',
        'auth_mode',
        'source_payload',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'volume' => 'integer',
            'quote_at' => 'immutable_datetime',
            'written_at' => 'immutable_datetime',
            'bar_started_at' => 'immutable_datetime',
            'bar_open' => 'float',
            'bar_high' => 'float',
            'bar_low' => 'float',
            'source_payload' => 'array',
        ];
    }
}
