<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    protected $table = 'images'; // 指定資料表名稱

    protected $fillable = [
        'image_name',
        'image_path',
        'article_id',
    ];


}
