<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoRerunSyncEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_type',
        'source_key',
        'source_item_id',
        'resource_key',
        'display_name',
        'relative_path',
        'absolute_path',
        'file_extension',
        'file_size_bytes',
        'file_modified_at',
        'content_sha1',
        'fingerprint_status',
        'last_error',
        'last_seen_run_id',
        'discovered_at',
        'fingerprinted_at',
        'is_present',
        'metadata_json',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'file_modified_at' => 'datetime',
        'discovered_at' => 'datetime',
        'fingerprinted_at' => 'datetime',
        'is_present' => 'boolean',
        'metadata_json' => 'array',
    ];

    public function lastSeenRun()
    {
        return $this->belongsTo(VideoRerunSyncRun::class, 'last_seen_run_id', 'id');
    }
}
