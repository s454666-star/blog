<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmOrder extends Model
{
    protected $table = 'crm_orders';
    protected $guarded = [];
    protected $casts = ['order_date' => 'date', 'total' => 'decimal:2'];

    public function customer() { return $this->belongsTo(CrmCustomer::class, 'customer_id'); }
    public function contact() { return $this->belongsTo(CrmContact::class, 'contact_id'); }
    public function address() { return $this->belongsTo(CrmAddress::class, 'address_id'); }
    public function items() { return $this->hasMany(CrmOrderItem::class, 'order_id'); }
}
