<?php

namespace Tests\Feature;

use App\Services\DialogueFilestoreDispatchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DialogueFilestoreDispatchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('telegram_filestore_sessions');

        Schema::create('telegram_filestore_sessions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('chat_id')->nullable();
            $table->string('username')->nullable();
            $table->string('encrypt_token')->nullable();
            $table->string('public_token')->nullable();
            $table->string('source_token')->nullable();
            $table->string('status')->default('closed');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedBigInteger('total_size')->default(0);
            $table->unsignedInteger('share_count')->default(0);
            $table->dateTime('last_shared_at')->nullable();
            $table->dateTime('close_upload_prompted_at')->nullable();
            $table->unsignedTinyInteger('is_sending')->default(0);
            $table->dateTime('sending_started_at')->nullable();
            $table->dateTime('sending_finished_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('closed_at')->nullable();
        });
    }

    public function test_dispatch_token_classifies_not_found_output_without_session(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->andReturnUsing(function (string $command, array $parameters, $output): int {
                $this->assertSame('tg:dispatch-token-scan-items', $command);
                $this->assertSame(['mtfxqbot_4V_notfound0004'], $parameters['tokens']);
                $this->assertSame('touch', $parameters['--done-action']);
                $this->assertTrue($parameters['--include-processed']);
                $this->assertSame(8000, $parameters['--port']);

                $output->writeln('Bot returned not found. Keep token_scan_items row untouched.');

                return 0;
            });

        $result = $this->app->make(DialogueFilestoreDispatchService::class)
            ->dispatchToken('mtfxqbot_4V_notfound0004', ['--port' => 8000]);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_found', $result['status']);
        $this->assertSame('Bot returned not found. Keep token_scan_items row untouched.', $result['summary']);
    }

    public function test_dispatch_token_classifies_no_files_output_without_session(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->andReturnUsing(function (string $command, array $parameters, $output): int {
                $this->assertSame('tg:dispatch-token-scan-items', $command);
                $this->assertSame(['mtfxqbot_4V_nofiles0005'], $parameters['tokens']);
                $this->assertSame(8000, $parameters['--port']);

                $output->writeln('filestore sync skipped: no forwardable files');

                return 0;
            });

        $result = $this->app->make(DialogueFilestoreDispatchService::class)
            ->dispatchToken('mtfxqbot_4V_nofiles0005', ['--port' => 8000]);

        $this->assertFalse($result['ok']);
        $this->assertSame('no_files', $result['status']);
        $this->assertSame('filestore sync skipped: no forwardable files', $result['summary']);
    }
}
