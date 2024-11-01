<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Factories\HasFactory;

    class CreditCard extends Model
    {
        use HasFactory;

        protected $table = 'credit_cards';

        protected $fillable = [
            'member_id', 'cardholder_name', 'card_number', 'expiry_date',
            'card_type', 'billing_address', 'postal_code', 'country', 'is_default'
        ];

        protected $hidden = ['card_number'];

        public function member()
        {
            return $this->belongsTo(Member::class);
        }

        public function orders()
        {
            return $this->hasMany(Order::class);
        }
    }
