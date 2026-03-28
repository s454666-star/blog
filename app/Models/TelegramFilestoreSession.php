<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramFilestoreSession extends Model
{
    protected $table = 'telegram_filestore_sessions';

    public $timestamps = false;

    protected $fillable = [
        'chat_id',
        'username',
        'encrypt_token',
        'public_token',
        'source_token',
        'status',
        'total_files',
        'total_size',
        'share_count',
        'created_at',
        'closed_at',
        'last_shared_at',
        'close_upload_prompted_at',
        'is_sending',
        'sending_started_at',
        'sending_finished_at',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'total_files' => 'integer',
        'total_size' => 'integer',
        'share_count' => 'integer',
        'is_sending' => 'integer',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(TelegramFilestoreFile::class, 'session_id', 'id');
    }
}
