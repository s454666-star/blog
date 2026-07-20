<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmCustomer extends Model
{
    protected $table = 'crm_customers';
    protected $guarded = [];

    public function contacts() { return $this->hasMany(CrmContact::class, 'customer_id'); }
    public function addresses() { return $this->hasMany(CrmAddress::class, 'customer_id'); }
    public function orders() { return $this->hasMany(CrmOrder::class, 'customer_id'); }
}
