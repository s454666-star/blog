<?php

namespace App\Console\Commands;

use App\Services\TwStockCompanyProfileService;
use Illuminate\Console\Command;

class RefreshTwStockCompanyProfilesCommand extends Command
{
    protected $signature = 'tw-stock:refresh-company-profiles';

    protected $description = '刷新台股全市場公司產業與估值族群資料。';

    public function handle(TwStockCompanyProfileService $profileService): int
    {
        $count = $profileService->refresh();

        $this->info(sprintf('完成：company_profiles=%d', $count));

        return self::SUCCESS;
    }
}
