<?php

    namespace App\Models;

    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\HasMany;

    class TokenScanHeader extends Model
    {
        protected $table = 'token_scan_headers';

        protected $fillable = [
            'peer_id',
            'chat_title',
            'last_start_message_id',
            'max_message_id',
            'last_batch_count',
        ];

        protected $casts = [
            'peer_id' => 'integer',
            'last_start_message_id' => 'integer',
            'max_message_id' => 'integer',
            'last_batch_count' => 'integer',
        ];

        public function items(): HasMany
        {
            return $this->hasMany(TokenScanItem::class, 'header_id');
        }
    }
