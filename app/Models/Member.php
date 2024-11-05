<?php

    namespace App\Models;

    use Illuminate\Foundation\Auth\User as Authenticatable; // Extend Authenticatable
    use Illuminate\Notifications\Notifiable;
    use Laravel\Sanctum\HasApiTokens; // Import the HasApiTokens trait

    class Member extends Authenticatable
    {
        use HasApiTokens, Notifiable; // Use the trait

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

        // Define relationships
        public function orders()
        {
            return $this->hasMany(Order::class, 'member_id');
        }

        public function deliveryAddresses()
        {
            return $this->hasMany(DeliveryAddress::class, 'member_id');
        }

        public function defaultDeliveryAddress()
        {
            return $this->hasOne(DeliveryAddress::class, 'member_id')->where('is_default', 1);
        }
    }
