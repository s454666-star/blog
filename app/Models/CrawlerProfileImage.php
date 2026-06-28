<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrawlerProfileImage extends Model
{
    protected $fillable = [
        'crawler_profile_candidate_id',
        'image_url',
        'image_url_hash',
        'sort_order',
        'captured_at',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(CrawlerProfileCandidate::class, 'crawler_profile_candidate_id');
    }

    protected static function booted(): void
    {
        static::saving(function (CrawlerProfileImage $image): void {
            if ($image->image_url !== null) {
                $image->image_url_hash = hash('sha256', (string) $image->image_url);
            }
        });
    }
}
