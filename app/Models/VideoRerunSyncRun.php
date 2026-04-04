<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VideoRerunSyncRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'started_at',
        'finished_at',
        'db_seen_count',
        'rerun_seen_count',
        'eagle_seen_count',
        'hashed_count',
        'skipped_count',
        'missing_file_count',
        'diff_group_count',
        'issue_count',
        'summary_json',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'summary_json' => 'array',
    ];

    public function entries()
    {
        return $this->hasMany(VideoRerunSyncEntry::class, 'last_seen_run_id', 'id');
    }
}
