<?php

namespace App\Console\Commands;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use App\Services\TelegramFilestoreBotProfileResolver;
use App\Services\TelegramFilestoreCloseUploadPromptService;
use Illuminate\Console\Command;

class RepromptFilestoreUploadingSessionCommand extends Command
{
    protected $signature = 'filestore:reprompt-uploading-session
        {chat_id? : Target chat_id}
        {--session-id= : Target uploading session id}
        {--bot-profile=filestore : Bot profile key}';

    protected $description = 'Re-send the close-upload prompt for an uploading telegram filestore session.';

    public function handle(TelegramFilestoreCloseUploadPromptService $promptService): int
    {
        $session = $this->resolveSession();
        if (!$session) {
            $this->error('Uploading session not found.');
            return self::FAILURE;
        }

        $chatId = (int) ($session->chat_id ?? 0);
        if ($chatId <= 0) {
            $this->error('Session has no chat_id.');
            return self::FAILURE;
        }

        $fileCount = (int) TelegramFilestoreFile::query()
            ->where('session_id', (int) $session->id)
            ->count();

        if ($fileCount <= 0) {
            $this->error('Session has no files.');
            return self::FAILURE;
        }

        $session->close_upload_prompted_at = now();
        $session->save();

        $result = $promptService->sendOrRefreshPrompt(
            (int) $session->id,
            $chatId,
            (string) $this->option('bot-profile') ?: TelegramFilestoreBotProfileResolver::FILESTORE,
            true
        );

        if ($result['action'] === 'failed') {
            $this->error('Prompt send failed.');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Prompt %s. session_id=%d chat_id=%d message_id=%s',
            $result['action'],
            (int) $session->id,
            $chatId,
            $result['message_id'] !== null ? (string) $result['message_id'] : '-'
        ));

        return self::SUCCESS;
    }

    private function resolveSession(): ?TelegramFilestoreSession
    {
        $sessionId = (int) ($this->option('session-id') ?: 0);
        if ($sessionId > 0) {
            return TelegramFilestoreSession::query()
                ->where('id', $sessionId)
                ->where('status', 'uploading')
                ->first();
        }

        $chatId = (int) ($this->argument('chat_id') ?: 0);
        if ($chatId <= 0) {
            return null;
        }

        return TelegramFilestoreSession::query()
            ->where('chat_id', $chatId)
            ->where('status', 'uploading')
            ->orderByDesc('id')
            ->first();
    }
}
