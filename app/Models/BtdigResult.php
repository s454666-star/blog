<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BtdigResult extends Model
{
    use HasFactory;

    protected $table = 'btdig_results';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
        'copied_at' => 'datetime',
    ];

    public function images()
    {
        return $this->hasMany(BtdigResultImage::class, 'btdig_result_id', 'id');
    }
}
