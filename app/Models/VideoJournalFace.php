<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoJournalFace extends Model
{
    protected $connection = 'video_journal';
    protected $fillable = ['entry_id', 'position', 'image', 'image_sha256'];
    protected $casts = ['embedding' => 'array', 'face_box' => 'array', 'landmarks' => 'array', 'processed_at' => 'datetime'];
}
