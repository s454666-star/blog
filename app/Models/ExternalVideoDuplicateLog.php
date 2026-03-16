<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ExternalVideoDuplicateLog extends Model
{
    use HasFactory;

    protected $table = 'external_video_duplicate_logs';

    protected $fillable = [
        'external_video_duplicate_match_id',
        'video_master_id',
        'matched_video_feature_id',
        'scan_root_path',
        'source_directory_path',
        'source_file_path',
        'source_path_sha1',
        'duplicate_file_path',
        'duplicate_path_sha1',
        'file_name',
        'file_size_bytes',
        'duration_seconds',
        'file_created_at',
        'file_modified_at',
        'screenshot_count',
        'feature_version',
        'capture_rule',
        'threshold_percent',
        'requested_min_match',
        'required_matches',
        'window_seconds',
        'size_percent',
        'max_candidates',
        'candidate_count',
        'similarity_percent',
        'matched_frames',
        'compared_frames',
        'duration_delta_seconds',
        'file_size_delta_bytes',
        'is_duplicate_detected',
        'operation_status',
        'operation_message',
        'source_feature_json',
        'matched_feature_json',
        'frame_comparisons_json',
    ];

    protected $casts = [
        'external_video_duplicate_match_id' => 'integer',
        'video_master_id' => 'integer',
        'matched_video_feature_id' => 'integer',
        'file_size_bytes' => 'integer',
        'duration_seconds' => 'decimal:3',
        'file_created_at' => 'datetime',
        'file_modified_at' => 'datetime',
        'screenshot_count' => 'integer',
        'threshold_percent' => 'integer',
        'requested_min_match' => 'integer',
        'required_matches' => 'integer',
        'window_seconds' => 'integer',
        'size_percent' => 'integer',
        'max_candidates' => 'integer',
        'candidate_count' => 'integer',
        'similarity_percent' => 'decimal:2',
        'matched_frames' => 'integer',
        'compared_frames' => 'integer',
        'duration_delta_seconds' => 'decimal:3',
        'file_size_delta_bytes' => 'integer',
        'is_duplicate_detected' => 'boolean',
        'source_feature_json' => 'array',
        'matched_feature_json' => 'array',
        'frame_comparisons_json' => 'array',
    ];

    public function match()
    {
        return $this->belongsTo(ExternalVideoDuplicateMatch::class, 'external_video_duplicate_match_id', 'id');
    }

    public function videoMaster()
    {
        return $this->belongsTo(VideoMaster::class, 'video_master_id', 'id');
    }

    public function matchedFeature()
    {
        return $this->belongsTo(VideoFeature::class, 'matched_video_feature_id', 'id');
    }

    public function getFileSizeHumanAttribute(): string
    {
        $bytes = (int) ($this->file_size_bytes ?? 0);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024.0 && $i < count($units) - 1) {
            $size /= 1024.0;
            $i++;
        }

        return $i === 0
            ? $bytes . ' ' . $units[$i]
            : number_format($size, 2) . ' ' . $units[$i];
    }

    public function getDurationHmsAttribute(): string
    {
        $seconds = max(0, (int) round((float) ($this->duration_seconds ?? 0)));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    public function getFileCreatedAtHumanAttribute(): string
    {
        if (!$this->file_created_at instanceof Carbon) {
            return '-';
        }

        return $this->file_created_at->format('Y-m-d H:i:s');
    }

    public function getFileModifiedAtHumanAttribute(): string
    {
        if (!$this->file_modified_at instanceof Carbon) {
            return '-';
        }

        return $this->file_modified_at->format('Y-m-d H:i:s');
    }
}
