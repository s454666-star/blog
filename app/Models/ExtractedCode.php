<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExtractedCode extends Model
{
    protected $table = 'extracted_codes';

    protected $fillable = [
        'code',
    ];

    public $timestamps = true;
}
