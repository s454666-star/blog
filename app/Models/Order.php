<?php
    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Carbon\Carbon;

    class Order extends Model
    {
        protected $fillable = [
            'member_id',
            'order_number',
            'order_date',
            'status',
            'total_amount',
            'payment_method',
            'shipping_fee',
            'delivery_address_id',
            'credit_card_id',
        ];

        protected $appends = ['delivery_address'];

        /**
         * 訂單屬於一個會員
         */
        public function member()
        {
            return $this->belongsTo(Member::class, 'member_id');
        }

        /**
         * 訂單屬於一個配送地址
         */
        public function deliveryAddressRelation()
        {
            return $this->belongsTo(DeliveryAddress::class, 'delivery_address_id');
        }

        /**
         * 訂單屬於一個信用卡（如果適用）
         */
        public function creditCard()
        {
            return $this->belongsTo(CreditCard::class, 'credit_card_id');
        }

        /**
         * 訂單有多個訂單品項
         */
        public function orderItems()
        {
            return $this->hasMany(OrderItem::class);
        }

        /**
         * 訂單擁有多個退貨單
         */
        public function returnOrders()
        {
            return $this->hasMany(ReturnOrder::class, 'order_id');
        }

        /**
         * 確保 delivery_address 屬性總是存在
         */
        public function getDeliveryAddressAttribute()
        {
            return $this->deliveryAddressRelation ?? $this->member->defaultDeliveryAddress ?? $this->member->deliveryAddresses()->first();
        }

        protected $dates = [
            'order_date',
        ];
    }
