<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramResourceCode extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_COMPLETED = 2;

    protected $table = 'telegram_resource_codes';

    protected $fillable = [
        'code',
        'code_type',
        'status',
        'source_peer_id',
        'source_message_id',
        'attempts',
        'processing_account',
        'forwarded_message_count',
        'available_at',
        'processing_started_at',
        'completed_at',
    ];

    protected $casts = [
        'code_type' => 'integer',
        'status' => 'integer',
        'source_peer_id' => 'integer',
        'source_message_id' => 'integer',
        'attempts' => 'integer',
        'processing_account' => 'integer',
        'forwarded_message_count' => 'integer',
        'available_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
