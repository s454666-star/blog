<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;

    class DeliveryAddress extends Model
    {
        protected $fillable = [
            'member_id',
            'recipient',
            'phone',
            'address',
            'postal_code',
            'country',
            'city',
            'is_default',
        ];

        // 配送地址屬於一個會員
        public function member()
        {
            return $this->belongsTo(Member::class, 'member_id');
        }
    }
