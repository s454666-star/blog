<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Actor extends Model
{
    // 指定對應的資料表名稱
    protected $table = 'actors';

    // 可以批量賦值的欄位
    protected $fillable = ['actor_name', 'secondary_actor_name'];

    // 定義與套圖的關聯 (一對多)
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class, 'actor_id');
    }
}
