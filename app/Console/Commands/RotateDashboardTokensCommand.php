<?php

namespace App\Console\Commands;

use App\Services\TelegramNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RotateDashboardTokensCommand extends Command
{
    protected $signature = 'line:rotate-dashboard-tokens
        {portfolio=all : all, esun, or yuanta}
        {--env-path= : Override the .env path}
        {--base-url= : Override the dashboard base URL}
        {--no-notify : Rotate without sending LINE push messages}
        {--skip-config-cache : Do not refresh Laravel config cache after writing .env}';

    protected $description = 'Rotate private dashboard URL tokens and optionally push the new links through LINE.';

    public function handle(TelegramNotificationService $telegram): int
    {
        $portfolio = strtolower((string) $this->argument('portfolio'));
        $definitions = $this->selectedDefinitions($portfolio);
        $envPath = (string) ($this->option('env-path') ?: base_path('.env'));
        $envContent = $this->readEnv($envPath);
        $values = $this->envValues($envContent);
        $baseUrl = rtrim((string) ($this->option('base-url') ?: ($values['APP_URL'] ?? config('app.url'))), '/');
        $rotated = [];

        foreach ($definitions as $key => $definition) {
            $token = bin2hex(random_bytes(32));
            $envContent = $this->setDotEnvValue($envContent, $definition['dashboard_token_key'], $token);
            $url = $baseUrl . $definition['dashboard_path'] . '?token=' . $token;

            Storage::disk('local')->put($definition['dashboard_url_path'], "AWS:\n{$url}\n");

            $rotated[$key] = [
                'definition' => $definition,
                'url' => $url,
            ];

            $this->info($definition['label'] . ' dashboard token rotated.');
        }

        $this->writeEnv($envPath, $envContent);

        if (! $this->option('skip-config-cache')) {
            $this->refreshConfigCache();
        }

        if (! $this->option('no-notify')) {
            $values = $this->envValues($envContent);
            $failures = [];

            foreach ($rotated as $item) {
                $definition = $item['definition'];
                $url = $item['url'];
                $message = sprintf($definition['message_template'], $url);

                try {
                    $telegram->sendText($definition['telegram_route'], $message);
                    if ($telegram->isEnabled()) {
                        $this->info($definition['label'] . ' Telegram notification sent.');
                    }
                } catch (Throwable $exception) {
                    report($exception);
                    $failures[] = $definition['label'] . ' Telegram: ' . $exception->getMessage();
                }

                try {
                    $this->sendLinePush($values, $definition, $message);
                    $this->info($definition['label'] . ' LINE notification sent.');
                } catch (Throwable $exception) {
                    report($exception);
                    $failures[] = $definition['label'] . ' LINE: ' . $exception->getMessage();
                }
            }

            if ($failures !== []) {
                throw new RuntimeException('One or more notification deliveries failed: ' . implode(' | ', $failures));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function selectedDefinitions(string $portfolio): array
    {
        $definitions = $this->portfolioDefinitions();

        if ($portfolio === 'all') {
            return $definitions;
        }

        if (! array_key_exists($portfolio, $definitions)) {
            throw new RuntimeException('Portfolio must be all, esun, or yuanta.');
        }

        return [$portfolio => $definitions[$portfolio]];
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function portfolioDefinitions(): array
    {
        return [
            'esun' => [
                'label' => 'E.SUN',
                'telegram_route' => 'esun',
                'dashboard_token_key' => 'ESUN_PORTFOLIO_DASHBOARD_TOKEN',
                'dashboard_path' => '/tw-stock/esun-portfolio',
                'dashboard_url_path' => 'esun/dashboard-url.txt',
                'channel_id_key' => 'LINE_CHANNEL_ID',
                'channel_secret_key' => 'LINE_CHANNEL_SECRET',
                'channel_access_token_key' => 'LINE_CHANNEL_ACCESS_TOKEN',
                'target_id_key' => 'LINE_DASHBOARD_NOTIFY_TARGET_ID',
                'other_target_id_key' => 'YUANTA_LINE_DASHBOARD_NOTIFY_TARGET_ID',
                'message_template' => "E.SUN dashboard URL updated\n%s",
            ],
            'yuanta' => [
                'label' => 'Yuanta',
                'telegram_route' => 'yuanta',
                'dashboard_token_key' => 'YUANTA_PORTFOLIO_DASHBOARD_TOKEN',
                'dashboard_path' => '/tw-stock/yuanta-portfolio',
                'dashboard_url_path' => 'yuanta/dashboard-url.txt',
                'channel_id_key' => 'YUANTA_LINE_CHANNEL_ID',
                'channel_secret_key' => 'YUANTA_LINE_CHANNEL_SECRET',
                'channel_access_token_key' => 'YUANTA_LINE_CHANNEL_ACCESS_TOKEN',
                'target_id_key' => 'YUANTA_LINE_DASHBOARD_NOTIFY_TARGET_ID',
                'other_target_id_key' => 'LINE_DASHBOARD_NOTIFY_TARGET_ID',
                'message_template' => "元大庫存即時看板\n%s",
            ],
        ];
    }

    private function readEnv(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException('.env file not found: ' . $path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Unable to read .env file: ' . $path);
        }

        return $content;
    }

    private function writeEnv(string $path, string $content): void
    {
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write .env file: ' . $path);
        }
    }

    private function setDotEnvValue(string $content, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

        if (preg_match($pattern, $content) === 1) {
            return preg_replace($pattern, $line, $content, 1) ?? $content;
        }

        return rtrim($content, "\r\n") . "\n" . $line . "\n";
    }

    /**
     * @return array<string, string>
     */
    private function envValues(string $content): array
    {
        $values = [];

        foreach (preg_split('/\R/', $content) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = $this->unquoteDotEnvValue(trim($value));
        }

        return $values;
    }

    private function unquoteDotEnvValue(string $value): string
    {
        if (strlen($value) < 2) {
            return $value;
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $definition
     */
    private function sendLinePush(array $values, array $definition, string $message): void
    {
        $targetId = (string) ($values[$definition['target_id_key']] ?? '');
        if ($targetId === '') {
            throw new RuntimeException($definition['target_id_key'] . ' is required.');
        }

        $otherTargetId = (string) ($values[$definition['other_target_id_key']] ?? '');
        if ($otherTargetId !== '' && hash_equals($otherTargetId, $targetId)) {
            throw new RuntimeException('Refusing to send dashboard URL because LINE target ids are identical.');
        }

        $response = Http::withToken($this->lineAccessToken($values, $definition))
            ->withHeaders(['X-Line-Retry-Key' => (string) Str::uuid()])
            ->post('https://api.line.me/v2/bot/message/push', [
                'to' => $targetId,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => $message,
                    ],
                ],
                'notificationDisabled' => false,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'LINE push failed for %s: HTTP %s %s',
                $definition['label'],
                $response->status(),
                $response->body(),
            ));
        }
    }

    /**
     * @param array<string, string> $values
     * @param array<string, string> $definition
     */
    private function lineAccessToken(array $values, array $definition): string
    {
        $channelId = (string) ($values[$definition['channel_id_key']] ?? '');
        $channelSecret = (string) ($values[$definition['channel_secret_key']] ?? '');

        if ($channelId !== '' && $channelSecret !== '') {
            $response = Http::asForm()->post('https://api.line.me/oauth2/v3/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $channelId,
                'client_secret' => $channelSecret,
            ]);

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'Failed to issue LINE access token for %s: HTTP %s %s',
                    $definition['label'],
                    $response->status(),
                    $response->body(),
                ));
            }

            $token = (string) $response->json('access_token', '');
            if ($token === '') {
                throw new RuntimeException('LINE token response did not include access_token for ' . $definition['label'] . '.');
            }

            return $token;
        }

        $configuredToken = (string) ($values[$definition['channel_access_token_key']] ?? '');
        if ($configuredToken === '') {
            throw new RuntimeException($definition['channel_access_token_key'] . ' or channel id/secret is required.');
        }

        return $configuredToken;
    }

    private function refreshConfigCache(): void
    {
        Artisan::call('optimize:clear');
        Artisan::call('config:cache');
    }
}
