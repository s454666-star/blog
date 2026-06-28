<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class SugarbabyCrawlerTestCommand extends Command
{
    protected $signature = 'crawler:85sugarbaby-test
                            {url=https://85sugarbaby.com.tw/home : Target page URL}
                            {--profile= : Chrome profile directory that stores the reusable login session}
                            {--output-dir= : Directory for crawler output files}
                            {--timeout=90 : Seconds to wait for page load and API responses}
                            {--active-clicks=0 : Click/test the active member stream this many times and save only anonymous summary}
                            {--raw-api-probe : Save raw API endpoint responses for local debugging}
                            {--headless : Run without a visible browser window}
                            {--keep-open : Leave Chrome open after the run}
                            {--dry-run : Print the Node command without launching Chrome}';

    protected $description = 'Test the 85sugarbaby crawler with a reusable local Google-login Chrome session.';

    public function handle(): int
    {
        $url = trim((string) $this->argument('url'));
        if (!$this->isHttpUrl($url)) {
            $this->error('The url argument must be a valid http(s) URL.');

            return self::FAILURE;
        }

        $scriptPath = base_path('scripts/google_login_crawler_probe.mjs');
        if (!is_file($scriptPath)) {
            $this->error('Crawler probe script was not found: ' . $scriptPath);

            return self::FAILURE;
        }

        $baseDir = $this->optionPath('output-dir') ?: storage_path('app/google-login-crawler/85sugarbaby');
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        $profilePath = $this->optionPath('profile')
            ?: storage_path('app/google-login-crawler/chrome-profile');
        $timeoutSeconds = max(5, (int) $this->option('timeout'));
        $stamp = now()->format('Ymd_His');

        $args = [
            $this->nodeBinary(),
            $scriptPath,
            '--url=' . $url,
            '--email=s454666123@gmail.com',
            '--profile=' . $profilePath,
            '--output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_page.html',
            '--text-output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_page.txt',
            '--meta-output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_meta.json',
            '--timeout=' . $timeoutSeconds,
        ];

        $activeClicks = max(0, (int) $this->option('active-clicks'));
        if ($activeClicks > 0) {
            $args[] = '--active-clicks=' . $activeClicks;
            $args[] = '--active-summary-output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_active_summary.json';
        }

        if ((bool) $this->option('raw-api-probe')) {
            $args[] = '--probe-85sugarbaby';
            $args[] = '--api-output=' . $baseDir . DIRECTORY_SEPARATOR . $stamp . '_api.json';
        }

        foreach (['headless', 'keep-open'] as $flag) {
            if ((bool) $this->option($flag)) {
                $args[] = '--' . $flag;
            }
        }

        if ((bool) $this->option('dry-run')) {
            $this->line(json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Running 85sugarbaby crawler test with reusable local Chrome session.');
        $this->line('Profile: ' . $profilePath);
        $this->line('Output dir: ' . $baseDir);

        $process = new Process($args, base_path(), null, null, $timeoutSeconds + 90);
        $process->run(function (string $type, string $buffer): void {
            if ($type === Process::ERR) {
                $this->getOutput()->write('<error>' . $buffer . '</error>');

                return;
            }

            $this->getOutput()->write($buffer);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }

    private function optionPath(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value === '' ? null : $value;
    }

    private function nodeBinary(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';
    }
}
