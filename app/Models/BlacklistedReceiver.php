<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlacklistedReceiver extends Model
{
    use HasFactory;

    protected $table = 'blacklisted_receivers';

    protected $guarded = [];

    protected $casts = [
        'type' => 'integer',
        'enabled' => 'boolean',
    ];
}
