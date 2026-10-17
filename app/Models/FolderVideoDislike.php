<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FolderVideoDislike extends Model
{
    protected $table = 'folder_video_dislikes';

    public $timestamps = false;

    protected $fillable = [
        'video_key',
        'video_id',
        'source',
        'filename',
        'client',
        'disliked_at',
    ];

    protected $casts = [
        'disliked_at' => 'datetime',
    ];

    public static function keyFor(string $videoId): string
    {
        return sha1($videoId);
    }
}
