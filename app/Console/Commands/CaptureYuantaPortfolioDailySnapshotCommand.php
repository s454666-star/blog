<?php

namespace App\Console\Commands;

use App\Services\YuantaPortfolioService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class CaptureYuantaPortfolioDailySnapshotCommand extends Command
{
    protected $signature = 'yuanta:portfolio-capture-daily
        {--date= : 快照日期，格式 YYYY-MM-DD；預設今天}
        {--no-force : 不強制查詢元大 API，直接使用目前快取}';

    protected $description = '收盤後保存元大庫存每日快照，供看板依日期回查。';

    public function handle(YuantaPortfolioService $service): int
    {
        try {
            $snapshotDate = $this->resolveSnapshotDate();
            $snapshot = $service->captureDailySnapshot($snapshotDate, !$this->option('no-force'));
        } catch (Throwable $e) {
            report($e);
            $this->error('元大每日快照保存失敗：' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '元大每日快照已保存 date=%s captured_at=%s rows=%d today_pnl=%s unrealized_pnl=%s source=%s',
            $snapshot->snapshot_date?->toDateString() ?? '--',
            $snapshot->captured_at?->toDateTimeString() ?? '--',
            $snapshot->stock_count,
            $snapshot->today_pnl === null ? '--' : (string) round((float) $snapshot->today_pnl, 2),
            $snapshot->unrealized_pnl === null ? '--' : (string) round((float) $snapshot->unrealized_pnl, 2),
            $snapshot->source_status ?? '--',
        ));

        return self::SUCCESS;
    }

    private function resolveSnapshotDate(): CarbonImmutable
    {
        $date = trim((string) $this->option('date'));
        if ($date === '') {
            return CarbonImmutable::today((string) config('yuanta.timezone', 'Asia/Taipei'));
        }

        $parsed = CarbonImmutable::createFromFormat('Y-m-d', str_replace('/', '-', $date), (string) config('yuanta.timezone', 'Asia/Taipei'));
        if (!$parsed instanceof CarbonImmutable) {
            throw new \InvalidArgumentException('日期格式錯誤：' . $date);
        }

        return $parsed->startOfDay();
    }
}
