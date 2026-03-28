<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dialogue extends Model
{
    use HasFactory;

    protected $table = 'dialogues';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'chat_id' => 'integer',
        'message_id' => 'integer',
        'is_read' => 'boolean',
        'is_sync' => 'boolean',
        'created_at' => 'datetime',
    ];
}
