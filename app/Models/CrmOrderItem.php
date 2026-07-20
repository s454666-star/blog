<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmOrderItem extends Model
{
    protected $table = 'crm_order_items';
    protected $guarded = [];

    public function order() { return $this->belongsTo(CrmOrder::class, 'order_id'); }
    public function product() { return $this->belongsTo(CrmProduct::class, 'product_id'); }
}
