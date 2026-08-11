<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmContact extends Model
{
    protected $table = 'crm_contacts';
    protected $guarded = [];

    public function customer() { return $this->belongsTo(CrmCustomer::class, 'customer_id'); }

    public function orders() { return $this->hasMany(CrmOrder::class, 'contact_id'); }
}
