<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    use HasFactory;

    protected $table = 'api_log';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'call_back_time' => 'datetime',
        'call_time' => 'datetime',
        'end_time' => 'datetime',
        'call_back_status' => 'boolean',
        'api_status' => 'boolean',
    ];
}
