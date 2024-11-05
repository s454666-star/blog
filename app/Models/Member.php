<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class Member extends Model
    {
        protected $fillable = [
            'username',
            'password',
            'name',
            'phone',
            'email',
            'email_verified',
            'address',
            'email_verification_token',
            'status',
        ];

        // 會員有多個訂單
        public function orders()
        {
            return $this->hasMany(Order::class, 'member_id');
        }

        // 會員有多個配送地址
        public function deliveryAddresses()
        {
            return $this->hasMany(DeliveryAddress::class, 'member_id');
        }
    }
