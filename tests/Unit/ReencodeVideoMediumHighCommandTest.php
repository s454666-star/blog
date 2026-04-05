<?php

namespace Tests\Unit;

use App\Console\Commands\ReencodeVideoMediumHighCommand;
use ReflectionMethod;
use Tests\TestCase;

class ReencodeVideoMediumHighCommandTest extends TestCase
{
    public function test_relative_video_name_defaults_to_mp4_when_extension_is_missing(): void
    {
        $command = new ReencodeVideoMediumHighCommand();
        $method = new ReflectionMethod($command, 'resolveTargetPath');
        $method->setAccessible(true);

        $resolved = $method->invoke($command, 'C:\\Users\\User\\Videos\\暫', 'clip-001');

        $this->assertSame('C:\\Users\\User\\Videos\\暫\\clip-001.mp4', $resolved);
    }

    public function test_existing_extension_is_preserved(): void
    {
        $command = new ReencodeVideoMediumHighCommand();
        $method = new ReflectionMethod($command, 'resolveTargetPath');
        $method->setAccessible(true);

        $resolved = $method->invoke($command, 'C:\\Users\\User\\Videos\\暫', 'clip-001.mkv');

        $this->assertSame('C:\\Users\\User\\Videos\\暫\\clip-001.mkv', $resolved);
    }
}
