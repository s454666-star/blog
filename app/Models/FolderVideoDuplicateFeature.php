<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FolderVideoDuplicateFeature extends Model
{
    use HasFactory;

    protected $table = 'folder_video_duplicate_features';

    protected $fillable = [
        'folder_video_duplicate_batch_id',
        'absolute_path',
        'path_sha1',
        'directory_path',
        'file_name',
        'file_size_bytes',
        'duration_seconds',
        'file_created_at',
        'file_modified_at',
        'screenshot_count',
        'feature_version',
        'capture_rule',
        'is_canonical',
        'moved_to_duplicate_path',
        'extraction_status',
        'last_error',
    ];

    protected $casts = [
        'folder_video_duplicate_batch_id' => 'integer',
        'file_size_bytes' => 'integer',
        'duration_seconds' => 'decimal:3',
        'file_created_at' => 'datetime',
        'file_modified_at' => 'datetime',
        'screenshot_count' => 'integer',
        'is_canonical' => 'boolean',
    ];

    public function batch()
    {
        return $this->belongsTo(FolderVideoDuplicateBatch::class, 'folder_video_duplicate_batch_id', 'id');
    }

    public function frames()
    {
        return $this->hasMany(FolderVideoDuplicateFrame::class, 'folder_video_duplicate_feature_id', 'id')
            ->orderBy('capture_order');
    }

    public function keptMatches()
    {
        return $this->hasMany(FolderVideoDuplicateMatch::class, 'kept_feature_id', 'id');
    }

    public function duplicateMatches()
    {
        return $this->hasMany(FolderVideoDuplicateMatch::class, 'duplicate_feature_id', 'id');
    }
}
