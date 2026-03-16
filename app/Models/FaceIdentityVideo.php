<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class FaceIdentityVideo extends Model
{
    use HasFactory;

    protected $table = 'face_identity_videos';

    protected $fillable = [
        'person_id',
        'feature_model',
        'source_root_label',
        'source_root_path',
        'relative_directory',
        'relative_path',
        'absolute_path',
        'file_name',
        'path_sha1',
        'file_size_bytes',
        'file_modified_at',
        'duration_seconds',
        'frame_interval_seconds',
        'accepted_sample_count',
        'preview_sample_path',
        'match_confidence',
        'assignment_source',
        'group_locked',
        'scan_status',
        'last_scanned_at',
        'last_error',
        'metadata_json',
    ];

    protected $casts = [
        'person_id' => 'integer',
        'file_size_bytes' => 'integer',
        'file_modified_at' => 'datetime',
        'duration_seconds' => 'decimal:3',
        'frame_interval_seconds' => 'integer',
        'accepted_sample_count' => 'integer',
        'match_confidence' => 'decimal:4',
        'group_locked' => 'boolean',
        'last_scanned_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function person()
    {
        return $this->belongsTo(FaceIdentityPerson::class, 'person_id', 'id');
    }

    public function samples()
    {
        return $this->hasMany(FaceIdentitySample::class, 'video_id', 'id')
            ->orderBy('capture_order');
    }

    public function groupChanges()
    {
        return $this->hasMany(FaceIdentityGroupChange::class, 'video_id', 'id')
            ->latest('id');
    }

    public function getPreviewImageUrlAttribute(): ?string
    {
        $path = trim((string) $this->preview_sample_path);
        if ($path === '') {
            return null;
        }

        return route('face-identities.image', ['path' => $path], false);
    }

    public function getStreamUrlAttribute(): string
    {
        return route('face-identities.video', $this, false);
    }

    public function getDisplayDurationAttribute(): string
    {
        $seconds = max(0, (int) round((float) ($this->duration_seconds ?? 0)));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    public function getFileModifiedHumanAttribute(): string
    {
        if (!$this->file_modified_at instanceof Carbon) {
            return '-';
        }

        return $this->file_modified_at->format('Y-m-d H:i:s');
    }
}
