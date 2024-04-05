<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $table = 'articles'; // 指定資料表名稱

    protected $fillable = [
        'title',
        'password',
        'https_link',
    ];


}
