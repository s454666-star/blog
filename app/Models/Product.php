<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;

    class Product extends Model
    {
        use HasFactory;

        protected $table = 'products';

        protected $fillable = [
            'category_id',
            'product_name',
            'price',
            'image_base64',
            'description',
            'stock_quantity',
            'sku',
            'weight',
            'dimensions',
            'color',
            'material',
            'brand',
            'status',
            'rating',
            'release_date',
        ];

        // 定義與類別的關係
        public function category()
        {
            return $this->belongsTo(ProductCategory::class, 'category_id');
        }
    }
