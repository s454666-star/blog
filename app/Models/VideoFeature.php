<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoFeature extends Model
{
    use HasFactory;

    protected $table = 'video_features';

    protected $fillable = [
        'video_master_id',
        'master_face_screenshot_id',
        'video_name',
        'video_path',
        'directory_path',
        'file_name',
        'path_sha1',
        'file_size_bytes',
        'duration_seconds',
        'file_created_at',
        'file_modified_at',
        'screenshot_count',
        'feature_version',
        'capture_rule',
        'extracted_at',
        'last_error',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'duration_seconds' => 'decimal:3',
        'file_created_at' => 'datetime',
        'file_modified_at' => 'datetime',
        'extracted_at' => 'datetime',
        'screenshot_count' => 'integer',
    ];

    public function videoMaster()
    {
        return $this->belongsTo(VideoMaster::class, 'video_master_id', 'id');
    }

    public function masterFace()
    {
        return $this->belongsTo(VideoFaceScreenshot::class, 'master_face_screenshot_id', 'id');
    }

    public function frames()
    {
        return $this->hasMany(VideoFeatureFrame::class, 'video_feature_id', 'id')
            ->orderBy('capture_order');
    }

    public function leftMatches()
    {
        return $this->hasMany(VideoFeatureMatch::class, 'left_video_feature_id', 'id');
    }

    public function rightMatches()
    {
        return $this->hasMany(VideoFeatureMatch::class, 'right_video_feature_id', 'id');
    }

    public function externalDuplicateMatches()
    {
        return $this->hasMany(ExternalVideoDuplicateMatch::class, 'matched_video_feature_id', 'id');
    }
}
