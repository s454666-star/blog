<?php

namespace Tests\Unit;

use App\Services\MediaDurationProbeService;
use RuntimeException;
use Tests\TestCase;

class MediaDurationProbeServiceTest extends TestCase
{
    public function test_it_retries_ffprobe_before_returning_duration(): void
    {
        $service = new FakeMediaDurationProbeService([
            [
                'successful' => false,
                'output' => '',
                'error_output' => '',
                'exit_code' => -1073741819,
            ],
            [
                'successful' => true,
                'output' => "95.600000\n",
                'error_output' => '',
                'exit_code' => 0,
            ],
        ]);

        $duration = $service->exposedProbeDurationSeconds('C:\\video\\IMG_2055.mp4', 'fake-ffprobe', 'fake-ffmpeg');

        $this->assertSame(95.6, $duration);
        $this->assertCount(2, $service->commands);
        $this->assertSame('fake-ffprobe', $service->commands[0][0]);
        $this->assertSame('fake-ffprobe', $service->commands[1][0]);
    }

    public function test_it_falls_back_to_ffmpeg_when_ffprobe_keeps_failing(): void
    {
        $service = new FakeMediaDurationProbeService([
            [
                'successful' => false,
                'output' => '',
                'error_output' => '',
                'exit_code' => -1073741819,
            ],
            [
                'successful' => false,
                'output' => '',
                'error_output' => '',
                'exit_code' => -1073741819,
            ],
            [
                'successful' => false,
                'output' => '',
                'error_output' => '',
                'exit_code' => -1073741819,
            ],
            [
                'successful' => false,
                'output' => '',
                'error_output' => "Duration: 00:01:35.60, start: 0.000000, bitrate: 740 kb/s\nAt least one output file must be specified",
                'exit_code' => 1,
            ],
        ]);

        $duration = $service->exposedProbeDurationSeconds('C:\\video\\IMG_2055.mp4', 'fake-ffprobe', 'fake-ffmpeg');

        $this->assertSame(95.6, $duration);
        $this->assertCount(4, $service->commands);
        $this->assertSame('fake-ffmpeg', $service->commands[3][0]);
    }

    public function test_it_throws_combined_failure_message_when_probe_and_fallback_both_fail(): void
    {
        $service = new FakeMediaDurationProbeService([
            [
                'successful' => false,
                'output' => '',
                'error_output' => '',
                'exit_code' => -1073741819,
            ],
            [
                'successful' => false,
                'output' => '',
                'error_output' => '',
                'exit_code' => -1073741819,
            ],
            [
                'successful' => false,
                'output' => '',
                'error_output' => '',
                'exit_code' => -1073741819,
            ],
            [
                'successful' => false,
                'output' => '',
                'error_output' => '',
                'exit_code' => 1,
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ffprobe 失敗');
        $this->expectExceptionMessage('ffmpeg fallback 失敗');

        $service->exposedProbeDurationSeconds('C:\\video\\IMG_2055.mp4', 'fake-ffprobe', 'fake-ffmpeg');
    }
}

class FakeMediaDurationProbeService extends MediaDurationProbeService
{
    /** @var array<int, array<string, mixed>> */
    private array $responses;

    /** @var array<int, array<int, string>> */
    public array $commands = [];

    /**
     * @param array<int, array<string, mixed>> $responses
     */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function exposedProbeDurationSeconds(string $absolutePath, string $ffprobeBin, string $ffmpegBin): float
    {
        return $this->probeDurationSeconds($absolutePath, $ffprobeBin, $ffmpegBin);
    }

    protected function runProcess(array $command, int $timeoutSeconds): array
    {
        $this->commands[] = $command;

        if ($this->responses === []) {
            throw new RuntimeException('No fake process response queued.');
        }

        return array_shift($this->responses);
    }
}
