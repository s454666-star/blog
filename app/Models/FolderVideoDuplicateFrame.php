<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FolderVideoDuplicateFrame extends Model
{
    use HasFactory;

    protected $table = 'folder_video_duplicate_frames';

    protected $fillable = [
        'folder_video_duplicate_feature_id',
        'capture_order',
        'capture_second',
        'dhash_hex',
        'dhash_prefix',
        'frame_sha1',
        'image_width',
        'image_height',
    ];

    protected $casts = [
        'folder_video_duplicate_feature_id' => 'integer',
        'capture_order' => 'integer',
        'capture_second' => 'decimal:3',
        'image_width' => 'integer',
        'image_height' => 'integer',
    ];

    public function feature()
    {
        return $this->belongsTo(FolderVideoDuplicateFeature::class, 'folder_video_duplicate_feature_id', 'id');
    }
}
