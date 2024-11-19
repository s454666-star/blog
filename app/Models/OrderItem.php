<?php
    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Factories\HasFactory;

    class OrderItem extends Model
    {
        use HasFactory;

        protected $table = 'order_items';

        protected $fillable = [
            'order_id',
            'product_id',
            'quantity',
            'price',
            'return_quantity', // 新增的退貨數量
        ];

        /**
         * 訂單項目屬於一個訂單
         */
        public function order()
        {
            return $this->belongsTo(Order::class);
        }

        /**
         * 訂單項目屬於一個產品
         */
        public function product()
        {
            return $this->belongsTo(Product::class);
        }

        /**
         * 訂單項目擁有多個退貨單
         */
        public function returnOrders()
        {
            return $this->hasMany(ReturnOrder::class, 'order_item_id');
        }
    }
