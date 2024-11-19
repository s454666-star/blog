<?php
    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Support\Str;

    class ReturnOrder extends Model
    {
        use HasFactory;

        protected $table = 'return_orders';

        protected $fillable = [
            'member_id',
            'order_id',
            'order_item_id',
            'reason',
            'return_date',
            'status',
            'return_quantity',
            'return_order_number',
        ];

        /**
         * 退貨單屬於一個會員
         */
        public function member()
        {
            return $this->belongsTo(Member::class, 'member_id');
        }

        /**
         * 退貨單屬於一個訂單
         */
        public function order()
        {
            return $this->belongsTo(Order::class, 'order_id');
        }

        /**
         * 退貨單屬於一個訂單項目
         */
        public function orderItem()
        {
            return $this->belongsTo(OrderItem::class, 'order_item_id');
        }

        /**
         * 取得退貨單的狀態選項
         */
        public static function getStatusOptions()
        {
            return [
                '已接收',
                '物流運送中',
                '已完成',
                '已取消',
            ];
        }

        /**
         * 自動生成退貨單號
         */
        protected static function boot()
        {
            parent::boot();

            static::creating(function ($model) {
                $date = now()->format('Ymd');
                $lastReturnOrder = self::where('return_order_number', 'like', 'R' . $date . '%')->orderBy('id', 'desc')->first();
                $sequence = $lastReturnOrder ? intval(substr($lastReturnOrder->return_order_number, -5)) + 1 : 1;
                $model->return_order_number = 'R' . $date . str_pad($sequence, 5, '0', STR_PAD_LEFT);
            });
        }
    }
