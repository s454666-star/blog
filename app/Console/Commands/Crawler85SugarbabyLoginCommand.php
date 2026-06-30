<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class Crawler85SugarbabyLoginCommand extends Command
{
    protected $signature = 'crawler:85sugarbaby-login
                            {--url=https://85sugarbaby.com.tw/home : Target page URL}
                            {--profile= : Chrome user data directory that stores the reusable login session}
                            {--timeout=300 : Seconds to keep the login browser flow alive}';

    protected $description = 'Open a visible browser for refreshing the reusable 85sugarbaby crawler login session.';

    public function handle(): int
    {
        $scriptPath = base_path('scripts/google_login_crawler_probe.mjs');
        if (!is_file($scriptPath)) {
            $this->error('Crawler probe script was not found: ' . $scriptPath);

            return self::FAILURE;
        }

        $baseDir = storage_path('app/google-login-crawler/85sugarbaby-login');
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        $stamp = CarbonImmutable::now()->format('Ymd_His');
        $profilePath = $this->option('profile')
            ? (string) $this->option('profile')
            : storage_path('app/google-login-crawler/chrome-profile');

        $args = [
            $this->nodeBinary(),
            $scriptPath,
            '--url=' . trim((string) $this->option('url')),
            '--email=' . trim((string) config('services.google_login_crawler.email', 's454666123@gmail.com')),
            '--profile=' . $profilePath,
            '--output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_page.html',
            '--text-output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_page.txt',
            '--meta-output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_meta.json',
            '--api-output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_api.json',
            '--timeout=' . max(30, (int) $this->option('timeout')),
            '--probe-85sugarbaby',
            '--active-clicks=1',
            '--click-google',
        ];

        $this->info('Opening visible Chrome for 85sugarbaby login session refresh...');
        $process = new Process($args, base_path(), null, null, max(60, (int) $this->option('timeout') + 30));
        $process->run(function (string $type, string $buffer): void {
            if ($type === Process::ERR) {
                $this->getOutput()->write('<error>' . $buffer . '</error>');

                return;
            }

            $this->getOutput()->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    private function nodeBinary(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';
    }
}
