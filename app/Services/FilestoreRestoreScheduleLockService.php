<?php

namespace App\Services;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Symfony\Component\Process\Process;

class FilestoreRestoreScheduleLockService
{
    public const EVENT_DESCRIPTION = 'blog-filestore-restore-pending-sessions';

    public function __construct(
        private Schedule $schedule,
        private CacheFactory $cache
    ) {
    }

    public function scheduledEvent(): ?Event
    {
        foreach ($this->schedule->events() as $event) {
            if ($event->description === self::EVENT_DESCRIPTION) {
                return $event;
            }
        }

        return null;
    }

    public function isLocked(): bool
    {
        $event = $this->scheduledEvent();
        if ($event === null) {
            return false;
        }

        $lock = $this->cache->store()->lock($event->mutexName(), $this->lockSeconds($event));
        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    public function forceRelease(): bool
    {
        $event = $this->scheduledEvent();
        if ($event === null) {
            return false;
        }

        $this->cache->store()->lock($event->mutexName(), $this->lockSeconds($event))->forceRelease();

        return true;
    }

    /**
     * @return array<int, array{pid:int, command_line:string}>
     */
    public function runningScheduledRestoreProcesses(): array
    {
        return array_values(array_filter(
            $this->listPhpProcesses(),
            fn (array $process): bool => $this->matchesScheduledRestoreCommand($process['command_line'])
        ));
    }

    private function lockSeconds(Event $event): int
    {
        return max((int) $event->expiresAt * 60, 60);
    }

    /**
     * @return array<int, array{pid:int, command_line:string}>
     */
    private function listPhpProcesses(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->listWindowsPhpProcesses();
        }

        return $this->listUnixPhpProcesses();
    }

    /**
     * @return array<int, array{pid:int, command_line:string}>
     */
    private function listWindowsPhpProcesses(): array
    {
        $script = <<<'POWERSHELL'
$items = @(Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" | Select-Object ProcessId, CommandLine)
if ($items.Count -eq 0) {
    Write-Output '[]'
    exit 0
}
$items | ConvertTo-Json -Compress
POWERSHELL;

        $process = new Process([
            'powershell',
            '-NoProfile',
            '-ExecutionPolicy',
            'Bypass',
            '-Command',
            $script,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return $this->normalizeWindowsProcessPayload($process->getOutput());
    }

    /**
     * @return array<int, array{pid:int, command_line:string}>
     */
    private function listUnixPhpProcesses(): array
    {
        $process = new Process(['ps', '-eo', 'pid=', 'command=']);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($process->getOutput())) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ! preg_match('/^(\d+)\s+(.+)$/', $line, $matches)) {
                continue;
            }

            $commandLine = trim($matches[2]);
            if (! str_contains(strtolower($commandLine), 'php')) {
                continue;
            }

            $items[] = [
                'pid' => (int) $matches[1],
                'command_line' => $commandLine,
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{pid:int, command_line:string}>
     */
    private function normalizeWindowsProcessPayload(string $json): array
    {
        $decoded = json_decode(trim($json), true);
        if (! is_array($decoded)) {
            return [];
        }

        if (array_key_exists('ProcessId', $decoded)) {
            $decoded = [$decoded];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $items[] = [
                'pid' => (int) ($item['ProcessId'] ?? 0),
                'command_line' => trim((string) ($item['CommandLine'] ?? '')),
            ];
        }

        return $items;
    }

    private function matchesScheduledRestoreCommand(string $commandLine): bool
    {
        $normalized = $this->normalizeCommandLine($commandLine);
        if ($normalized === '') {
            return false;
        }

        foreach ($this->requiredCommandFragments() as $fragment) {
            if (! str_contains($normalized, $this->normalizeCommandLine($fragment))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function requiredCommandFragments(): array
    {
        return [
            'filestore:restore-to-bot',
            '--all',
            '--pending-session-limit=500',
            '127.0.0.1:8001',
            '--target-bot-username=',
            ltrim((string) config('telegram.backup_restore_bot_username', 'new_files_star_bot'), '@'),
            'telegram-filestore-local-workers',
            'worker.env',
        ];
    }

    private function normalizeCommandLine(string $value): string
    {
        $normalized = strtolower($value);

        return str_replace(['"', "'", '\\'], ['', '', '/'], $normalized);
    }
}
