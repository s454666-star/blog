<?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use DirectoryIterator;
    use FilesystemIterator;
    use RecursiveDirectoryIterator;
    use RecursiveIteratorIterator;
    use SplFileInfo;
    use Throwable;

    class RenameTokyoHotWmvFilenamesCommand extends Command
    {
        protected $signature = 'media:rename-tokyohot-wmv
        {path? : 目標資料夾路徑，預設 F:\Tokyo-Hot}
        {recursive? : 1=含子資料夾(預設)，0=只處理該層}';

        protected $description = '批次修正 Tokyo-Hot .wmv 檔名（直接改名）：一般來源只保留 nxxxx 與可選 A/B/C；若 [BT-btt.com] 開頭則保留 nxxxx 後文字；支援 nxxxx / nxxxxp2 / nxxxx 其他字 p2 這種檔名';

        public function handle(): int
        {
            $pathArg = $this->argument('path');
            $recursiveArg = $this->argument('recursive');

            $path = is_string($pathArg) ? trim($pathArg) : '';
            if ($path === '') {
                $path = 'F:\\Tokyo-Hot';
            }
            $path = $this->normalizePath($path);

            $recursive = $this->toBool($recursiveArg, true);

            if (!is_dir($path)) {
                $this->error('資料夾不存在：' . $path);
                return self::FAILURE;
            }

            $this->line('掃描目錄：' . $path);
            $this->line('含子資料夾：' . ($recursive ? '是' : '否'));
            $this->line('模式：直接改名');
            $this->newLine();

            $iterator = $this->buildIterator($path, $recursive);

            $scanned = 0;
            $planned = 0;
            $renamed = 0;
            $skipped = 0;
            $failed = 0;

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof SplFileInfo) {
                    continue;
                }
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $ext = strtolower((string) $fileInfo->getExtension());
                if ($ext !== 'wmv') {
                    continue;
                }

                $scanned += 1;

                $oldPath = $fileInfo->getPathname();
                $dir = $fileInfo->getPath();
                $oldFile = $fileInfo->getFilename();
                $oldBase = (string) pathinfo($oldFile, PATHINFO_FILENAME);

                if ($this->shouldKeepAsIs($oldBase)) {
                    $skipped += 1;
                    continue;
                }

                $newBase = $this->buildNewBaseName($oldBase);
                if ($newBase === null) {
                    $skipped += 1;
                    $this->warn('[略過] 無法解析：' . $oldFile);
                    continue;
                }

                $desiredTargetPath = $dir . DIRECTORY_SEPARATOR . $newBase . '.wmv';
                if ($this->samePath($oldPath, $desiredTargetPath)) {
                    $skipped += 1;
                    continue;
                }

                $finalTargetPath = $this->ensureUniqueTargetPath($dir, $newBase, 'wmv');
                $finalTargetName = (string) basename($finalTargetPath);

                $planned += 1;

                try {
                    $ok = @rename($oldPath, $finalTargetPath);
                    if ($ok) {
                        $renamed += 1;
                        $this->info('[完成] ' . $oldFile . '  =>  ' . $finalTargetName);
                    } else {
                        $failed += 1;
                        $this->error('[失敗] ' . $oldFile . '  =>  ' . $finalTargetName);
                    }
                } catch (Throwable $e) {
                    $failed += 1;
                    $this->error('[例外] ' . $oldFile . '  =>  ' . $finalTargetName);
                    $this->error('原因：' . $e->getMessage());
                }
            }

            $this->newLine();
            $this->line('掃描檔案數：' . $scanned);
            $this->line('需要改名數：' . $planned);
            $this->line('已改名數：' . $renamed);
            $this->line('略過數：' . $skipped);
            $this->line('失敗數：' . $failed);

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        private function buildIterator(string $path, bool $recursive): iterable
        {
            if ($recursive) {
                $dirIterator = new RecursiveDirectoryIterator(
                    $path,
                    FilesystemIterator::SKIP_DOTS
                );

                return new RecursiveIteratorIterator($dirIterator);
            }

            return new DirectoryIterator($path);
        }

        private function normalizePath(string $path): string
        {
            $path = trim($path);
            $path = str_replace('/', DIRECTORY_SEPARATOR, $path);

            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        private function samePath(string $a, string $b): bool
        {
            $aNorm = $this->normalizePath($a);
            $bNorm = $this->normalizePath($b);

            if (DIRECTORY_SEPARATOR === '\\') {
                return strtolower($aNorm) === strtolower($bNorm);
            }

            return $aNorm === $bNorm;
        }

        private function shouldKeepAsIs(string $baseName): bool
        {
            $baseName = trim($baseName);

            $m = [];
            $ok = preg_match('/^Tokyo-Hot\s+(n\d{4})(?:\s+([A-Z]))?(.*)$/', $baseName, $m);
            if ($ok !== 1) {
                return false;
            }

            $rest = isset($m[3]) ? (string) $m[3] : '';
            $restTrimLeft = ltrim($rest);

            if ($restTrimLeft === '') {
                return true;
            }

            if (preg_match('/^[\[\]]/', $restTrimLeft) === 1) {
                return false;
            }

            if (preg_match('/^[\.\-]+$/', trim($restTrimLeft)) === 1) {
                return false;
            }

            return true;
        }

        private function buildNewBaseName(string $baseName): ?string
        {
            $raw = trim($baseName);
            $isBtBtt = $this->isBtBttPrefix($raw);

            $match = [];
            $ok = preg_match('/n(\d{4})/i', $raw, $match, PREG_OFFSET_CAPTURE);
            if ($ok !== 1) {
                return null;
            }

            $digits = (string) $match[1][0];
            $fullMatch = (string) $match[0][0];
            $offset = (int) $match[0][1];

            $id = 'n' . $digits;

            $posEnd = $offset + strlen($fullMatch);
            $tail = substr($raw, $posEnd);
            $tail = is_string($tail) ? $tail : '';

            $letter = null;
            $tailAfterSuffix = $tail;

            $page = $this->extractPageFromTail($tail);
            if ($page !== null) {
                $letter = $this->pageToLetters($page);
                $tailAfterSuffix = $this->removeFirstPageTokenFromTail($tail);
            } else {
                $lm = [];
                $hasLetter = preg_match('/^\s*([A-Z])\b/i', $tail, $lm);
                if ($hasLetter === 1 && isset($lm[1])) {
                    $letter = strtoupper((string) $lm[1]);
                    $tailAfterSuffix = (string) preg_replace('/^\s*[A-Z]\b/i', '', $tail, 1);
                }
            }

            $newBase = 'Tokyo-Hot ' . $id;

            if (is_string($letter) && $letter !== '') {
                $newBase .= ' ' . $letter;
            }

            if ($isBtBtt) {
                $keep = $this->cleanKeptTail($tailAfterSuffix);
                if ($keep !== '') {
                    $newBase .= ' ' . $keep;
                }
            }

            $newBase = $this->sanitizeFileBaseName($newBase);

            return $newBase;
        }

        private function extractPageFromTail(string $tail): ?int
        {
            $pm = [];
            $has = preg_match('/\bp(\d+)\b/i', $tail, $pm);
            if ($has !== 1 || !isset($pm[1])) {
                return null;
            }

            $page = (int) $pm[1];
            if ($page < 1) {
                return null;
            }

            return $page;
        }

        private function removeFirstPageTokenFromTail(string $tail): string
        {
            $tail2 = (string) preg_replace('/\bp\d+\b/i', ' ', $tail, 1);
            return $tail2;
        }

        private function pageToLetters(int $page): string
        {
            if ($page <= 0) {
                return '';
            }

            $n = $page;
            $result = '';

            while ($n > 0) {
                $n -= 1;
                $rem = $n % 26;
                $result = chr(ord('A') + $rem) . $result;
                $n = intdiv($n, 26);
            }

            return $result;
        }

        private function isBtBttPrefix(string $baseName): bool
        {
            return preg_match('/^\[BT-btt\.com\]/i', $baseName) === 1;
        }

        private function cleanKeptTail(string $tail): string
        {
            $tail = trim($tail);

            if ($tail === '') {
                return '';
            }

            $tail = (string) preg_replace('/\s+/u', ' ', $tail);

            return trim($tail);
        }

        private function sanitizeFileBaseName(string $baseName): string
        {
            $baseName = (string) preg_replace('/[<>:"\/\\\\|?*]/u', ' ', $baseName);
            $baseName = (string) preg_replace('/\s+/u', ' ', $baseName);
            $baseName = trim($baseName);
            $baseName = rtrim($baseName, ". \t");

            if ($baseName === '') {
                return 'Tokyo-Hot';
            }

            return $baseName;
        }

        private function ensureUniqueTargetPath(string $dir, string $base, string $ext): string
        {
            $dir = $this->normalizePath($dir);
            $candidate = $dir . DIRECTORY_SEPARATOR . $base . '.' . $ext;

            if (!file_exists($candidate)) {
                return $candidate;
            }

            $suffixNumber = 2;
            while (true) {
                $candidate2 = $dir . DIRECTORY_SEPARATOR . $base . ' (' . $suffixNumber . ').' . $ext;
                if (!file_exists($candidate2)) {
                    return $candidate2;
                }
                $suffixNumber += 1;
            }
        }

        private function toBool($value, bool $default): bool
        {
            if ($value === null) {
                return $default;
            }

            if (is_bool($value)) {
                return $value;
            }

            if (is_int($value)) {
                return $value === 1;
            }

            if (is_string($value)) {
                $v = strtolower(trim($value));
                if ($v === '') {
                    return $default;
                }
                if (in_array($v, ['1', 'true', 'yes', 'y', 'on'], true)) {
                    return true;
                }
                if (in_array($v, ['0', 'false', 'no', 'n', 'off'], true)) {
                    return false;
                }
            }

            return $default;
        }
    }
