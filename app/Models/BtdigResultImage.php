<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BtdigResultImage extends Model
{
    use HasFactory;

    protected $table = 'btdig_result_images';

    protected $guarded = [];

    protected $casts = [
        'btdig_result_id' => 'integer',
        'keyword_number' => 'integer',
        'image_size_bytes' => 'integer',
        'sort_order' => 'integer',
        'fetched_at' => 'datetime',
    ];

    public function btdigResult()
    {
        return $this->belongsTo(BtdigResult::class, 'btdig_result_id', 'id');
    }
}
