<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MigrationRecord extends Model
{
    use HasFactory;

    protected $table = 'migrations';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'batch' => 'integer',
    ];
}
