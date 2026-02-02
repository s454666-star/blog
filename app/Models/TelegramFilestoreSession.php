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
        'status',
        'total_files',
        'total_size',
        'created_at',
        'closed_at',
    ];

    protected $casts = [
        'chat_id' => 'integer',
        'total_files' => 'integer',
        'total_size' => 'integer',
    ];

    public function files(): HasMany
    {
        return $this->hasMany(TelegramFilestoreFile::class, 'session_id', 'id');
    }
}
