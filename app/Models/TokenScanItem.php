<?php


    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;

    class TokenScanItem extends Model
    {
        protected $table = 'token_scan_items';

        protected $fillable
            = [
                'header_id',
                'token',
            ];

        public function header(): BelongsTo
        {
            return $this->belongsTo(TokenScanHeader::class, 'header_id');
        }
    }
