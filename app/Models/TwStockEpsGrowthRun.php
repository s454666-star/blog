<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TwStockEpsGrowthRun extends Model
{
    protected $table = 'tw_stock_eps_growth_runs';

    protected $guarded = [];

    protected $casts = [
        'snapshot_date' => 'date',
        'price_date' => 'date',
        'base_year' => 'integer',
        'forecast_year_1' => 'integer',
        'forecast_year_2' => 'integer',
        'forecast_year_3' => 'integer',
        'article_count' => 'integer',
        'forecast_count' => 'integer',
        'eligible_count' => 'integer',
        'top_count' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function rankings(): HasMany
    {
        return $this->hasMany(TwStockEpsGrowthRanking::class, 'run_id');
    }
}
