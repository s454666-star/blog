<?php

namespace Tests\Feature;

use App\Jobs\TelegramFilestoreDebouncedPromptJob;
use App\Services\TelegramFilestoreBotProfileResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class TelegramFilestoreDebouncedPromptJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('cache.default', 'array');
        config()->set('telegram.filestore_bot_username', 'filestoebot');
        config()->set('telegram.filestore_bot_token', 'filestore-test-token');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('telegram_filestore_files');
        Schema::dropIfExists('telegram_filestore_sessions');

        Schema::create('telegram_filestore_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->string('username')->nullable();
            $table->string('public_token')->nullable();
            $table->string('source_token')->nullable();
            $table->string('status')->default('uploading');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedBigInteger('total_size')->default(0);
            $table->dateTime('close_upload_prompted_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('closed_at')->nullable();
        });

        Schema::create('telegram_filestore_files', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->string('file_id');
            $table->string('file_unique_id');
            $table->string('source_token')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_type')->default('document');
            $table->text('raw_payload')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    public function test_prompt_job_uses_dedicated_queue(): void
    {
        $job = new TelegramFilestoreDebouncedPromptJob(
            4185,
            8491679630,
            TelegramFilestoreBotProfileResolver::FILESTORE
        );

        $this->assertSame(TelegramFilestoreDebouncedPromptJob::QUEUE_NAME, $job->queue);
    }

    public function test_job_retries_when_session_lock_is_busy(): void
    {
        Bus::fake();

        $sessionId = $this->createUploadingSessionWithFiles();
        Cache::put('filestore_debounce_last_file_at_' . $sessionId, now()->subSeconds(6)->getTimestamp(), now()->addMinute());
        Cache::put('filestore_debounce_job_lock_' . $sessionId, 1, now()->addSeconds(10));

        $job = new TelegramFilestoreDebouncedPromptJob($sessionId, 8491679630);
        $job->handle();

        Bus::assertDispatched(TelegramFilestoreDebouncedPromptJob::class, function (TelegramFilestoreDebouncedPromptJob $dispatched) use ($sessionId): bool {
            return $dispatched->queue === TelegramFilestoreDebouncedPromptJob::QUEUE_NAME
                && $this->readPrivateIntProperty($dispatched, 'sessionId') === $sessionId;
        });
    }

    public function test_job_retries_when_prompt_refresh_is_temporarily_deduped(): void
    {
        Bus::fake();

        $sessionId = $this->createUploadingSessionWithFiles(now()->subSecond());
        Cache::put('filestore_debounce_last_file_at_' . $sessionId, now()->subSeconds(6)->getTimestamp(), now()->addMinute());
        Cache::put('filestore_close_upload_prompt_message_id_' . $sessionId, 7024632, now()->addMinutes(30));

        $job = new TelegramFilestoreDebouncedPromptJob($sessionId, 8491679630);
        $job->handle();

        Bus::assertDispatched(TelegramFilestoreDebouncedPromptJob::class, function (TelegramFilestoreDebouncedPromptJob $dispatched) use ($sessionId): bool {
            return $dispatched->queue === TelegramFilestoreDebouncedPromptJob::QUEUE_NAME
                && $this->readPrivateIntProperty($dispatched, 'sessionId') === $sessionId;
        });
    }

    private function createUploadingSessionWithFiles($closeUploadPromptedAt = null): int
    {
        $sessionId = DB::table('telegram_filestore_sessions')->insertGetId([
            'chat_id' => 8491679630,
            'username' => 's4546663',
            'status' => 'uploading',
            'total_files' => 2,
            'total_size' => 3000,
            'created_at' => now()->subMinute(),
            'close_upload_prompted_at' => $closeUploadPromptedAt,
        ]);

        DB::table('telegram_filestore_files')->insert([
            [
                'session_id' => $sessionId,
                'chat_id' => 8491679630,
                'message_id' => 1,
                'file_id' => 'f1',
                'file_unique_id' => 'u1',
                'file_size' => 1000,
                'file_type' => 'photo',
                'created_at' => now()->subSeconds(20),
            ],
            [
                'session_id' => $sessionId,
                'chat_id' => 8491679630,
                'message_id' => 2,
                'file_id' => 'f2',
                'file_unique_id' => 'u2',
                'file_size' => 2000,
                'file_type' => 'photo',
                'created_at' => now(),
            ],
        ]);

        return (int) $sessionId;
    }

    private function readPrivateIntProperty(object $target, string $property): int
    {
        $reflection = new \ReflectionProperty($target, $property);
        $reflection->setAccessible(true);

        return (int) $reflection->getValue($target);
    }
}
