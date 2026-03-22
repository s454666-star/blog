<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FolderVideoDuplicateMatch extends Model
{
    use HasFactory;

    protected $table = 'folder_video_duplicate_matches';

    protected $fillable = [
        'folder_video_duplicate_batch_id',
        'kept_feature_id',
        'duplicate_feature_id',
        'kept_file_path',
        'duplicate_file_path',
        'duplicate_path_sha1',
        'moved_to_path',
        'similarity_percent',
        'matched_frames',
        'compared_frames',
        'required_matches',
        'duration_delta_seconds',
        'file_size_delta_bytes',
        'frame_comparisons_json',
        'operation_status',
        'operation_message',
    ];

    protected $casts = [
        'folder_video_duplicate_batch_id' => 'integer',
        'kept_feature_id' => 'integer',
        'duplicate_feature_id' => 'integer',
        'similarity_percent' => 'decimal:2',
        'matched_frames' => 'integer',
        'compared_frames' => 'integer',
        'required_matches' => 'integer',
        'duration_delta_seconds' => 'decimal:3',
        'file_size_delta_bytes' => 'integer',
        'frame_comparisons_json' => 'array',
    ];

    public function batch()
    {
        return $this->belongsTo(FolderVideoDuplicateBatch::class, 'folder_video_duplicate_batch_id', 'id');
    }

    public function keptFeature()
    {
        return $this->belongsTo(FolderVideoDuplicateFeature::class, 'kept_feature_id', 'id');
    }

    public function duplicateFeature()
    {
        return $this->belongsTo(FolderVideoDuplicateFeature::class, 'duplicate_feature_id', 'id');
    }
}
