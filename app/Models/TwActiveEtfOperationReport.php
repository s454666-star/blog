<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TwActiveEtfOperationReport extends Model
{
    use HasFactory;

    protected $table = 'tw_active_etf_operation_reports';

    protected $guarded = [];

    protected $casts = [
        'operation_date' => 'date',
        'source_row_count' => 'integer',
        'changed_row_count' => 'integer',
        'source_payload' => 'array',
        'fetched_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(TwActiveEtfOperationItem::class, 'report_id');
    }
}
