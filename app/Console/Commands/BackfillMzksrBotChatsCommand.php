<?php

namespace App\Console\Commands;

use App\Services\MzksrBotChatRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class BackfillMzksrBotChatsCommand extends Command
{
    protected $signature = 'telegram:mzksr-backfill-chats
        {--path= : Optional log file path or glob. Defaults to storage/logs/laravel*.log}';

    protected $description = 'Backfill mzksr_bot chat IDs from Laravel logs into mzksr_bot_chats.';

    public function __construct(private MzksrBotChatRecorder $chatRecorder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (!Schema::hasTable('mzksr_bot_chats')) {
            $this->error('Table mzksr_bot_chats does not exist. Run migrations first.');
            return self::FAILURE;
        }

        $files = $this->resolveLogFiles();
        if ($files === []) {
            $this->error('No matching log files found.');
            return self::FAILURE;
        }

        $aggregated = [];
        $matchedLines = 0;

        foreach ($files as $file) {
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                $this->warn('skip_unreadable=' . $file);
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $parsed = $this->parseLogLine($line);
                if ($parsed === null) {
                    continue;
                }

                $event = $this->extractTrackedEvent($parsed['message'], $parsed['context']);
                if ($event === null) {
                    continue;
                }

                $matchedLines++;
                $this->aggregateEvent($aggregated, $event, $parsed['observed_at']);
            }

            fclose($handle);
        }

        if ($aggregated === []) {
            $this->info('No mzksr_bot chat rows were found in the scanned logs.');
            return self::SUCCESS;
        }

        ksort($aggregated);

        foreach ($aggregated as $chatId => $attributes) {
            $this->chatRecorder->recordChat((int) $chatId, $attributes);
        }

        $this->line('files_scanned=' . count($files));
        $this->line('matched_lines=' . $matchedLines);
        $this->line('unique_chat_ids=' . count($aggregated));

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function resolveLogFiles(): array
    {
        $pathOption = trim((string) $this->option('path'));
        if ($pathOption !== '') {
            $matches = glob($pathOption);
            return $matches === false ? [] : array_values(array_filter($matches, 'is_file'));
        }

        $matches = glob(storage_path('logs/laravel*.log'));
        return $matches === false ? [] : array_values(array_filter($matches, 'is_file'));
    }

    /**
     * @return array{message: string, context: array<string, mixed>, observed_at: Carbon}|null
     */
    private function parseLogLine(string $line): ?array
    {
        if (!preg_match('/^\[(?<timestamp>[^\]]+)\]\s+[A-Za-z0-9_.-]+:\s+(?<message>.+)$/', $line, $matches)) {
            return null;
        }

        $payload = trim((string) $matches['message']);
        $jsonOffset = strpos($payload, ' {');
        if ($jsonOffset === false) {
            return null;
        }

        $message = trim(substr($payload, 0, $jsonOffset));
        $json = trim(substr($payload, $jsonOffset + 1));
        if ($message === '' || $json === '') {
            return null;
        }

        try {
            $observedAt = Carbon::parse((string) $matches['timestamp']);
        } catch (\Throwable) {
            return null;
        }

        $context = json_decode($json, true);
        if (!is_array($context)) {
            return null;
        }

        return [
            'message' => $message,
            'context' => $context,
            'observed_at' => $observedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function extractTrackedEvent(string $message, array $context): ?array
    {
        if ($message === 'Telegram webhook incoming') {
            $chatId = (int) ($context['chat_id'] ?? 0);
            if ($chatId === 0) {
                return null;
            }

            return [
                'chat_id' => $chatId,
                'interaction_count' => 1,
            ];
        }

        if ($message !== 'Telegram sendMessage success') {
            return null;
        }

        $fromUsername = (string) data_get($context, 'body.result.from.username', '');
        if ($fromUsername !== MzksrBotChatRecorder::BOT_USERNAME) {
            return null;
        }

        $chat = data_get($context, 'body.result.chat');
        if (!is_array($chat)) {
            return null;
        }

        $chatId = (int) ($chat['id'] ?? 0);
        if ($chatId === 0) {
            return null;
        }

        return [
            'chat_id' => $chatId,
            'chat_type' => $chat['type'] ?? null,
            'username' => $chat['username'] ?? null,
            'first_name' => $chat['first_name'] ?? null,
            'last_name' => $chat['last_name'] ?? null,
            'title' => $chat['title'] ?? null,
            'interaction_count' => 1,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $aggregated
     * @param  array<string, mixed>  $event
     */
    private function aggregateEvent(array &$aggregated, array $event, Carbon $observedAt): void
    {
        $chatId = (int) ($event['chat_id'] ?? 0);
        if ($chatId === 0) {
            return;
        }

        if (!isset($aggregated[$chatId])) {
            $aggregated[$chatId] = [
                'chat_type' => null,
                'username' => null,
                'first_name' => null,
                'last_name' => null,
                'title' => null,
                'interaction_count' => 0,
                'first_seen_at' => $observedAt->copy(),
                'last_seen_at' => $observedAt->copy(),
            ];
        }

        $aggregated[$chatId]['interaction_count'] += max(1, (int) ($event['interaction_count'] ?? 1));

        if ($observedAt->lessThan($aggregated[$chatId]['first_seen_at'])) {
            $aggregated[$chatId]['first_seen_at'] = $observedAt->copy();
        }

        $isLatestEvent = $observedAt->greaterThanOrEqualTo($aggregated[$chatId]['last_seen_at']);
        if ($observedAt->greaterThan($aggregated[$chatId]['last_seen_at'])) {
            $aggregated[$chatId]['last_seen_at'] = $observedAt->copy();
        }

        foreach (['chat_type', 'username', 'first_name', 'last_name', 'title'] as $field) {
            $value = $event[$field] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            if ($aggregated[$chatId][$field] === null || $isLatestEvent) {
                $aggregated[$chatId][$field] = trim($value);
            }
        }
    }
}
