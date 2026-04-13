<?php

namespace Tests\Feature;

use App\Jobs\TelegramFilestoreDebouncedPromptJob;
use App\Services\TelegramFilestoreBotProfileResolver;
use Tests\TestCase;

class TelegramFilestoreDebouncedPromptJobTest extends TestCase
{
    public function test_prompt_job_uses_dedicated_queue(): void
    {
        config()->set('telegram.filestore_bot_username', 'filestoebot');
        config()->set('telegram.filestore_bot_token', 'filestore-test-token');

        $job = new TelegramFilestoreDebouncedPromptJob(
            4185,
            8491679630,
            TelegramFilestoreBotProfileResolver::FILESTORE
        );

        $this->assertSame(TelegramFilestoreDebouncedPromptJob::QUEUE_NAME, $job->queue);
    }
}
