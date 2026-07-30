<?php

namespace App\Console\Commands;

use App\Services\TwFuturesRealtimeQuoteService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConsumeTwFuturesRealtimeQuoteCommand extends Command
{
    protected $signature = 'tw-stock:consume-taiex-futures-realtime
                            {--watch : Keep consuming Redis until the process is stopped}
                            {--interval-ms=1000 : Polling interval while watching}';

    protected $description = 'Consume the short-lived authenticated TradingView quote from Redis into the AWS database latest-quote row.';

    public function handle(TwFuturesRealtimeQuoteService $service): int
    {
        $watch = (bool) $this->option('watch');
        $intervalMilliseconds = max(250, min(5000, (int) $this->option('interval-ms')));
        $lastHeartbeatAt = 0;

        do {
            try {
                $result = $service->consumeLatest();
                if (! $watch) {
                    $this->line('status=' . $result['status']);
                } elseif ($result['status'] === 'stored' && time() - $lastHeartbeatAt >= 60) {
                    $lastHeartbeatAt = time();
                    Log::info('台指期 Redis 即時報價已持續寫入資料庫。', [
                        'symbol' => TwFuturesRealtimeQuoteService::SYMBOL,
                        'written_at' => $result['quote']?->written_at?->toIso8601String(),
                    ]);
                }
            } catch (Throwable $exception) {
                Log::error('台指期 Redis 即時報價 consumer 執行失敗。', [
                    'error' => $exception->getMessage(),
                ]);
                if (! $watch) {
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }
            }

            if ($watch) {
                usleep($intervalMilliseconds * 1000);
            }
        } while ($watch);

        return self::SUCCESS;
    }
}
