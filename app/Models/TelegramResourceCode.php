<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramResourceCode extends Model
{
    public const STATUS_PENDING = 0;
    public const STATUS_PROCESSING = 1;
    public const STATUS_COMPLETED = 2;
    public const STATUS_SKIPPED = 3;

    public const SKIP_REASON_DORMANT = 1;

    protected $table = 'telegram_resource_codes';

    protected $fillable = [
        'code',
        'code_type',
        'status',
        'skip_reason',
        'source_peer_id',
        'source_message_id',
        'attempts',
        'processing_account',
        'forwarded_message_count',
        'available_at',
        'processing_started_at',
        'completed_at',
        'skipped_at',
    ];

    protected $casts = [
        'code_type' => 'integer',
        'status' => 'integer',
        'skip_reason' => 'integer',
        'source_peer_id' => 'integer',
        'source_message_id' => 'integer',
        'attempts' => 'integer',
        'processing_account' => 'integer',
        'forwarded_message_count' => 'integer',
        'available_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'skipped_at' => 'datetime',
    ];
}
