<?php

namespace App\Console\Commands;

use App\Services\VideoRerunSyncService;
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

        $run = $syncService->scan((bool) $this->option('force'), $limits);
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
