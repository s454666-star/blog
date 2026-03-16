<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoFeatureMatch extends Model
{
    use HasFactory;

    protected $table = 'video_feature_matches';

    protected $fillable = [
        'left_video_feature_id',
        'right_video_feature_id',
        'similarity_percent',
        'matched_frames',
        'compared_frames',
        'duration_delta_seconds',
        'file_size_delta_bytes',
        'compared_at',
        'notes_json',
    ];

    protected $casts = [
        'left_video_feature_id' => 'integer',
        'right_video_feature_id' => 'integer',
        'similarity_percent' => 'decimal:2',
        'matched_frames' => 'integer',
        'compared_frames' => 'integer',
        'duration_delta_seconds' => 'decimal:3',
        'file_size_delta_bytes' => 'integer',
        'compared_at' => 'datetime',
        'notes_json' => 'array',
    ];

    public function leftFeature()
    {
        return $this->belongsTo(VideoFeature::class, 'left_video_feature_id', 'id');
    }

    public function rightFeature()
    {
        return $this->belongsTo(VideoFeature::class, 'right_video_feature_id', 'id');
    }
}
