<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Factories\HasFactory;

    class Order extends Model
    {
        use HasFactory;

        protected $table = 'orders';

        protected $fillable = [
            'member_id', 'order_number', 'status', 'total_amount',
            'payment_method', 'shipping_fee', 'delivery_address_id', 'credit_card_id'
        ];

        public function member()
        {
            return $this->belongsTo(Member::class);
        }

        public function deliveryAddress()
        {
            return $this->belongsTo(DeliveryAddress::class);
        }

        public function creditCard()
        {
            return $this->belongsTo(CreditCard::class);
        }

        public function orderItems()
        {
            return $this->hasMany(OrderItem::class);
        }
    }
