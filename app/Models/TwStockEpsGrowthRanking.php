<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwStockEpsGrowthRanking extends Model
{
    protected $table = 'tw_stock_eps_growth_rankings';

    protected $guarded = [];

    protected $casts = [
        'rank' => 'integer',
        'previous_rank' => 'integer',
        'rank_change' => 'integer',
        'eps_2025' => 'float',
        'eps_2026' => 'float',
        'eps_2027' => 'float',
        'eps_2028' => 'float',
        'growth_2025_2026' => 'float',
        'growth_2026_2027' => 'float',
        'growth_2027_2028' => 'float',
        'growth_sum' => 'float',
        'revenue_2026_thousands' => 'integer',
        'revenue_2027_thousands' => 'integer',
        'revenue_2028_thousands' => 'integer',
        'price_date' => 'date',
        'close_price' => 'float',
        'analyst_count' => 'integer',
        'forecast_date' => 'date',
        'news_id' => 'integer',
        'low_base' => 'boolean',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(TwStockEpsGrowthRun::class, 'run_id');
    }
}
