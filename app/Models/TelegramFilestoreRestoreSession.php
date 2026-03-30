<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramFilestoreRestoreSession extends Model
{
    protected $table = 'telegram_filestore_restore_sessions';

    protected $fillable = [
        'source_session_id',
        'source_chat_id',
        'source_token',
        'source_public_token',
        'target_bot_username',
        'target_chat_id',
        'status',
        'total_files',
        'processed_files',
        'success_files',
        'failed_files',
        'last_source_file_id',
        'last_error',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'source_session_id' => 'integer',
        'source_chat_id' => 'integer',
        'target_chat_id' => 'integer',
        'total_files' => 'integer',
        'processed_files' => 'integer',
        'success_files' => 'integer',
        'failed_files' => 'integer',
        'last_source_file_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(TelegramFilestoreSession::class, 'source_session_id', 'id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TelegramFilestoreRestoreFile::class, 'restore_session_id', 'id');
    }
}
