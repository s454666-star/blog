<?php

namespace App\Console\Commands;

use App\Services\VideoRerunSyncService;
use App\Support\VideoRerunSyncSource;
use Illuminate\Console\Command;

class SyncRerunVideoSourcesCommand extends Command
{
    protected $signature = 'video:sync-rerun-sources
        {--force : 重新計算所有來源檔案的指紋}
        {--limit= : 三個來源都只先掃指定筆數}
        {--db-limit= : A 來源最多掃幾筆}
        {--rerun-limit= : B 來源最多掃幾筆}
        {--eagle-limit= : C 來源最多掃幾筆}';

    protected $description = '比對 video_master(type=1)、Z:\\video(重跑)、Eagle 重跑資源三邊差異，同檔不同名會用檔案指紋歸類。';

    public function handle(VideoRerunSyncService $syncService): int
    {
        $this->components->info('開始掃描三個來源，未變動檔案會直接跳過。');

        $sharedLimit = $this->parseLimitOption($this->option('limit'));
        $limits = [
            'db' => $this->parseLimitOption($this->option('db-limit')) ?? $sharedLimit,
            'rerun_disk' => $this->parseLimitOption($this->option('rerun-limit')) ?? $sharedLimit,
            'eagle' => $this->parseLimitOption($this->option('eagle-limit')) ?? $sharedLimit,
        ];

        if (array_filter($limits, static fn ($value) => $value !== null) !== []) {
            $this->line(sprintf(
                '本次限量掃描：A=%s, B=%s, C=%s',
                $limits['db'] ?? 'all',
                $limits['rerun_disk'] ?? 'all',
                $limits['eagle'] ?? 'all',
            ));
        }

        $this->line('正在預估總筆數...');

        $lastProgressPercent = -1;
        $lastSourceType = null;

        $run = $syncService->scan(
            (bool) $this->option('force'),
            $limits,
            function (array $progress) use (&$lastProgressPercent, &$lastSourceType): void {
                $type = (string) ($progress['type'] ?? '');

                if ($type === 'start') {
                    $sourceTotals = $progress['source_totals'] ?? [];

                    $this->line(sprintf(
                        '預估總筆數：A=%d, B=%d, C=%d, total=%d',
                        (int) ($sourceTotals[VideoRerunSyncSource::DB] ?? 0),
                        (int) ($sourceTotals[VideoRerunSyncSource::RERUN_DISK] ?? 0),
                        (int) ($sourceTotals[VideoRerunSyncSource::EAGLE] ?? 0),
                        (int) ($progress['overall_total'] ?? 0),
                    ));

                    return;
                }

                if ($type === 'stage_start') {
                    $this->line(sprintf(
                        '開始掃描 %s (%d/%d)',
                        (string) ($progress['source_label'] ?? $progress['source_type'] ?? 'unknown'),
                        (int) ($progress['source_processed'] ?? 0),
                        (int) ($progress['source_total'] ?? 0),
                    ));

                    return;
                }

                if ($type === 'finish' && (int) ($progress['overall_total'] ?? 0) === 0) {
                    $this->line('進度 100% (0/0) | 沒有可掃描的檔案');
                    return;
                }

                if ($type !== 'advance') {
                    return;
                }

                $overallProcessed = (int) ($progress['overall_processed'] ?? 0);
                $overallTotal = (int) ($progress['overall_total'] ?? 0);
                $overallPercent = min(100, (int) floor((float) ($progress['overall_percent'] ?? 0)));
                $sourceType = (string) ($progress['source_type'] ?? '');

                if ($overallPercent === $lastProgressPercent && $sourceType === $lastSourceType && $overallProcessed !== $overallTotal) {
                    return;
                }

                $lastProgressPercent = $overallPercent;
                $lastSourceType = $sourceType;

                $stats = is_array($progress['stats'] ?? null) ? $progress['stats'] : [];

                $this->line(sprintf(
                    '進度 %d%% (%d/%d) | %s %d/%d | hashed=%d skipped=%d missing=%d | %s',
                    $overallPercent,
                    $overallProcessed,
                    $overallTotal,
                    (string) ($progress['source_label'] ?? $sourceType),
                    (int) ($progress['source_processed'] ?? 0),
                    (int) ($progress['source_total'] ?? 0),
                    (int) ($stats['hashed'] ?? 0),
                    (int) ($stats['skipped'] ?? 0),
                    (int) ($stats['missing'] ?? 0),
                    (string) ($progress['display_name'] ?? ''),
                ));
            }
        );
        $summary = $run->summary_json ?? [];

        $this->table(
            ['來源', '看到的檔案數'],
            [
                ['A. video_master(type=1)', (string) $run->db_seen_count],
                ['B. Z:\\video(重跑)', (string) $run->rerun_seen_count],
                ['C. Eagle 重跑資源', (string) $run->eagle_seen_count],
            ]
        );

        $this->newLine();
        $this->line('hashed: ' . $run->hashed_count);
        $this->line('skipped: ' . $run->skipped_count);
        $this->line('missing file: ' . $run->missing_file_count);
        $this->line('diff groups: ' . ($summary['diff_groups'] ?? 0));
        $this->line('issues: ' . ($summary['issues'] ?? 0));
        $this->newLine();
        $this->components->info('差異頁：' . route('videos.rerun-sync.index'));

        return self::SUCCESS;
    }

    private function parseLimitOption(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }
}
