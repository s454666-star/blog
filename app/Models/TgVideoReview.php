<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TgVideoReview extends Model
{
    protected $fillable = [
        'path_hash',
        'video_path',
        'image_path',
        'file_size_bytes',
        'file_modified_at',
        'duration_seconds',
        'screenshot_count',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'file_modified_at' => 'integer',
        'duration_seconds' => 'decimal:3',
        'screenshot_count' => 'integer',
    ];
}
