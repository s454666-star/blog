<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class PortfolioDividendIncome extends Model
{
    protected $guarded = [];

    protected $casts = [
        'ex_dividend_date' => 'date',
        'cash_dividend_per_share' => 'float',
        'eligible_quantity' => 'float',
        'dividend_income' => 'float',
        'source_payload' => 'array',
        'calculated_at' => 'datetime',
    ];

    public static function yearTotal(string $broker, int $year): float
    {
        if (!Schema::hasTable((new static)->getTable())) {
            return 0.0;
        }

        return (float) Cache::remember(
            "portfolio-dividend-income:{$broker}:{$year}",
            now()->addMinute(),
            fn () => static::query()
                ->where('broker', $broker)
                ->whereYear('ex_dividend_date', $year)
                ->sum('dividend_income'),
        );
    }

    public static function forgetYearTotal(string $broker, int $year): void
    {
        Cache::forget("portfolio-dividend-income:{$broker}:{$year}");
    }
}
