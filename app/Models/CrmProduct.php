<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CrmProduct extends Model
{
    protected $table = 'crm_products';
    protected $guarded = [];
    protected $casts = ['price' => 'decimal:2', 'cost' => 'decimal:2'];
}
