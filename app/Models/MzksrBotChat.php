<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MzksrBotChat extends Model
{
    use HasFactory;

    protected $table = 'mzksr_bot_chats';

    protected $guarded = [];

    protected $casts = [
        'chat_id' => 'integer',
        'last_message_id' => 'integer',
        'interaction_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
