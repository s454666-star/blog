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
        'fetched_at' => 'datetime',
    ];
}
