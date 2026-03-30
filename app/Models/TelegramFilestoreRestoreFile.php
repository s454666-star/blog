<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramFilestoreRestoreFile extends Model
{
    protected $table = 'telegram_filestore_restore_files';

    protected $fillable = [
        'restore_session_id',
        'source_session_id',
        'source_file_row_id',
        'source_chat_id',
        'source_message_id',
        'source_file_id',
        'source_file_unique_id',
        'source_token',
        'source_public_token',
        'forwarded_message_id',
        'target_chat_id',
        'target_message_id',
        'target_file_id',
        'target_file_unique_id',
        'file_name',
        'mime_type',
        'file_size',
        'file_type',
        'status',
        'attempt_count',
        'last_error',
        'raw_payload',
        'forwarded_at',
        'synced_at',
    ];

    protected $casts = [
        'restore_session_id' => 'integer',
        'source_session_id' => 'integer',
        'source_file_row_id' => 'integer',
        'source_chat_id' => 'integer',
        'source_message_id' => 'integer',
        'forwarded_message_id' => 'integer',
        'target_chat_id' => 'integer',
        'target_message_id' => 'integer',
        'file_size' => 'integer',
        'attempt_count' => 'integer',
        'raw_payload' => 'array',
        'forwarded_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function restoreSession(): BelongsTo
    {
        return $this->belongsTo(TelegramFilestoreRestoreSession::class, 'restore_session_id', 'id');
    }

    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(TelegramFilestoreSession::class, 'source_session_id', 'id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(TelegramFilestoreFile::class, 'source_file_row_id', 'id');
    }
}
