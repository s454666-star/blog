<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FolderVideoPlay extends Model
{
    protected $table = 'folder_video_plays';

    public $timestamps = false;

    protected $fillable = [
        'video_key',
        'video_id',
        'source',
        'filename',
        'client',
        'played_at',
    ];

    protected $casts = [
        'played_at' => 'datetime',
    ];

    public static function keyFor(string $videoId): string
    {
        return sha1($videoId);
    }
}
