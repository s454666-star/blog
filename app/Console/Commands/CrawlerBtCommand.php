<?php

namespace App\Console\Commands;

use App\Http\Controllers\GetBtDataController;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class CrawlerBtCommand extends Command
{
    private const RUN_LOCK_KEY = 'bt-crawler:run';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get-bt';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch BT crawler results';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(GetBtDataController $getBtDataController): int
    {
        if (!config('bt.crawler_enabled', true)) {
            Log::info('Command get-bt skipped because BT crawler is disabled by configuration');

            return self::SUCCESS;
        }

        $lock = $this->acquireRunLock();

        if ($lock === false) {
            Log::info('Command get-bt skipped because another crawler run already holds the Redis lock');

            return self::SUCCESS;
        }

        if ($lock === null) {
            Log::error('Command get-bt failed: unable to acquire BT crawler Redis lock');

            return self::FAILURE;
        }

        Log::info('Command get-bt started');

        try {
            $getBtDataController->fetchData();
            Log::info('Command get-bt executed successfully');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Command get-bt failed: ' . $e->getMessage());

            return self::FAILURE;
        } finally {
            $this->releaseRunLock($lock);
        }
    }

    /**
     * @return Lock|false|null false means another run already holds the lock, null means Redis locking is unavailable.
     */
    private function acquireRunLock(): Lock|false|null
    {
        try {
            $lock = Cache::lock(self::RUN_LOCK_KEY, (int) config('bt.run_lock_seconds', 1800));

            return $lock->get() ? $lock : false;
        } catch (Throwable $e) {
            Log::error('Command get-bt could not reach the Redis lock store', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function releaseRunLock(?Lock $lock): void
    {
        if ($lock instanceof Lock) {
            $lock->release();
        }
    }
}
