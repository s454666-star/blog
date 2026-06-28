<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class GoogleLoginCrawlerTestCommand extends Command
{
    protected $signature = 'crawler:google-login-test
                            {url : Target URL to open}
                            {--email=s454666123@gmail.com : Google account email to prefill when Google asks for an identifier}
                            {--profile= : Chrome user data directory for reusable local login state}
                            {--output= : HTML snapshot output path}
                            {--text-output= : Plain text snapshot output path}
                            {--meta-output= : Metadata JSON output path}
                            {--wait-selector= : CSS selector that means the protected data is ready}
                            {--timeout=300 : Seconds to wait for login/data}
                            {--chrome= : Chrome or Edge executable path}
                            {--click-google : Try to click a visible Google login button on the target page}
                            {--headless : Run without a visible browser window}
                            {--keep-open : Leave Chrome open after the run}
                            {--dry-run : Print the Node command without launching Chrome}';

    protected $description = 'Open a local Chrome session for Google-login crawler testing and save page snapshots.';

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

        $storageDir = storage_path('app/google-login-crawler');
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0777, true);
        }

        $timeoutSeconds = max(5, (int) $this->option('timeout'));

        $profilePath = $this->optionPath('profile')
            ?: $storageDir . DIRECTORY_SEPARATOR . 'chrome-profile';
        $outputPath = $this->optionPath('output')
            ?: $storageDir . DIRECTORY_SEPARATOR . 'latest.html';
        $textOutputPath = $this->optionPath('text-output')
            ?: $storageDir . DIRECTORY_SEPARATOR . 'latest.txt';
        $metaOutputPath = $this->optionPath('meta-output')
            ?: $storageDir . DIRECTORY_SEPARATOR . 'latest-meta.json';

        $args = [
            $this->nodeBinary(),
            $scriptPath,
            '--url=' . $url,
            '--email=' . trim((string) $this->option('email')),
            '--profile=' . $profilePath,
            '--output=' . $outputPath,
            '--text-output=' . $textOutputPath,
            '--meta-output=' . $metaOutputPath,
            '--timeout=' . $timeoutSeconds,
        ];

        $chromePath = $this->optionPath('chrome');
        if ($chromePath !== null) {
            $args[] = '--chrome=' . $chromePath;
        }

        $waitSelector = trim((string) $this->option('wait-selector'));
        if ($waitSelector !== '') {
            $args[] = '--wait-selector=' . $waitSelector;
        }

        foreach (['click-google', 'headless', 'keep-open'] as $flag) {
            if ((bool) $this->option($flag)) {
                $args[] = '--' . $flag;
            }
        }

        if ((bool) $this->option('dry-run')) {
            $this->line(json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Opening local Chrome for Google-login crawler test.');
        $this->line('Profile: ' . $profilePath);
        $this->line('HTML output: ' . $outputPath);
        $this->line('Text output: ' . $textOutputPath);
        $this->line('Meta output: ' . $metaOutputPath);

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
