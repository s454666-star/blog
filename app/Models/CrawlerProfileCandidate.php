<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrawlerProfileCandidate extends Model
{
    protected $fillable = [
        'source',
        'external_user_id',
        'nickname',
        'age',
        'area',
        'profile_url',
        'matched_filter_json',
        'raw_payload',
        'captured_at',
    ];

    protected $casts = [
        'age' => 'integer',
        'matched_filter_json' => 'array',
        'raw_payload' => 'array',
        'captured_at' => 'datetime',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(CrawlerProfileImage::class);
    }
}
