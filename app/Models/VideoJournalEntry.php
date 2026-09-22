<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoJournalEntry extends Model
{
    protected $connection = 'video_journal';
    protected $fillable = ['title', 'source', 'body', 'tags', 'covers'];
    protected $casts = ['tags' => 'array', 'covers' => 'array'];

    public function faces()
    {
        return $this->hasMany(VideoJournalFace::class, 'entry_id')->orderBy('position');
    }

    public function getPortraitsAttribute(): array
    {
        return $this->faces()->pluck('image')->all();
    }
}
