<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestVi extends Model
{
    use HasFactory;

    // 指定對應的資料表名稱
    protected $table = 'test_vi';

    // 指定可以批量賦值的欄位
    protected $fillable = ['name', 'date'];

    // 如果你不想使用 Eloquent 的時間戳（created_at 和 updated_at）
    // 可以將 $timestamps 設為 false
    public $timestamps = false;

    // 其他你需要的模型邏輯...
}