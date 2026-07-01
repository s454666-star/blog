<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwActiveEtfOperationItem extends Model
{
    use HasFactory;

    protected $table = 'tw_active_etf_operation_items';

    protected $guarded = [];

    protected $casts = [
        'operation_date' => 'date',
        'change_shares' => 'integer',
        'change_lots' => 'float',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(TwActiveEtfOperationReport::class, 'report_id');
    }
}
