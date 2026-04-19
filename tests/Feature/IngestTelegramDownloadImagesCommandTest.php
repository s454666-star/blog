<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IngestTelegramDownloadImagesCommandTest extends TestCase
{
    private string $sourceDir;

    private string $targetDir;

    protected function setUp(): void
    {
        parent::setUp();

        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blog_ingest_telegram_images_' . uniqid('', true);
        $this->sourceDir = $baseDir . DIRECTORY_SEPARATOR . 'source';
        $this->targetDir = $baseDir . DIRECTORY_SEPARATOR . 'target';

        File::ensureDirectoryExists($this->sourceDir);
        File::ensureDirectoryExists($this->targetDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->sourceDir));

        parent::tearDown();
    }

    public function test_command_moves_matching_images_and_deletes_duplicate_copy(): void
    {
        $existingTarget = $this->targetDir . DIRECTORY_SEPARATOR . 'existing.jpg';
        $duplicateSource = $this->sourceDir . DIRECTORY_SEPARATOR . 'telegram-image-001.jpg';
        $uniqueSource = $this->sourceDir . DIRECTORY_SEPARATOR . 'telegram-image-002.jpg';
        $ignoredSource = $this->sourceDir . DIRECTORY_SEPARATOR . 'not-telegram-image.jpg';

        $this->createPatternJpeg($existingTarget, 'diagonal');
        copy($existingTarget, $duplicateSource);
        $this->createPatternJpeg($uniqueSource, 'checker');
        $this->createPatternJpeg($ignoredSource, 'frame');

        touch($existingTarget, time() - 30);
        touch($duplicateSource, time() - 20);
        touch($uniqueSource, time() - 10);
        touch($ignoredSource, time() - 5);

        $this->artisan('image:ingest-telegram-downloads', [
            '--source' => $this->sourceDir,
            '--target' => $this->targetDir,
            '--threshold' => 90,
        ])
            ->expectsOutputToContain('已搬移')
            ->expectsOutputToContain('已刪除')
            ->assertExitCode(0);

        $this->assertFileExists($existingTarget);
        $this->assertFileDoesNotExist($duplicateSource);
        $this->assertFileDoesNotExist($uniqueSource);
        $this->assertFileExists($ignoredSource);
        $this->assertFileExists($this->targetDir . DIRECTORY_SEPARATOR . 'telegram-image-002.jpg');
        $this->assertFileDoesNotExist($this->targetDir . DIRECTORY_SEPARATOR . 'telegram-image-001.jpg');

        $targetFiles = collect(File::files($this->targetDir))
            ->map(fn ($file) => $file->getFilename())
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'existing.jpg',
            'telegram-image-002.jpg',
        ], $targetFiles);
    }

    public function test_command_keeps_files_in_place_during_dry_run(): void
    {
        $existingTarget = $this->targetDir . DIRECTORY_SEPARATOR . 'existing.jpg';
        $duplicateSource = $this->sourceDir . DIRECTORY_SEPARATOR . 'telegram-image-001.jpg';

        $this->createPatternJpeg($existingTarget, 'diagonal');
        copy($existingTarget, $duplicateSource);

        $this->artisan('image:ingest-telegram-downloads', [
            '--source' => $this->sourceDir,
            '--target' => $this->targetDir,
            '--threshold' => 90,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('準備搬移')
            ->expectsOutputToContain('dry-run 刪除')
            ->assertExitCode(0);

        $this->assertFileExists($existingTarget);
        $this->assertFileExists($duplicateSource);
        $this->assertFileDoesNotExist($this->targetDir . DIRECTORY_SEPARATOR . 'telegram-image-001.jpg');
    }

    private function createPatternJpeg(string $path, string $pattern): void
    {
        $image = imagecreatetruecolor(120, 90);
        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        $gray = imagecolorallocate($image, 180, 180, 180);

        imagefill($image, 0, 0, $white);

        if ($pattern === 'diagonal') {
            imageline($image, 0, 0, 119, 89, $black);
            imageline($image, 0, 89, 119, 0, $gray);
        } elseif ($pattern === 'checker') {
            for ($y = 0; $y < 90; $y += 18) {
                for ($x = 0; $x < 120; $x += 20) {
                    $color = (($x + $y) / 10) % 2 === 0 ? $black : $gray;
                    imagefilledrectangle($image, $x, $y, $x + 9, $y + 9, $color);
                }
            }
        } else {
            imagerectangle($image, 10, 10, 109, 79, $black);
            imagefilledellipse($image, 60, 45, 30, 30, $gray);
        }

        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }
}
