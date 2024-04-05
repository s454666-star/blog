<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $table = 'articles'; // 指定資料表名稱
    protected $primaryKey = 'article_id';

    protected $fillable = [
        'title',
        'password',
        'https_link',
    ];

    public function images()
    {
        return $this->hasMany(Image::class, 'article_id', 'article_id');
    }
}
