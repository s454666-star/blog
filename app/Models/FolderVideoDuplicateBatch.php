<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FolderVideoDuplicateBatch extends Model
{
    use HasFactory;

    protected $table = 'folder_video_duplicate_batches';

    protected $fillable = [
        'scan_root_path',
        'duplicate_directory_path',
        'is_recursive',
        'threshold_percent',
        'min_match_required',
        'window_seconds',
        'max_candidates',
        'limit_count',
        'is_dry_run',
        'cleanup_requested',
        'status',
        'total_files',
        'processed_files',
        'kept_files',
        'moved_files',
        'failed_files',
        'started_at',
        'finished_at',
        'last_error',
    ];

    protected $casts = [
        'is_recursive' => 'boolean',
        'threshold_percent' => 'integer',
        'min_match_required' => 'integer',
        'window_seconds' => 'integer',
        'max_candidates' => 'integer',
        'limit_count' => 'integer',
        'is_dry_run' => 'boolean',
        'cleanup_requested' => 'boolean',
        'total_files' => 'integer',
        'processed_files' => 'integer',
        'kept_files' => 'integer',
        'moved_files' => 'integer',
        'failed_files' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function features()
    {
        return $this->hasMany(FolderVideoDuplicateFeature::class, 'folder_video_duplicate_batch_id', 'id');
    }

    public function matches()
    {
        return $this->hasMany(FolderVideoDuplicateMatch::class, 'folder_video_duplicate_batch_id', 'id');
    }
}
