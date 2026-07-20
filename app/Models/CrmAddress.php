<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmAddress extends Model
{
    protected $table = 'crm_addresses';
    protected $guarded = [];
    protected $casts = ['is_default' => 'boolean'];

    public function customer() { return $this->belongsTo(CrmCustomer::class, 'customer_id'); }

    public function getFullAddressAttribute(): string
    {
        return trim(implode(' ', array_filter([
            $this->postal_code, $this->county, $this->district,
            $this->address_line1, $this->address_line2,
        ])));
    }
}
