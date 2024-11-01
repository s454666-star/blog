<?php

    namespace App\Models;

    use Illuminate\Foundation\Auth\User as Authenticatable;
    use Illuminate\Notifications\Notifiable;
    use Laravel\Sanctum\HasApiTokens; // 引入 Sanctum 套件

    class Member extends Authenticatable
    {
        use Notifiable, HasApiTokens;

        protected $fillable = [
            'username',
            'password',
            'name',
            'phone',
            'email',
            'email_verified',
            'email_verification_token',
            'address',
            'status',
        ];

        protected $hidden = [
            'password',
            'remember_token',
        ];

        public function deliveryAddresses()
        {
            return $this->hasMany(DeliveryAddress::class);
        }

        public function creditCards()
        {
            return $this->hasMany(CreditCard::class);
        }

        public function orders()
        {
            return $this->hasMany(Order::class);
        }
    }
