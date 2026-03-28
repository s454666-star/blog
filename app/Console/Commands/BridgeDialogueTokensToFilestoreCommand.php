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
        {--max-dialogue-id=0 : Optional max dialogues.id to scan from}';

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
            'failed' => 0,
        ];

        $this->line(sprintf(
            'prefix=%s search=%s limit=%d row_chunk=%d existing_source_tokens=%d',
            $prefix,
            $searchNeedle,
            $limit,
            $rowChunk,
            count($existingTokens)
        ));

        $query = Dialogue::query()
            ->select(['id', 'text'])
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

                foreach ($this->extractMatchingTokens((string) ($row->text ?? ''), $normalizedPrefix) as $token) {
                    $stats['matched_tokens']++;

                    $normalizedToken = Str::lower($token);

                    if (isset($seenTokens[$normalizedToken])) {
                        $stats['skipped_dup_in_run']++;
                        continue;
                    }
                    $seenTokens[$normalizedToken] = true;

                    if (isset($existingTokens[$normalizedToken])) {
                        $stats['skipped_existing']++;
                        $this->line(sprintf('skip_existing dialogue_id=%d token=%s', $rowId, $token));
                        continue;
                    }

                    if ($limit > 0 && $stats['attempted'] >= $limit) {
                        $limitReached = true;
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

                    $result = $this->dispatchService->dispatchToken($token, $dispatchOptions, $this->output);
                    $session = TelegramFilestoreSession::query()
                        ->where('source_token', $token)
                        ->orderByDesc('id')
                        ->first(['id', 'public_token', 'status', 'total_files']);

                    if (($result['ok'] ?? false) === true && $session) {
                        $existingTokens[$normalizedToken] = true;
                        $stats['synced']++;
                        $this->info(sprintf(
                            'synced dialogue_id=%d token=%s session_id=%d public_token=%s total_files=%d status=%s exit_code=%d',
                            $rowId,
                            $token,
                            (int) $session->id,
                            (string) ($session->public_token ?? '-'),
                            (int) ($session->total_files ?? 0),
                            (string) ($session->status ?? '-'),
                            (int) ($result['exit_code'] ?? 0)
                        ));
                        continue;
                    }

                    $stats['failed']++;
                    $this->warn(sprintf(
                        'failed dialogue_id=%d token=%s exit_code=%d summary=%s',
                        $rowId,
                        $token,
                        (int) ($result['exit_code'] ?? 1),
                        trim((string) ($result['summary'] ?? 'dispatch failed'))
                    ));
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

        foreach (TelegramFilestoreSession::query()->whereNotNull('source_token')->pluck('source_token') as $token) {
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
        $this->line('failed=' . $stats['failed']);
    }
}
