<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FailedJob extends Model
{
    use HasFactory;

    protected $table = 'failed_jobs';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'failed_at' => 'datetime',
    ];
}
