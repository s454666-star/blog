<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoFeatureFrame extends Model
{
    use HasFactory;

    protected $table = 'video_feature_frames';

    protected $fillable = [
        'video_feature_id',
        'video_screenshot_id',
        'capture_order',
        'capture_second',
        'screenshot_path',
        'dhash_hex',
        'dhash_prefix',
        'frame_sha1',
        'image_width',
        'image_height',
    ];

    protected $casts = [
        'video_feature_id' => 'integer',
        'video_screenshot_id' => 'integer',
        'capture_order' => 'integer',
        'capture_second' => 'decimal:3',
        'image_width' => 'integer',
        'image_height' => 'integer',
    ];

    public function videoFeature()
    {
        return $this->belongsTo(VideoFeature::class, 'video_feature_id', 'id');
    }

    public function screenshot()
    {
        return $this->belongsTo(VideoScreenshot::class, 'video_screenshot_id', 'id');
    }
}
