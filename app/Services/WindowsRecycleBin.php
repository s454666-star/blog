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

        $process = new Process(array_merge([
            'powershell.exe', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $script, '-Paths',
        ], $paths));
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() . PHP_EOL . $process->getOutput()) ?: '丟入垃圾桶失敗。');
        }

        foreach ($paths as $path) {
            if (file_exists($path)) {
                throw new RuntimeException('檔案仍存在，垃圾桶操作未完成。');
            }
        }
    }
}
