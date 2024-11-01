<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Factories\HasFactory;

    class DeliveryAddress extends Model
    {
        use HasFactory;

        protected $table = 'delivery_addresses';

        protected $fillable = [
            'member_id', 'recipient', 'phone', 'address', 'postal_code',
            'country', 'city', 'is_default'
        ];

        public function member()
        {
            return $this->belongsTo(Member::class);
        }

        public function orders()
        {
            return $this->hasMany(Order::class);
        }
    }
