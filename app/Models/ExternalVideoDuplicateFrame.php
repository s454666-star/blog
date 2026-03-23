<?php

namespace App\Models;

use App\Support\RelativeMediaPath;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExternalVideoDuplicateFrame extends Model
{
    use HasFactory;

    protected $table = 'external_video_duplicate_frames';

    protected $fillable = [
        'external_video_duplicate_match_id',
        'matched_video_feature_frame_id',
        'capture_order',
        'capture_second',
        'screenshot_path',
        'dhash_hex',
        'dhash_prefix',
        'frame_sha1',
        'image_width',
        'image_height',
        'similarity_percent',
        'is_threshold_match',
    ];

    protected $casts = [
        'external_video_duplicate_match_id' => 'integer',
        'matched_video_feature_frame_id' => 'integer',
        'capture_order' => 'integer',
        'capture_second' => 'decimal:3',
        'image_width' => 'integer',
        'image_height' => 'integer',
        'similarity_percent' => 'integer',
        'is_threshold_match' => 'boolean',
    ];

    public function match()
    {
        return $this->belongsTo(ExternalVideoDuplicateMatch::class, 'external_video_duplicate_match_id', 'id');
    }

    public function matchedVideoFeatureFrame()
    {
        return $this->belongsTo(VideoFeatureFrame::class, 'matched_video_feature_frame_id', 'id');
    }

    public function getScreenshotPathAttribute($value): string
    {
        return RelativeMediaPath::normalize($value) ?? '';
    }

    public function setScreenshotPathAttribute($value): void
    {
        $this->attributes['screenshot_path'] = RelativeMediaPath::normalize($value) ?? '';
    }
}
