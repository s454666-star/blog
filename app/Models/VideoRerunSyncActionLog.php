<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoRerunSyncActionLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'action_type',
        'content_sha1',
        'target_source',
        'target_key',
        'status',
        'message',
        'payload_json',
    ];

    protected $casts = [
        'payload_json' => 'array',
    ];
}
