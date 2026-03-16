<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaceIdentityPerson extends Model
{
    use HasFactory;

    protected $table = 'face_identity_people';

    protected $fillable = [
        'feature_model',
        'cover_sample_path',
        'video_count',
        'sample_count',
        'first_seen_at',
        'last_seen_at',
        'centroid_embedding_json',
    ];

    protected $casts = [
        'video_count' => 'integer',
        'sample_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function videos()
    {
        return $this->hasMany(FaceIdentityVideo::class, 'person_id', 'id');
    }

    public function samples()
    {
        return $this->hasMany(FaceIdentitySample::class, 'person_id', 'id');
    }

    public function getDisplayCodeAttribute(): string
    {
        return sprintf('#%05d', (int) $this->id);
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        $path = trim((string) $this->cover_sample_path);
        if ($path === '') {
            return null;
        }

        return route('face-identities.image', ['path' => $path], false);
    }
}
