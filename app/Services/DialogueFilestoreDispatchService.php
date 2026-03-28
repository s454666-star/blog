<?php

namespace App\Services;

use App\Models\TelegramFilestoreSession;
use Illuminate\Support\Facades\Artisan;
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
            $parameters['--port'] = max(1, (int) ($options['--port'] ?? 8000));
        }

        if (($options['--filestore-delete-source-messages'] ?? false) === true) {
            $parameters['--filestore-delete-source-messages'] = true;
        }

        $exitCode = Artisan::call('tg:dispatch-token-scan-items', $parameters, $output);

        $session = TelegramFilestoreSession::query()
            ->where('source_token', $token)
            ->orderByDesc('id')
            ->first(['id', 'public_token', 'status', 'total_files']);

        if (!$session) {
            return [
                'ok' => false,
                'status' => 'missing_after_dispatch',
                'exit_code' => $exitCode,
                'summary' => 'dispatch finished without creating telegram_filestore_sessions row',
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
}
