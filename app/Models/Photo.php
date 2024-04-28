<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Photo extends Model
{
    protected $table = 'photo';
    protected $fillable = ['photo_name', 'path', 'tags', 'rating'];

    public $timestamps = false;
}
