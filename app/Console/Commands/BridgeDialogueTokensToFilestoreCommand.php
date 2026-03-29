<?php

namespace App\Console\Commands;

use App\Models\Dialogue;
use App\Models\TelegramFilestoreSession;
use App\Services\DialogueFilestoreDispatchService;
use App\Services\TelegramCodeTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class BridgeDialogueTokensToFilestoreCommand extends Command
{
    protected $signature = 'filestore:bridge-dialogues-tokens
        {--prefix=mtfxqbot_ : Only process extracted tokens with this prefix}
        {--search= : Optional text needle for narrowing dialogues rows before token extraction}
        {--limit=0 : Max unique tokens to dispatch. 0 means unlimited}
        {--row-chunk=500 : Dialogue rows to read per batch}
        {--port=8000 : Telegram FastAPI service port. Ignored when --base-uri is provided}
        {--base-uri=* : Explicit Telegram API base URI(s). Overrides --port}
        {--max-dialogue-id=0 : Optional max dialogues.id to scan from}
        {--retry-delay=5 : Wait seconds before retrying a token that still has no files}
        {--max-retries=5 : Max extra retries for no-file or not-found token before marking dialogues.is_sync=1}';

    protected $description = 'Scan dialogues newest-first, extract matching source tokens, and bridge missing ones into telegram_filestore via @filestoebot.';

    public function __construct(
        private TelegramCodeTokenService $tokenService,
        private DialogueFilestoreDispatchService $dispatchService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $prefix = trim((string) $this->option('prefix'));
        if ($prefix === '') {
            $this->error('prefix cannot be empty');
            return self::INVALID;
        }

        $normalizedPrefix = Str::lower($prefix);
        $searchNeedle = trim((string) $this->option('search'));
        if ($searchNeedle === '') {
            $searchNeedle = preg_replace('/[_:]+$/', '', $prefix) ?: $prefix;
        }

        $limit = max((int) $this->option('limit'), 0);
        $rowChunk = max((int) $this->option('row-chunk'), 1);
        $maxDialogueId = max((int) $this->option('max-dialogue-id'), 0);
        $retryDelaySeconds = max((int) $this->option('retry-delay'), 0);
        $maxRetries = max((int) $this->option('max-retries'), 0);
        $dispatchOptions = [
            '--port' => max((int) $this->option('port'), 1),
            '--base-uri' => (array) $this->option('base-uri'),
            '--filestore-delete-source-messages' => true,
        ];

        $existingTokens = $this->loadExistingSourceTokenSet($normalizedPrefix);
        $seenTokens = [];
        $stats = [
            'rows_scanned' => 0,
            'matched_tokens' => 0,
            'skipped_existing' => 0,
            'skipped_dup_in_run' => 0,
            'attempted' => 0,
            'synced' => 0,
            'terminal_no_files' => 0,
            'failed' => 0,
            'rows_marked_sync' => 0,
        ];
        $tokenDecisions = [];

        $this->line(sprintf(
            'prefix=%s search=%s limit=%d row_chunk=%d existing_source_tokens=%d retry_delay=%d max_retries=%d',
            $prefix,
            $searchNeedle,
            $limit,
            $rowChunk,
            count($existingTokens),
            $retryDelaySeconds,
            $maxRetries
        ));

        $query = Dialogue::query()
            ->select(['id', 'text'])
            ->where(function ($builder): void {
                $builder->whereNull('is_sync')->orWhere('is_sync', false);
            })
            ->where('text', 'like', '%' . $searchNeedle . '%');

        if ($maxDialogueId > 0) {
            $query->where('id', '<=', $maxDialogueId);
        }

        $cursorId = (int) ($query->max('id') ?? 0);
        if ($cursorId <= 0) {
            $this->info('No matching dialogues rows found.');
            $this->printSummary($stats);
            return self::SUCCESS;
        }

        $limitReached = false;

        while ($cursorId > 0 && !$limitReached) {
            $rows = Dialogue::query()
                ->select(['id', 'text'])
                ->where('id', '<=', $cursorId)
                ->where(function ($builder): void {
                    $builder->whereNull('is_sync')->orWhere('is_sync', false);
                })
                ->where('text', 'like', '%' . $searchNeedle . '%')
                ->orderByDesc('id')
                ->limit($rowChunk)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $minIdInBatch = $cursorId;

            foreach ($rows as $row) {
                $stats['rows_scanned']++;

                $rowId = (int) ($row->id ?? 0);
                if ($rowId > 0 && $rowId < $minIdInBatch) {
                    $minIdInBatch = $rowId;
                }

                $rowTokens = $this->extractMatchingTokens((string) ($row->text ?? ''), $normalizedPrefix);
                if ($rowTokens === []) {
                    continue;
                }

                $canMarkRowAsSynced = true;

                foreach ($rowTokens as $token) {
                    $stats['matched_tokens']++;

                    $normalizedToken = Str::lower($token);

                    if (isset($seenTokens[$normalizedToken])) {
                        $stats['skipped_dup_in_run']++;

                        if (!(($tokenDecisions[$normalizedToken]['mark_is_sync'] ?? false) === true)) {
                            $canMarkRowAsSynced = false;
                        }

                        continue;
                    }
                    $seenTokens[$normalizedToken] = true;

                    if (isset($existingTokens[$normalizedToken])) {
                        $stats['skipped_existing']++;
                        $tokenDecisions[$normalizedToken] = [
                            'mark_is_sync' => true,
                            'reason' => 'existing_session',
                        ];
                        $this->line(sprintf('skip_existing dialogue_id=%d token=%s', $rowId, $token));
                        continue;
                    }

                    if ($limit > 0 && $stats['attempted'] >= $limit) {
                        $limitReached = true;
                        $canMarkRowAsSynced = false;
                        break 2;
                    }

                    $stats['attempted']++;
                    $this->line(str_repeat('-', 100));
                    $this->line(sprintf(
                        '[%d] dialogue_id=%d token=%s',
                        $stats['attempted'],
                        $rowId,
                        $token
                    ));

                    $result = $this->dispatchTokenWithRetry(
                        $token,
                        $dispatchOptions,
                        $retryDelaySeconds,
                        $maxRetries
                    );

                    if (($result['ok'] ?? false) === true) {
                        $existingTokens[$normalizedToken] = true;
                        $stats['synced']++;
                        $tokenDecisions[$normalizedToken] = [
                            'mark_is_sync' => true,
                            'reason' => 'synced',
                        ];
                        $this->info(sprintf(
                            'synced dialogue_id=%d token=%s session_id=%d public_token=%s total_files=%d status=%s exit_code=%d',
                            $rowId,
                            $token,
                            (int) ($result['session_id'] ?? 0),
                            (string) ($result['public_token'] ?? '-'),
                            (int) ($result['total_files'] ?? 0),
                            (string) ($result['session_status'] ?? '-'),
                            (int) ($result['exit_code'] ?? 0)
                        ));
                        continue;
                    }

                    if ($this->shouldMarkDialogueAsSyncedForResult($result)) {
                        $stats['terminal_no_files']++;
                        $tokenDecisions[$normalizedToken] = [
                            'mark_is_sync' => true,
                            'reason' => (string) ($result['status'] ?? 'no_files'),
                        ];
                        $this->warn(sprintf(
                            'terminal_no_files dialogue_id=%d token=%s exit_code=%d status=%s summary=%s',
                            $rowId,
                            $token,
                            (int) ($result['exit_code'] ?? 1),
                            (string) ($result['status'] ?? '-'),
                            trim((string) ($result['summary'] ?? 'dispatch finished without files'))
                        ));
                        continue;
                    }

                    $tokenDecisions[$normalizedToken] = [
                        'mark_is_sync' => false,
                        'reason' => (string) ($result['status'] ?? 'failed'),
                    ];
                    $canMarkRowAsSynced = false;
                    $stats['failed']++;
                    $this->warn(sprintf(
                        'failed dialogue_id=%d token=%s exit_code=%d summary=%s',
                        $rowId,
                        $token,
                        (int) ($result['exit_code'] ?? 1),
                        trim((string) ($result['summary'] ?? 'dispatch failed'))
                    ));
                }

                if ($canMarkRowAsSynced && $this->markDialogueAsSynced($rowId)) {
                    $stats['rows_marked_sync']++;
                    $this->line(sprintf('marked_is_sync dialogue_id=%d', $rowId));
                }
            }

            $cursorId = $minIdInBatch - 1;
        }

        if ($limitReached) {
            $this->line('limit_reached=1');
        }

        $this->printSummary($stats);

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function extractMatchingTokens(string $text, string $normalizedPrefix): array
    {
        $tokens = $this->tokenService->extractTokens($text);
        if ($tokens === []) {
            return [];
        }

        return array_values(array_filter($tokens, static function (string $token) use ($normalizedPrefix): bool {
            return Str::startsWith(Str::lower($token), $normalizedPrefix);
        }));
    }

    /**
     * @return array<string, true>
     */
    private function loadExistingSourceTokenSet(string $normalizedPrefix): array
    {
        $existing = [];

        foreach (
            TelegramFilestoreSession::query()
                ->where('status', 'closed')
                ->whereNotNull('source_token')
                ->pluck('source_token') as $token
        ) {
            $value = trim((string) $token);
            if ($value === '') {
                continue;
            }

            $normalized = Str::lower($value);
            if (!Str::startsWith($normalized, $normalizedPrefix)) {
                continue;
            }

            $existing[$normalized] = true;
        }

        return $existing;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function printSummary(array $stats): void
    {
        $this->line(str_repeat('=', 100));
        $this->line('rows_scanned=' . $stats['rows_scanned']);
        $this->line('matched_tokens=' . $stats['matched_tokens']);
        $this->line('skipped_existing=' . $stats['skipped_existing']);
        $this->line('skipped_dup_in_run=' . $stats['skipped_dup_in_run']);
        $this->line('attempted=' . $stats['attempted']);
        $this->line('synced=' . $stats['synced']);
        $this->line('terminal_no_files=' . $stats['terminal_no_files']);
        $this->line('failed=' . $stats['failed']);
        $this->line('rows_marked_sync=' . $stats['rows_marked_sync']);
    }

    /**
     * @param  array<string, mixed>  $dispatchOptions
     * @return array<string, mixed>
     */
    private function dispatchTokenWithRetry(
        string $token,
        array $dispatchOptions,
        int $retryDelaySeconds,
        int $maxRetries
    ): array {
        $retryCount = 0;

        while (true) {
            $attempt = $retryCount + 1;
            if ($attempt > 1) {
                $this->line('retry_attempt=' . $attempt . '/' . ($maxRetries + 1));
            }

            $result = $this->dispatchService->dispatchToken($token, $dispatchOptions, $this->output);
            $result['attempts'] = $attempt;

            if (
                !$this->shouldRetryNoFileResult($result)
                || $retryCount >= $maxRetries
            ) {
                return $result;
            }

            $retryCount++;
            $this->warn(sprintf(
                'No files yet for token=%s. Wait %d seconds and retry. retry=%d/%d status=%s',
                $token,
                $retryDelaySeconds,
                $retryCount,
                $maxRetries,
                (string) ($result['status'] ?? '-')
            ));

            if ($retryDelaySeconds > 0) {
                sleep($retryDelaySeconds);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function shouldRetryNoFileResult(array $result): bool
    {
        return in_array((string) ($result['status'] ?? ''), ['not_found', 'no_files'], true);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function shouldMarkDialogueAsSyncedForResult(array $result): bool
    {
        return $this->shouldRetryNoFileResult($result)
            || (string) ($result['status'] ?? '') === 'invalid_token';
    }

    private function markDialogueAsSynced(int $dialogueId): bool
    {
        if ($dialogueId <= 0) {
            return false;
        }

        return Dialogue::query()
                ->whereKey($dialogueId)
                ->where(function ($builder): void {
                    $builder->whereNull('is_sync')->orWhere('is_sync', false);
                })
                ->update(['is_sync' => true]) > 0;
    }
}
