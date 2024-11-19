<?php
    namespace App\Models;

    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;
    use Laravel\Sanctum\HasApiTokens;

    class Member extends Authenticatable
    {
        use HasApiTokens, Notifiable;

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

        /**
         * 會員擁有多個訂單
         */
        public function orders()
        {
            return $this->hasMany(Order::class, 'member_id');
        }

        /**
         * 會員擁有多個配送地址
         */
        public function deliveryAddresses()
        {
            return $this->hasMany(DeliveryAddress::class, 'member_id');
        }

        /**
         * 會員的默認配送地址
         */
        public function defaultDeliveryAddress()
        {
            return $this->hasOne(DeliveryAddress::class, 'member_id')->where('is_default', 1);
        }

        /**
         * 會員擁有多個退貨單
         */
        public function returnOrders()
        {
            return $this->hasMany(ReturnOrder::class, 'member_id');
        }

        /**
         * 取得會員地址，優先使用默認配送地址
         */
        public function getAddressAttribute()
        {
            return $this->defaultDeliveryAddress ? $this->defaultDeliveryAddress->address : $this->attributes['address'];
        }
    }
