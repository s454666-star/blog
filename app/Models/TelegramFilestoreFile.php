<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramFilestoreFile extends Model
{
    protected $table = 'telegram_filestore_files';

    public $timestamps = false;

    protected $fillable = [
        'session_id',
        'chat_id',
        'message_id',
        'file_id',
        'file_unique_id',
        'file_name',
        'mime_type',
        'file_size',
        'file_type',
        'raw_payload',
        'created_at',
    ];

    protected $casts = [
        'session_id' => 'integer',
        'chat_id' => 'integer',
        'message_id' => 'integer',
        'file_size' => 'integer',
        'raw_payload' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TelegramFilestoreSession::class, 'session_id', 'id');
    }
}
