<?php

namespace Tests\Feature;

use App\Services\PresetCommandRunnerService;
use InvalidArgumentException;
use Mockery;
use Tests\TestCase;

class CommandRunnerControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_command_runner_page_renders_the_workspace(): void
    {
        $response = $this->get('/command-runner');

        $response->assertOk();
        $response->assertSee('Blog 指令工具台');
        $response->assertSee('掃群組 token 並送去指定 port');
        $response->assertSee('掃描並搬移和 video_features 重複的影片');
        $response->assertSee('掃描指定資料夾內彼此重複的影片');
        $response->assertSee('補跑剩餘 token：選 port 執行');
        $response->assertSee('8000 PORT跑');
        $response->assertSee('8001 PORT跑');
        $response->assertSee('資料夾位置');
    }

    public function test_run_endpoint_returns_runner_output(): void
    {
        $expected = [
            'preset' => [
                'id' => 'scan_group_tokens',
                'title' => '掃群組 token 並送去 8000',
                'summary' => '先掃描預設 Telegram 群組中的 token，再把待處理項目派送到 8000 服務。',
            ],
            'success' => true,
            'exit_code' => 0,
            'duration_ms' => 1234,
            'finished_at' => '2026-04-03 19:40:00',
            'output' => "sample output\n",
        ];

        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with('scan_group_tokens', [])
            ->andReturn($expected);

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->postJson(route('command-runner.run'), [
            'preset' => 'scan_group_tokens',
        ]);

        $response->assertOk();
        $response->assertExactJson($expected);
    }

    public function test_run_endpoint_forwards_custom_path_to_runner(): void
    {
        $expected = [
            'preset' => [
                'id' => 'move_video_duplicates',
                'title' => '掃描並搬移和 video_features 重複的影片',
                'summary' => '把下載資料夾中的影片拿去和既有 video_features 資料庫比對，命中的就搬去疑似重複檔案。',
            ],
            'success' => true,
            'exit_code' => 0,
            'duration_ms' => 5678,
            'finished_at' => '2026-04-03 19:42:00',
            'output' => "custom path output\n",
        ];

        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with('move_video_duplicates', [
                'path' => 'C:\\incoming\\video-folder',
            ])
            ->andReturn($expected);

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->postJson(route('command-runner.run'), [
            'preset' => 'move_video_duplicates',
            'path' => 'C:\\incoming\\video-folder',
        ]);

        $response->assertOk();
        $response->assertExactJson($expected);
    }

    public function test_stream_endpoint_emits_chunk_and_complete_events(): void
    {
        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('getPreset')
            ->once()
            ->with('scan_group_tokens_port_8000', [])
            ->andReturn([
                'id' => 'scan_group_tokens_port_8000',
                'title' => '掃群組 token：跑 8000',
                'summary' => '先掃描 token，再把待處理項目派送到 8000。',
            ]);
        $mock->shouldReceive('stream')
            ->once()
            ->with(
                'scan_group_tokens_port_8000',
                [],
                Mockery::on(static fn ($callback) => is_callable($callback))
            )
            ->andReturnUsing(function (string $preset, array $input, callable $callback): void {
                $callback('chunk', ['text' => "line 1\n"]);
                $callback('complete', [
                    'preset' => [
                        'id' => $preset,
                        'title' => '掃群組 token：跑 8000',
                        'summary' => '先掃描 token，再把待處理項目派送到 8000。',
                    ],
                    'success' => true,
                    'exit_code' => 0,
                    'duration_ms' => 321,
                    'finished_at' => '2026-04-03 20:10:00',
                ]);
            });

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->post(route('command-runner.stream'), [
            'preset' => 'scan_group_tokens_port_8000',
        ]);

        $response->assertOk();
        $response->assertHeader('X-Accel-Buffering', 'no');
        $content = $response->streamedContent();

        $this->assertStringContainsString('event: chunk', $content);
        $this->assertStringContainsString('line 1', $content);
        $this->assertStringContainsString('event: complete', $content);
        $this->assertStringContainsString('"success":true', $content);
    }

    public function test_run_endpoint_rejects_unknown_presets(): void
    {
        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with('not-real', [])
            ->andThrow(new InvalidArgumentException('Unknown preset command.'));

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->postJson(route('command-runner.run'), [
            'preset' => 'not-real',
        ]);

        $response->assertStatus(422);
        $response->assertExactJson([
            'message' => 'Unknown preset command.',
        ]);
    }
}
