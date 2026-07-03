<?php

namespace App\Console\Commands;

use App\Services\EsunPortfolioService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class CaptureEsunPortfolioDailySnapshotCommand extends Command
{
    protected $signature = 'esun:portfolio-capture-daily
        {--date= : 快照日期，格式 YYYY-MM-DD；預設今天}
        {--no-force : 不強制查詢玉山 API，直接使用目前快取}';

    protected $description = '收盤後保存玉山庫存每日快照，供看板依日期回查。';

    public function handle(EsunPortfolioService $service): int
    {
        try {
            $snapshotDate = $this->resolveSnapshotDate();
            $snapshot = $service->captureDailySnapshot($snapshotDate, !$this->option('no-force'));
        } catch (Throwable $e) {
            report($e);
            $this->error('玉山每日快照保存失敗：' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '玉山每日快照已保存 date=%s captured_at=%s rows=%d today_pnl=%s unrealized_pnl=%s source=%s',
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
            return CarbonImmutable::today((string) config('esun.timezone', 'Asia/Taipei'));
        }

        $parsed = CarbonImmutable::createFromFormat('Y-m-d', str_replace('/', '-', $date), (string) config('esun.timezone', 'Asia/Taipei'));
        if (!$parsed instanceof CarbonImmutable) {
            throw new \InvalidArgumentException('日期格式錯誤：' . $date);
        }

        return $parsed->startOfDay();
    }
}
