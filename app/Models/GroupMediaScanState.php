<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GroupMediaScanState extends Model
{
    protected $table = 'group_media_scan_states';

    protected $fillable = [
        'base_uri',
        'peer_id',
        'chat_title',
        'max_message_id',
        'last_group_message_id',
        'last_downloaded_message_id',
        'last_batch_count',
        'last_message_datetime',
        'last_saved_path',
        'last_saved_name',
    ];

    protected $casts = [
        'peer_id' => 'integer',
        'max_message_id' => 'integer',
        'last_group_message_id' => 'integer',
        'last_downloaded_message_id' => 'integer',
        'last_batch_count' => 'integer',
        'last_message_datetime' => 'datetime',
    ];
}
