<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class ProductCategory extends Model
    {
        // 定義表名
        protected $table = 'product_categories';

        // 可批量賦值的屬性
        protected $fillable = [
            'category_name',
            'description',
            'status',
        ];

        // 隱藏不必要的欄位
        protected $hidden = [
            'created_at',
            'updated_at',
        ];
    }
