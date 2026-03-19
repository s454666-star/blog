<?php

namespace Tests\Unit;

use App\Console\Commands\ScanGroupMediaCommand;
use App\Services\TelegramCodeTokenService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ScanGroupMediaCommandTest extends TestCase
{
    public function test_message_has_downloadable_video_accepts_video_mime_type(): void
    {
        $command = $this->makeCommand();

        $message = [
            'media' => [
                '_' => 'MessageMediaDocument',
                'document' => [
                    'mime_type' => 'video/mp4',
                    'attributes' => [],
                ],
            ],
        ];

        $this->assertTrue($this->invokeMessageHasDownloadableVideo($command, $message));
    }

    public function test_message_has_downloadable_video_accepts_video_attribute_without_video_mime_type(): void
    {
        $command = $this->makeCommand();

        $message = [
            'media' => [
                '_' => 'MessageMediaDocument',
                'document' => [
                    'mime_type' => 'application/octet-stream',
                    'attributes' => [
                        ['_' => 'DocumentAttributeFilename', 'file_name' => 'clip.bin'],
                        ['_' => 'DocumentAttributeVideo', 'duration' => 10],
                    ],
                ],
            ],
        ];

        $this->assertTrue($this->invokeMessageHasDownloadableVideo($command, $message));
    }

    public function test_message_has_downloadable_video_rejects_photo_and_generic_document(): void
    {
        $command = $this->makeCommand();

        $photoMessage = [
            'media' => [
                '_' => 'MessageMediaPhoto',
            ],
        ];

        $documentMessage = [
            'media' => [
                '_' => 'MessageMediaDocument',
                'document' => [
                    'mime_type' => 'application/pdf',
                    'attributes' => [
                        ['_' => 'DocumentAttributeFilename', 'file_name' => 'file.pdf'],
                    ],
                ],
            ],
        ];

        $this->assertFalse($this->invokeMessageHasDownloadableVideo($command, $photoMessage));
        $this->assertFalse($this->invokeMessageHasDownloadableVideo($command, $documentMessage));
    }

    private function makeCommand(): ScanGroupMediaCommand
    {
        $tokenService = Mockery::mock(TelegramCodeTokenService::class);

        return new ScanGroupMediaCommand($tokenService);
    }

    private function invokeMessageHasDownloadableVideo(ScanGroupMediaCommand $command, array $message): bool
    {
        $method = new ReflectionMethod($command, 'messageHasDownloadableVideo');
        $method->setAccessible(true);

        return (bool) $method->invoke($command, $message);
    }
}
