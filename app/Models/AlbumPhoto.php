<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlbumPhoto extends Model
{
    // 指定對應的資料表名稱
    protected $table = 'album_photos';

    // 可以批量賦值的欄位
    protected $fillable = ['album_id', 'photo_path', 'index_sort'];

    // 定義與套圖的關聯 (多對一)
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class, 'album_id');
    }
}
