<?php

namespace Tests\Feature;

use App\Services\MediaDurationProbeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DeleteExactVideoDuplicatesCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blog_delete_exact_duplicates_' . uniqid('', true);
        File::ensureDirectoryExists($this->tempDir);

        $this->app->instance(MediaDurationProbeService::class, new class extends MediaDurationProbeService
        {
            public function probeDurationSeconds(
                string $absolutePath,
                ?string $ffprobeBin = null,
                ?string $ffmpegBin = null,
                int $timeoutSeconds = 60,
                int $ffprobeAttempts = 3
            ): float {
                return 12.345;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_command_deletes_only_exact_binary_duplicates(): void
    {
        $originalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'alpha.mp4';
        $copyPath = $this->tempDir . DIRECTORY_SEPARATOR . 'alpha_copy.mp4';
        $sameSizeDifferentContentPath = $this->tempDir . DIRECTORY_SEPARATOR . 'beta.mp4';

        file_put_contents($originalPath, '0123456789');
        file_put_contents($copyPath, '0123456789');
        file_put_contents($sameSizeDifferentContentPath, 'abcdefghij');

        touch($originalPath, time() - 20);
        touch($copyPath, time() - 10);
        touch($sameSizeDifferentContentPath, time() - 5);

        $this->artisan('video:delete-exact-duplicates', [
            'path' => $this->tempDir,
        ])
            ->expectsOutputToContain('hash=sha256-base64')
            ->expectsOutputToContain('完全相同')
            ->expectsOutputToContain('已刪除')
            ->assertExitCode(0);

        $this->assertFileExists($originalPath);
        $this->assertFileDoesNotExist($copyPath);
        $this->assertFileExists($sameSizeDifferentContentPath);
    }

    public function test_command_keeps_files_in_dry_run_mode(): void
    {
        $originalPath = $this->tempDir . DIRECTORY_SEPARATOR . 'alpha.mp4';
        $copyPath = $this->tempDir . DIRECTORY_SEPARATOR . 'alpha_copy.mp4';

        file_put_contents($originalPath, 'same-binary-video');
        file_put_contents($copyPath, 'same-binary-video');

        touch($originalPath, time() - 20);
        touch($copyPath, time() - 10);

        $this->artisan('video:delete-exact-duplicates', [
            'path' => $this->tempDir,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dry-run 刪除')
            ->assertExitCode(0);

        $this->assertFileExists($originalPath);
        $this->assertFileExists($copyPath);
    }
}
