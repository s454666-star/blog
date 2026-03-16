<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FaceIdentityGroupChange extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'face_identity_group_changes';

    protected $fillable = [
        'video_id',
        'from_person_id',
        'to_person_id',
        'action',
        'note',
        'metadata_json',
        'created_at',
    ];

    protected $casts = [
        'video_id' => 'integer',
        'from_person_id' => 'integer',
        'to_person_id' => 'integer',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function video()
    {
        return $this->belongsTo(FaceIdentityVideo::class, 'video_id', 'id');
    }

    public function fromPerson()
    {
        return $this->belongsTo(FaceIdentityPerson::class, 'from_person_id', 'id');
    }

    public function toPerson()
    {
        return $this->belongsTo(FaceIdentityPerson::class, 'to_person_id', 'id');
    }
}
