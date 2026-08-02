<?php

namespace App\Services;

use App\Contracts\RecycleBin;
use RuntimeException;
use Symfony\Component\Process\Process;

class WindowsRecycleBin implements RecycleBin
{
    public function move(array $paths): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('垃圾桶操作只允許在 Windows 本機執行。');
        }

        $paths = array_values(array_unique(array_filter($paths, fn (string $path): bool => $path !== '')));
        if ($paths === []) {
            throw new RuntimeException('沒有可丟入垃圾桶的檔案。');
        }

        $script = base_path('scripts/send-to-recycle-bin.ps1');
        if (!is_file($script)) {
            throw new RuntimeException('找不到 Windows 垃圾桶處理腳本。');
        }

        $manifestDirectory = storage_path('app/tg-video-review-recycle');
        if (!is_dir($manifestDirectory)
            && !mkdir($manifestDirectory, 0775, true)
            && !is_dir($manifestDirectory)) {
            throw new RuntimeException('無法建立垃圾桶暫存目錄。');
        }
        $manifestPath = $manifestDirectory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.json';
        $manifest = json_encode($paths, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($manifestPath, $manifest, LOCK_EX) === false) {
            throw new RuntimeException('無法建立垃圾桶檔案清單。');
        }

        try {
            $process = new Process([
                'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
                '-File', $script, '-ManifestPath', $manifestPath,
            ]);
            $process->setTimeout(120);
            $process->run();
        } finally {
            @unlink($manifestPath);
        }

        if (!$process->isSuccessful()) {
            $output = trim($process->getErrorOutput() . PHP_EOL . $process->getOutput());
            throw new RuntimeException($this->utf8($output) ?: '丟入垃圾桶失敗。');
        }

        foreach ($paths as $path) {
            if (file_exists($path)) {
                throw new RuntimeException('檔案仍存在，垃圾桶操作未完成。');
            }
        }
    }

    private function utf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = mb_convert_encoding($value, 'UTF-8', 'CP950');

        return mb_check_encoding($converted, 'UTF-8') ? $converted : 'Windows 垃圾桶操作失敗。';
    }
}
