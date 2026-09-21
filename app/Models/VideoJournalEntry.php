<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VideoJournalEntry extends Model
{
    protected $connection = 'video_journal';
    protected $fillable = ['title', 'source', 'body'];
}
