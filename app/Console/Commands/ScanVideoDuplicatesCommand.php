<?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use App\Models\VideoDuplicate;
    use Illuminate\Console\Command;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\DB;
    use Symfony\Component\Process\Process;
    use DirectoryIterator;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use FilesystemIterator;
    use Throwable;

    class ScanVideoDuplicatesCommand extends Command
    {
        protected $signature = 'video:scan-duplicates
{path : 目標資料夾路徑}
{recursive? : 1=含子資料夾(預設1),0=只掃一層}
{refresh? : 1=強制重建該路徑底下已存在記錄(預設0)}
{threshold? : 相似度百分比(預設80)}
{window_seconds? : 候選比對的時長容許秒數(預設15)}
{size_percent? : 候選比對的檔案大小容許百分比(預設15)}
{max_candidates? : 每個影片最多拉多少候選來比對(預設3000)}
{limit? : 最多處理多少支影片(0=不限制)}
{debug? : 1=輸出候選與相似度摘要(預設0)}
{min_match? : 需符合幾張截圖(預設4，可設3)}';

        protected $description = '掃描 mp4 影片重複：固定 1/2/3/4 分鐘擷取 720p JPEG 存 base64，比對 dHash 相似度>=門檻；不足 4 分鐘則用能擷取到的截圖做比對；similar_video_ids 用逗號串起來(含自己)';

        private const SNAPSHOT_MINUTES = [1, 2, 3, 4];
        private const SNAPSHOT_HEIGHT = 720;

        private const JPEG_QUALITY = 60;

        private const HASH_BITS = 64;

        private const HASH_PREFIX_LEN = 2;
        private const SCORE_EXACT = 64;
        private const SCORE_PREFIX = 4;
        private const SCORE_WINDOW = 8;
        private const SCORE_SIZE = 8;

        public function handle(): int
        {
            $pathArg = (string) $this->argument('path');
            $rootPath = $this->normalizePath($pathArg);
            if ($rootPath === '' || !is_dir($rootPath)) {
                $this->error('path 不是有效資料夾：' . $pathArg);
                return 1;
            }

            $recursive = $this->toIntDefault($this->argument('recursive'), 1) === 1;
            $refresh = $this->toIntDefault($this->argument('refresh'), 0) === 1;

            $threshold = $this->toIntDefault($this->argument('threshold'), 80);
            if ($threshold < 1) {
                $threshold = 1;
            }
            if ($threshold > 100) {
                $threshold = 100;
            }

            $windowSeconds = $this->toIntDefault($this->argument('window_seconds'), 15);
            if ($windowSeconds < 0) {
                $windowSeconds = 0;
            }

            $sizePercent = $this->toIntDefault($this->argument('size_percent'), 15);
            if ($sizePercent < 0) {
                $sizePercent = 0;
            }
            if ($sizePercent > 90) {
                $sizePercent = 90;
            }

            $maxCandidates = $this->toIntDefault($this->argument('max_candidates'), 3000);
            if ($maxCandidates < 1) {
                $maxCandidates = 1;
            }

            $limit = $this->toIntDefault($this->argument('limit'), 0);
            if ($limit < 0) {
                $limit = 0;
            }

            $debug = $this->toIntDefault($this->argument('debug'), 0) === 1;

            $minMatch = $this->toIntDefault($this->argument('min_match'), 4);
            if ($minMatch < 1) {
                $minMatch = 1;
            }
            if ($minMatch > 4) {
                $minMatch = 4;
            }

            $ffmpegBin = (string) env('FFMPEG_BIN', 'ffmpeg');
            $ffprobeBin = (string) env('FFPROBE_BIN', 'ffprobe');

            $tmpDir = storage_path('app/video_dedup/tmp');
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0777, true);
            }

            $this->info('Root: ' . $rootPath);
            $this->info('Recursive: ' . ($recursive ? '1' : '0') . '  Refresh: ' . ($refresh ? '1' : '0'));
            $this->info('Threshold: ' . $threshold . '%  MinMatch: ' . $minMatch . '  WindowSeconds: ' . $windowSeconds . '  SizePercent: ' . $sizePercent . '%');
            $this->info('MaxCandidates: ' . $maxCandidates . '  Limit: ' . $limit . '  Debug: ' . ($debug ? '1' : '0'));

            $files = $this->collectMp4Files($rootPath, $recursive);
            $total = count($files);

            if ($total === 0) {
                $this->warn('找不到 mp4');
                return 0;
            }

            $this->info('找到 mp4 數量: ' . $total);

            $processed = 0;
            $skipped = 0;
            $failed = 0;
            $dupHit = 0;

            foreach ($files as $idx => $filePath) {
                if ($limit > 0 && $processed >= $limit) {
                    break;
                }

                $processed += 1;
                $this->line('[' . $processed . '/' . $total . '] ' . $filePath);

                try {
                    $fullPath = $this->normalizePath($filePath);
                    if ($fullPath === '' || !is_file($fullPath)) {
                        $skipped += 1;
                        $this->warn('  跳過：不是檔案');
                        continue;
                    }

                    $filename = basename($fullPath);
                    $pathSha1 = sha1($fullPath);

                    $fileSize = (int) @filesize($fullPath);
                    $mtime = (int) @filemtime($fullPath);

                    $existing = VideoDuplicate::query()
                        ->select(['id', 'filename', 'full_path', 'file_size_bytes', 'file_mtime'])
                        ->where('full_path_sha1', $pathSha1)
                        ->first();

                    if ($existing !== null && !$refresh) {
                        $samePath = (string) ($existing->full_path ?? '') === $fullPath;
                        $sameName = (string) ($existing->filename ?? '') === $filename;

                        if ($samePath && $sameName) {
                            $skipped += 1;
                            $this->line('  已存在同路徑同檔名，跳過');
                            continue;
                        }

                        $sameSize = (int) $existing->file_size_bytes === $fileSize;
                        $sameMtime = (int) $existing->file_mtime === $mtime;
                        if ($sameSize && $sameMtime) {
                            $skipped += 1;
                            $this->line('  已存在且未變更，跳過');
                            continue;
                        }
                    }

                    $durationSeconds = $this->probeDurationSeconds($ffprobeBin, $fullPath);
                    if ($durationSeconds <= 0) {
                        $this->markError($pathSha1, 'ffprobe 取得 duration 失敗或 duration=0');
                        $failed += 1;
                        $this->error('  失敗：duration 取得失敗');
                        continue;
                    }

                    if ($debug) {
                        $this->line('  duration_seconds=' . $durationSeconds . '  file_size_bytes=' . $fileSize);
                    }

                    $shots = $this->makeSnapshotsFixedMinutesAllowShort($ffmpegBin, $tmpDir, $fullPath, $durationSeconds);

                    $b64s = ['', '', '', ''];
                    $hashHexes = ['', '', '', ''];

                    $availableShots = 0;

                    $i = 0;
                    while ($i < 4) {
                        $shotPath = $shots[$i];
                        if (is_string($shotPath) && $shotPath !== '' && is_file($shotPath)) {
                            $availableShots += 1;

                            $this->normalizeJpegInPlace($shotPath, self::JPEG_QUALITY);

                            $b64s[$i] = base64_encode((string) file_get_contents($shotPath));
                            $hashHexes[$i] = $this->computeDHashHexFromJpeg($shotPath);
                        }
                        $i += 1;
                    }

                    if ($availableShots <= 0) {
                        $this->markError($pathSha1, '無法擷取任何截圖（影片太短或 ffmpeg 擷取失敗）');
                        $failed += 1;
                        $this->error('  失敗：無法擷取任何截圖');
                        continue;
                    }

                    if ($availableShots < 4) {
                        $this->line('  影片不足 4 分鐘：只擷取到 ' . $availableShots . ' 張（用能擷取到的截圖比對）');
                    }

                    $candidates = $this->loadCandidatesNoHardFilter(
                        $durationSeconds,
                        $fileSize,
                        $windowSeconds,
                        $sizePercent,
                        $maxCandidates,
                        $pathSha1,
                        $hashHexes
                    );

                    $candCount = is_countable($candidates) ? count($candidates) : 0;
                    $this->line('  候選數: ' . $candCount);

                    if ($debug && $candCount > 0) {
                        $this->printTopCandidateSimilarities($hashHexes, $candidates, 10);
                    }

                    $duplicateIds = [];
                    foreach ($candidates as $cand) {
                        if ($this->isDuplicateByAvailableHashes(
                            $hashHexes,
                            [
                                (string) ($cand->hash1_hex ?? ''),
                                (string) ($cand->hash2_hex ?? ''),
                                (string) ($cand->hash3_hex ?? ''),
                                (string) ($cand->hash4_hex ?? ''),
                            ],
                            $threshold,
                            $minMatch
                        )) {
                            $duplicateIds[] = (int) $cand->id;
                        }
                    }

                    $now = Carbon::now();

                    DB::beginTransaction();
                    try {
                        $current = $existing ?? new VideoDuplicate();

                        $current->filename = $filename;
                        $current->full_path = $fullPath;
                        $current->full_path_sha1 = $pathSha1;
                        $current->file_size_bytes = $fileSize;
                        $current->file_mtime = $mtime;
                        $current->duration_seconds = $durationSeconds;

                        $current->snapshot1_b64 = $b64s[0];
                        $current->snapshot2_b64 = $b64s[1];
                        $current->snapshot3_b64 = $b64s[2];
                        $current->snapshot4_b64 = $b64s[3];

                        $current->hash1_hex = $hashHexes[0];
                        $current->hash2_hex = $hashHexes[1];
                        $current->hash3_hex = $hashHexes[2];
                        $current->hash4_hex = $hashHexes[3];

                        $current->last_error = null;

                        if ($current->exists) {
                            $current->updated_at = $now;
                        } else {
                            $current->similar_video_ids = '';
                            $current->created_at = $now;
                            $current->updated_at = $now;
                        }

                        $current->save();

                        $currentId = (int) $current->id;
                        $groupIds = $this->mergeGroupIdsFromDuplicates($currentId, $duplicateIds);

                        sort($groupIds);
                        $csv = implode(',', $groupIds);

                        VideoDuplicate::query()
                            ->whereIn('id', $groupIds)
                            ->update([
                                'similar_video_ids' => $csv,
                                'updated_at' => $now,
                            ]);

                        if (count($groupIds) > 1) {
                            $dupHit += 1;
                        }

                        DB::commit();
                    } catch (Throwable $e) {
                        DB::rollBack();
                        throw $e;
                    }

                    if (count($duplicateIds) > 0) {
                        $this->info('  重複命中: ' . implode(',', $duplicateIds));
                    } else {
                        $this->line('  無重複');
                    }
                } catch (Throwable $e) {
                    $failed += 1;
                    $this->error('  例外: ' . $e->getMessage());
                } finally {
                    $this->cleanupTmpDir($tmpDir);
                }
            }

            $this->newLine();
            $this->info('完成');
            $this->info('Processed: ' . $processed . '  Skipped: ' . $skipped . '  Failed: ' . $failed . '  DuplicateHit: ' . $dupHit);

            return 0;
        }

        private function normalizePath(string $path): string
        {
            $path = trim($path);
            if ($path === '') {
                return '';
            }

            $real = @realpath($path);
            if (is_string($real) && $real !== '') {
                return $real;
            }

            return $path;
        }

        private function toIntDefault(mixed $value, int $default): int
        {
            if (is_int($value)) {
                return $value;
            }

            if (is_string($value) && $value !== '' && is_numeric($value)) {
                return (int) $value;
            }

            return $default;
        }

        private function collectMp4Files(string $rootPath, bool $recursive): array
        {
            $result = [];

            if ($recursive) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($it as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }

                    $path = $fileInfo->getPathname();
                    if ($this->isMp4($path)) {
                        $result[] = $path;
                    }
                }
            } else {
                foreach (new DirectoryIterator($rootPath) as $fileInfo) {
                    if ($fileInfo->isDot()) {
                        continue;
                    }

                    if (!$fileInfo->isFile()) {
                        continue;
                    }

                    $path = $fileInfo->getPathname();
                    if ($this->isMp4($path)) {
                        $result[] = $path;
                    }
                }
            }

            usort($result, function ($a, $b) {
                $mtimeA = (int) @filemtime($a);
                $mtimeB = (int) @filemtime($b);

                if ($mtimeA === $mtimeB) {
                    return 0;
                }

                return ($mtimeA > $mtimeB) ? -1 : 1;
            });

            return $result;
        }

        private function isMp4(string $path): bool
        {
            $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            return $ext === 'mp4';
        }

        private function probeDurationSeconds(string $ffprobeBin, string $videoPath): int
        {
            $process = new Process([
                $ffprobeBin,
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=nokey=1:noprint_wrappers=1',
                $videoPath,
            ]);

            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                return 0;
            }

            $out = trim($process->getOutput());
            if ($out === '' || !is_numeric($out)) {
                return 0;
            }

            $seconds = (int) floor((float) $out);
            return max(0, $seconds);
        }

        private function makeSnapshotsFixedMinutesAllowShort(string $ffmpegBin, string $tmpDir, string $videoPath, int $durationSeconds): array
        {
            $shots = [null, null, null, null];

            $safeMax = max(1, $durationSeconds - 1);

            $i = 0;
            while ($i < 4) {
                $minute = self::SNAPSHOT_MINUTES[$i];
                $target = $minute * 60;

                if ($target > $safeMax) {
                    $shots[$i] = null;
                    $i += 1;
                    continue;
                }

                $ss = $target;

                $outPath = $tmpDir . DIRECTORY_SEPARATOR . 'shot_' . ($i + 1) . '_' . uniqid('', true) . '.jpg';

                $process = new Process([
                    $ffmpegBin,
                    '-hide_banner',
                    '-loglevel', 'error',
                    '-y',
                    '-ss', (string) $ss,
                    '-i', $videoPath,
                    '-frames:v', '1',
                    '-vf', 'scale=-2:' . (string) self::SNAPSHOT_HEIGHT,
                    '-q:v', '6',
                    $outPath,
                ]);

                $process->setTimeout(180);
                $process->run();

                if (!$process->isSuccessful() || !is_file($outPath) || (int) filesize($outPath) <= 0) {
                    $shots[$i] = null;
                    $i += 1;
                    continue;
                }

                $shots[$i] = $outPath;
                $i += 1;
            }

            return $shots;
        }

        private function normalizeJpegInPlace(string $jpegPath, int $quality): void
        {
            if (!extension_loaded('gd')) {
                return;
            }

            $img = @imagecreatefromjpeg($jpegPath);
            if (!$img) {
                return;
            }

            $w = imagesx($img);
            $h = imagesy($img);

            if ($h > self::SNAPSHOT_HEIGHT) {
                $newH = self::SNAPSHOT_HEIGHT;
                $newW = (int) floor($w * ($newH / max(1, $h)));
                $newW = max(2, $newW);

                $dst = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);

                imagejpeg($dst, $jpegPath, $quality);

                imagedestroy($dst);
                imagedestroy($img);
                return;
            }

            imagejpeg($img, $jpegPath, $quality);
            imagedestroy($img);
        }

        private function computeDHashHexFromJpeg(string $jpegPath): string
        {
            if (!extension_loaded('gd')) {
                throw new \RuntimeException('需要 GD extension 才能算 dHash');
            }

            $img = @imagecreatefromjpeg($jpegPath);
            if (!$img) {
                throw new \RuntimeException('讀取 JPEG 失敗：' . $jpegPath);
            }

            $smallW = 9;
            $smallH = 8;

            $small = imagecreatetruecolor($smallW, $smallH);
            imagecopyresampled(
                $small,
                $img,
                0,
                0,
                0,
                0,
                $smallW,
                $smallH,
                imagesx($img),
                imagesy($img)
            );

            imagedestroy($img);

            $bytes = array_fill(0, 8, 0);
            $bitIndex = 0;

            $y = 0;
            while ($y < $smallH) {
                $x = 0;
                while ($x < $smallW - 1) {
                    $left = $this->grayAt($small, $x, $y);
                    $right = $this->grayAt($small, $x + 1, $y);

                    $bit = $left > $right ? 1 : 0;

                    $bytePos = intdiv($bitIndex, 8);
                    $bitPos = 7 - ($bitIndex % 8);

                    if ($bit === 1) {
                        $bytes[$bytePos] |= (1 << $bitPos);
                    }

                    $bitIndex += 1;
                    $x += 1;
                }
                $y += 1;
            }

            imagedestroy($small);

            $hex = '';
            $i = 0;
            while ($i < 8) {
                $hex .= str_pad(dechex($bytes[$i] & 255), 2, '0', STR_PAD_LEFT);
                $i += 1;
            }

            return strtolower($hex);
        }

        private function grayAt($img, int $x, int $y): int
        {
            $rgb = imagecolorat($img, $x, $y);

            $r = ($rgb >> 16) & 255;
            $g = ($rgb >> 8) & 255;
            $b = $rgb & 255;

            return (int) floor(($r + $g + $b) / 3);
        }

        private function loadCandidatesNoHardFilter(
            int $durationSeconds,
            int $fileSize,
            int $windowSeconds,
            int $sizePercent,
            int $maxCandidates,
            string $excludePathSha1,
            array $hashHexes
        ) {
            $h1 = (string) ($hashHexes[0] ?? '');
            $h2 = (string) ($hashHexes[1] ?? '');
            $h3 = (string) ($hashHexes[2] ?? '');
            $h4 = (string) ($hashHexes[3] ?? '');

            $p1 = substr($h1, 0, self::HASH_PREFIX_LEN);
            $p2 = substr($h2, 0, self::HASH_PREFIX_LEN);
            $p3 = substr($h3, 0, self::HASH_PREFIX_LEN);
            $p4 = substr($h4, 0, self::HASH_PREFIX_LEN);

            $minDur = max(0, $durationSeconds - $windowSeconds);
            $maxDur = $durationSeconds + $windowSeconds;

            $ratio = $sizePercent / 100;
            $minSize = (int) floor($fileSize * (1 - $ratio));
            $maxSize = (int) ceil($fileSize * (1 + $ratio));

            $scoreExpr =
                '(' .
                '(CASE WHEN hash1_hex = ? THEN ' . self::SCORE_EXACT . ' ELSE 0 END) + ' .
                '(CASE WHEN hash2_hex = ? THEN ' . self::SCORE_EXACT . ' ELSE 0 END) + ' .
                '(CASE WHEN hash3_hex = ? THEN ' . self::SCORE_EXACT . ' ELSE 0 END) + ' .
                '(CASE WHEN hash4_hex = ? THEN ' . self::SCORE_EXACT . ' ELSE 0 END) + ' .
                '(CASE WHEN LEFT(hash1_hex,' . self::HASH_PREFIX_LEN . ') = ? THEN ' . self::SCORE_PREFIX . ' ELSE 0 END) + ' .
                '(CASE WHEN LEFT(hash2_hex,' . self::HASH_PREFIX_LEN . ') = ? THEN ' . self::SCORE_PREFIX . ' ELSE 0 END) + ' .
                '(CASE WHEN LEFT(hash3_hex,' . self::HASH_PREFIX_LEN . ') = ? THEN ' . self::SCORE_PREFIX . ' ELSE 0 END) + ' .
                '(CASE WHEN LEFT(hash4_hex,' . self::HASH_PREFIX_LEN . ') = ? THEN ' . self::SCORE_PREFIX . ' ELSE 0 END) + ' .
                '(CASE WHEN duration_seconds BETWEEN ? AND ? THEN ' . self::SCORE_WINDOW . ' ELSE 0 END) + ' .
                '(CASE WHEN file_size_bytes BETWEEN ? AND ? THEN ' . self::SCORE_SIZE . ' ELSE 0 END)' .
                ')';

            $bindings = [
                $h1, $h2, $h3, $h4,
                $p1, $p2, $p3, $p4,
                $minDur, $maxDur,
                $minSize, $maxSize,
            ];

            return VideoDuplicate::query()
                ->select([
                    'id',
                    'hash1_hex',
                    'hash2_hex',
                    'hash3_hex',
                    'hash4_hex',
                    'similar_video_ids',
                    'duration_seconds',
                    'file_size_bytes',
                    'full_path',
                ])
                ->where('full_path_sha1', '!=', $excludePathSha1)
                ->where(function ($q) {
                    $q->where('hash1_hex', '!=', '')
                        ->orWhere('hash2_hex', '!=', '')
                        ->orWhere('hash3_hex', '!=', '')
                        ->orWhere('hash4_hex', '!=', '');
                })
                ->orderByRaw($scoreExpr . ' DESC', $bindings)
                ->orderByRaw('ABS(CAST(duration_seconds AS SIGNED) - ?) asc', [$durationSeconds])
                ->orderByRaw('ABS(CAST(file_size_bytes AS SIGNED) - ?) asc', [$fileSize])
                ->orderBy('id', 'desc')
                ->limit($maxCandidates)
                ->get();
        }

        private function isDuplicateByAvailableHashes(array $a, array $b, int $thresholdPercent, int $minMatch): bool
        {
            $overlap = 0;
            $hits = 0;

            $i = 0;
            while ($i < 4) {
                $ha = (string) ($a[$i] ?? '');
                $hb = (string) ($b[$i] ?? '');

                if ($this->isValidHex64($ha) && $this->isValidHex64($hb)) {
                    $overlap += 1;
                    if ($this->hashSimilarityPercent($ha, $hb) >= $thresholdPercent) {
                        $hits += 1;
                    }
                }

                $i += 1;
            }

            if ($overlap <= 0) {
                return false;
            }

            $need = $minMatch;
            if ($need > $overlap) {
                $need = $overlap;
            }

            return $hits >= $need;
        }

        private function isValidHex64(string $hex): bool
        {
            $hex = strtolower(trim($hex));
            if (strlen($hex) !== 16) {
                return false;
            }
            return (bool) preg_match('/^[0-9a-f]{16}$/', $hex);
        }

        private function hashSimilarityPercent(string $hexA, string $hexB): int
        {
            $dist = $this->hammingDistanceHex64($hexA, $hexB);
            $same = self::HASH_BITS - $dist;
            $percent = (int) floor(($same * 100) / self::HASH_BITS);
            return max(0, min(100, $percent));
        }

        private function hammingDistanceHex64(string $hexA, string $hexB): int
        {
            $hexA = strtolower(trim($hexA));
            $hexB = strtolower(trim($hexB));

            if (!$this->isValidHex64($hexA) || !$this->isValidHex64($hexB)) {
                return self::HASH_BITS;
            }

            static $hexVal = null;
            static $pop = null;

            if ($hexVal === null) {
                $hexVal = [
                    '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5, '6' => 6, '7' => 7,
                    '8' => 8, '9' => 9, 'a' => 10, 'b' => 11, 'c' => 12, 'd' => 13, 'e' => 14, 'f' => 15,
                ];
            }

            if ($pop === null) {
                $pop = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
            }

            $dist = 0;

            $i = 0;
            while ($i < 16) {
                $ca = $hexA[$i];
                $cb = $hexB[$i];

                $x = $hexVal[$ca] ^ $hexVal[$cb];
                $dist += $pop[$x];

                $i += 1;
            }

            return $dist;
        }

        private function mergeGroupIdsFromDuplicates(int $currentId, array $duplicateIds): array
        {
            $ids = [$currentId];

            foreach ($duplicateIds as $id) {
                $ids[] = (int) $id;
            }

            $rows = VideoDuplicate::query()
                ->select(['id', 'similar_video_ids'])
                ->whereIn('id', $ids)
                ->get();

            foreach ($rows as $row) {
                $ids[] = (int) $row->id;

                $csv = (string) ($row->similar_video_ids ?? '');
                $csv = trim($csv);
                if ($csv === '') {
                    continue;
                }

                $parts = explode(',', $csv);
                foreach ($parts as $p) {
                    $p = trim($p);
                    if ($p === '' || !ctype_digit($p)) {
                        continue;
                    }
                    $ids[] = (int) $p;
                }
            }

            $ids = array_values(array_unique(array_filter($ids, function ($v) {
                return is_int($v) && $v > 0;
            })));

            return $ids;
        }

        private function markError(string $pathSha1, string $message): void
        {
            try {
                VideoDuplicate::query()
                    ->where('full_path_sha1', $pathSha1)
                    ->update([
                        'last_error' => $message,
                        'updated_at' => Carbon::now(),
                    ]);
            } catch (Throwable $e) {
            }
        }

        private function cleanupTmpDir(string $tmpDir): void
        {
            if (!is_dir($tmpDir)) {
                return;
            }

            $files = @scandir($tmpDir);
            if (!is_array($files)) {
                return;
            }

            foreach ($files as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                $p = $tmpDir . DIRECTORY_SEPARATOR . $f;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
        }

        private function printTopCandidateSimilarities(array $hashHexes, $candidates, int $topN): void
        {
            $n = 0;
            foreach ($candidates as $cand) {
                if ($n >= $topN) {
                    break;
                }

                $candHashes = [
                    (string) ($cand->hash1_hex ?? ''),
                    (string) ($cand->hash2_hex ?? ''),
                    (string) ($cand->hash3_hex ?? ''),
                    (string) ($cand->hash4_hex ?? ''),
                ];

                $sim = [];
                $i = 0;
                while ($i < 4) {
                    $a = (string) ($hashHexes[$i] ?? '');
                    $b = (string) ($candHashes[$i] ?? '');
                    if ($this->isValidHex64($a) && $this->isValidHex64($b)) {
                        $sim[] = (string) $this->hashSimilarityPercent($a, $b);
                    } else {
                        $sim[] = 'NA';
                    }
                    $i += 1;
                }

                $this->line(
                    '  cand_id=' . (int) $cand->id .
                    ' dur=' . (int) ($cand->duration_seconds ?? 0) .
                    ' size=' . (int) ($cand->file_size_bytes ?? 0) .
                    ' sim=[' . implode(',', $sim) . ']' .
                    ' path=' . (string) ($cand->full_path ?? '')
                );

                $n += 1;
            }
        }
    }
