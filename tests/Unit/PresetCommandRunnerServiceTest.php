<?php

namespace Tests\Unit;

use App\Services\PresetCommandRunnerService;
use Tests\TestCase;

class PresetCommandRunnerServiceTest extends TestCase
{
    public function test_it_builds_reencode_preset_with_optional_blank_video(): void
    {
        $service = new PresetCommandRunnerService();

        $preset = $service->getPreset('reencode_video_medium_high', [
            'path' => 'C:\\Users\\User\\Videos\\暫',
            'video' => '',
        ]);

        $this->assertSame('reencode_video_medium_high', $preset['id']);
        $this->assertCount(2, $preset['inputs']);
        $this->assertSame('C:\\Users\\User\\Videos\\暫', $preset['inputs'][0]['value']);
        $this->assertSame('', $preset['inputs'][1]['value']);
        $this->assertFalse($preset['inputs'][1]['required']);
        $this->assertSame([
            'C:\\php\\php.exe',
            'artisan',
            'video:reencode-medium-high',
            'C:\\Users\\User\\Videos\\暫',
        ], $preset['steps'][0]['command']);
        $this->assertStringContainsString('video:reencode-medium-high "C:\\Users\\User\\Videos\\暫" ""', $preset['command_preview']);
    }

    public function test_it_builds_reencode_preset_with_single_video_argument(): void
    {
        $service = new PresetCommandRunnerService();

        $preset = $service->getPreset('reencode_video_medium_high', [
            'path' => 'C:\\Users\\User\\Videos\\暫',
            'video' => 'clip-001.mp4',
        ]);

        $this->assertSame([
            'C:\\php\\php.exe',
            'artisan',
            'video:reencode-medium-high',
            'C:\\Users\\User\\Videos\\暫',
            'clip-001.mp4',
        ], $preset['steps'][0]['command']);
        $this->assertStringContainsString('video:reencode-medium-high C:\\Users\\User\\Videos\\暫 clip-001.mp4', $preset['steps'][0]['display']);
        $this->assertStringContainsString('clip-001.mp4', $preset['command_preview']);
    }
}
