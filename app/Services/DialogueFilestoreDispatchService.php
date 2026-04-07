<?php

namespace App\Services;

use App\Models\TelegramFilestoreSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class DialogueFilestoreDispatchService
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dispatchToken(string $token, array $options = [], ?OutputInterface $output = null): array
    {
        $token = trim($token);
        if ($token === '') {
            return [
                'ok' => false,
                'status' => 'invalid_token',
                'exit_code' => 1,
                'summary' => 'dispatch skipped: token is empty',
            ];
        }

        $parameters = [
            'tokens' => [$token],
            '--done-action' => 'touch',
            '--include-processed' => true,
        ];

        $baseUris = array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($options['--base-uri'] ?? [])
        ), static fn (string $value): bool => $value !== ''));

        if ($baseUris !== []) {
            $parameters['--base-uri'] = $baseUris;
        } else {
            $parameters['--port'] = max(1, (int) ($options['--port'] ?? 8001));
        }

        if (($options['--filestore-delete-source-messages'] ?? false) === true) {
            $parameters['--filestore-delete-source-messages'] = true;
        }

        $skipWhenTotalFilesExceeds = max(0, (int) ($options['--skip-when-total-files-exceeds'] ?? 0));
        if ($skipWhenTotalFilesExceeds > 0) {
            $parameters['--skip-when-total-files-exceeds'] = $skipWhenTotalFilesExceeds;
        }

        $buffer = new BufferedOutput(
            $output?->getVerbosity() ?? OutputInterface::VERBOSITY_NORMAL,
            $output?->isDecorated() ?? false,
            $output?->getFormatter()
        );

        $exitCode = Artisan::call('tg:dispatch-token-scan-items', $parameters, $buffer);
        $capturedOutput = $buffer->fetch();

        if ($output !== null && $capturedOutput !== '') {
            $output->write($capturedOutput);
            if (!Str::endsWith($capturedOutput, ["\n", "\r"])) {
                $output->writeln('');
            }
        }

        $session = TelegramFilestoreSession::query()
            ->where('source_token', $token)
            ->orderByDesc('id')
            ->first(['id', 'public_token', 'status', 'total_files']);

        if (!$session) {
        $terminal = $this->inferTerminalStatusFromOutput($capturedOutput, $exitCode);

            return [
                'ok' => false,
                'status' => $terminal['status'],
                'exit_code' => $exitCode,
                'summary' => $terminal['summary'],
            ];
        }

        return [
            'ok' => true,
            'status' => 'synced',
            'exit_code' => $exitCode,
            'session_id' => (int) $session->id,
            'public_token' => (string) ($session->public_token ?? ''),
            'session_status' => (string) ($session->status ?? ''),
            'total_files' => (int) ($session->total_files ?? 0),
            'summary' => sprintf(
                'session_id=%d public_token=%s status=%s total_files=%d',
                (int) $session->id,
                (string) ($session->public_token ?? '-'),
                (string) ($session->status ?? '-'),
                (int) ($session->total_files ?? 0)
            ),
        ];
    }

    /**
     * @return array{status:string,summary:string}
     */
    private function inferTerminalStatusFromOutput(string $capturedOutput, int $exitCode = 0): array
    {
        $normalizedOutput = Str::lower($capturedOutput);

        if ($exitCode === 3 || Str::contains($normalizedOutput, 'stopped_early=1')) {
            $lines = preg_split('/\r\n|\r|\n/', trim($capturedOutput)) ?: [];
            $matchingLine = '';

            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line !== '' && Str::contains(Str::lower($line), 'stopping dispatch because')) {
                    $matchingLine = $line;
                }
            }

            return [
                'status' => 'stopped_early',
                'summary' => $matchingLine !== ''
                    ? $matchingLine
                    : 'dispatch stopped early because the current bot run may still be continuing in the background',
            ];
        }

        if (Str::contains($normalizedOutput, 'stored token in dialogues with is_sync=1')) {
            $lines = preg_split('/\r\n|\r|\n/', trim($capturedOutput)) ?: [];
            $matchingLine = '';

            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line !== '' && Str::contains(Str::lower($line), 'stored token in dialogues with is_sync=1')) {
                    $matchingLine = $line;
                }
            }

            return [
                'status' => 'invalid_token',
                'summary' => $matchingLine !== ''
                    ? $matchingLine
                    : 'mtfxq token was stored into dialogues with is_sync=1',
            ];
        }

        if (Str::contains($normalizedOutput, 'bot returned not found')) {
            return [
                'status' => 'not_found',
                'summary' => 'Bot returned not found. Keep token_scan_items row untouched.',
            ];
        }

        foreach ([
            'filestore sync skipped: no files observed',
            'filestore sync skipped: no forwardable files',
            'filestore sync skipped: no messages were forwarded',
        ] as $marker) {
            if (Str::contains($normalizedOutput, $marker)) {
                return [
                    'status' => 'no_files',
                    'summary' => $marker,
                ];
            }
        }

        if (Str::contains($normalizedOutput, 'skipped before pagination because reported total_items=')) {
            $lines = preg_split('/\r\n|\r|\n/', trim($capturedOutput)) ?: [];
            $matchingLine = '';

            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line !== '' && Str::contains(Str::lower($line), 'skipped before pagination because reported total_items=')) {
                    $matchingLine = $line;
                }
            }

            return [
                'status' => 'file_count_limit',
                'summary' => $matchingLine !== ''
                    ? $matchingLine
                    : 'Skipped before pagination because reported total_items exceeded the configured limit.',
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($capturedOutput)) ?: [];
        $lastLine = trim((string) end($lines));

        return [
            'status' => 'missing_after_dispatch',
            'summary' => $lastLine !== ''
                ? $lastLine
                : 'dispatch finished without creating telegram_filestore_sessions row',
        ];
    }
}
