<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Album extends Model
{
    // 指定對應的資料表名稱
    protected $table = 'albums';

    // 可以批量賦值的欄位
    protected $fillable = ['name', 'content', 'cover_path', 'actor_id'];

    // 定義與演員的關聯 (多對一)
    public function actor(): BelongsTo
    {
        return $this->belongsTo(Actor::class, 'actor_id');
    }

    // 定義與照片的關聯 (一對多)
    public function photos(): HasMany
    {
        return $this->hasMany(AlbumPhoto::class, 'album_id');
    }
}
