<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaceIdentitySample extends Model
{
    use HasFactory;

    protected $table = 'face_identity_samples';

    protected $fillable = [
        'video_id',
        'person_id',
        'feature_model',
        'capture_order',
        'capture_second',
        'image_path',
        'embedding_json',
        'embedding_sha1',
        'detector_score',
        'quality_score',
        'blur_score',
        'frontal_score',
        'bbox_json',
        'landmarks_json',
    ];

    protected $casts = [
        'video_id' => 'integer',
        'person_id' => 'integer',
        'capture_order' => 'integer',
        'capture_second' => 'decimal:3',
        'detector_score' => 'decimal:4',
        'quality_score' => 'decimal:3',
        'blur_score' => 'decimal:3',
        'frontal_score' => 'decimal:3',
        'bbox_json' => 'array',
        'landmarks_json' => 'array',
    ];

    public function video()
    {
        return $this->belongsTo(FaceIdentityVideo::class, 'video_id', 'id');
    }

    public function person()
    {
        return $this->belongsTo(FaceIdentityPerson::class, 'person_id', 'id');
    }

    public function getImageUrlAttribute(): ?string
    {
        $path = trim((string) $this->image_path);
        if ($path === '') {
            return null;
        }

        return route('face-identities.image', ['path' => $path], false);
    }
}
