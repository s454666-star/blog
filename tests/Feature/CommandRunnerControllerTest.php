<?php

namespace Tests\Feature;

use App\Services\PresetCommandRunnerService;
use InvalidArgumentException;
use Mockery;
use Symfony\Component\DomCrawler\Crawler;
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
        $response->assertSee('掃描並刪除和 DB / 暫存索引重複的影片');
        $response->assertSee('掃描指定資料夾內彼此重複的影片');
        $response->assertSee('掃描完全相同影片並直接刪除重複檔');
        $response->assertSee('掃描資料夾重複影片（只掃描不搬移）');
        $response->assertSee('影片重新編碼（中高碼率）');
        $response->assertSee('比對 DB / 重跑 / Eagle 三邊差異');
        $response->assertSee('video:sync-rerun-sources');
        $response->assertSee('補跑剩餘 token：選 port 執行');
        $response->assertSee('8001 PORT跑');
        $response->assertSee('資料夾位置');
        $response->assertSee('影片檔名（留空 = 整個資料夾）');
        $response->assertSee('Z:\\FC2-2026(new)');
        $response->assertSee('C:\\Users\\User\\Videos\\暫');
        $response->assertSee('停止');

        $crawler = new Crawler($response->getContent());

        $this->assertSame($crawler->filter('[data-card]')->count(), $crawler->filter('[data-copy-preview]')->count());
        $this->assertSame($crawler->filter('[data-runtime-row]')->count(), $crawler->filter('[data-copy-output]')->count());
    }

    public function test_run_endpoint_returns_runner_output(): void
    {
        $expected = [
            'preset' => [
                'id' => 'scan_group_tokens',
                'title' => '掃群組 token 並送去 8001',
                'summary' => '先掃描預設 Telegram 群組中的 token，再把待處理項目派送到 8001 服務。',
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
                'title' => '掃描並刪除和 DB / 暫存索引重複的影片',
                'summary' => '把你輸入的資料夾中的影片先拿去和既有 video_features 資料庫比對，再比對 C:\\Users\\User\\Videos\\暫 的特徵索引；命中重複就直接刪除，未命中就搬進暫存參考資料夾。',
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

    public function test_run_endpoint_forwards_exact_duplicate_path_to_runner(): void
    {
        $expected = [
            'preset' => [
                'id' => 'delete_exact_video_duplicates',
                'title' => '掃描完全相同影片並直接刪除重複檔',
                'summary' => '只看檔案本身；同檔案大小且 SHA-256 完全一樣才刪除，不做截圖比對，大小不同就保留。',
            ],
            'success' => true,
            'exit_code' => 0,
            'duration_ms' => 1357,
            'finished_at' => '2026-04-19 15:42:00',
            'output' => "exact duplicate output\n",
        ];

        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with('delete_exact_video_duplicates', [
                'path' => 'C:\\Users\\User\\Videos\\暫',
            ])
            ->andReturn($expected);

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->postJson(route('command-runner.run'), [
            'preset' => 'delete_exact_video_duplicates',
            'path' => 'C:\\Users\\User\\Videos\\暫',
        ]);

        $response->assertOk();
        $response->assertExactJson($expected);
    }

    public function test_run_endpoint_forwards_folder_and_video_to_runner(): void
    {
        $expected = [
            'preset' => [
                'id' => 'reencode_video_medium_high',
                'title' => '影片重新編碼（中高碼率）',
                'summary' => '用 ffmpeg 把單支影片或整個資料夾重新編碼成 H.264/AAC，中高碼率輸出成新的 mp4。',
            ],
            'success' => true,
            'exit_code' => 0,
            'duration_ms' => 2468,
            'finished_at' => '2026-04-05 13:10:00',
            'output' => "reencode output\n",
        ];

        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with('reencode_video_medium_high', [
                'path' => 'C:\\Users\\User\\Videos\\暫',
                'video' => 'clip-001.mp4',
            ])
            ->andReturn($expected);

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->postJson(route('command-runner.run'), [
            'preset' => 'reencode_video_medium_high',
            'path' => 'C:\\Users\\User\\Videos\\暫',
            'video' => 'clip-001.mp4',
        ]);

        $response->assertOk();
        $response->assertExactJson($expected);
    }

    public function test_stream_endpoint_emits_chunk_and_complete_events(): void
    {
        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('getPreset')
            ->once()
            ->with('scan_group_tokens_port_8001', [])
            ->andReturn([
                'id' => 'scan_group_tokens_port_8001',
                'title' => '掃群組 token：跑 8001',
                'summary' => '先掃描 token，再把待處理項目派送到 8001。',
            ]);
        $mock->shouldReceive('stream')
            ->once()
            ->with(
                'scan_group_tokens_port_8001',
                [],
                Mockery::on(static fn ($callback) => is_callable($callback)),
                'runner-token-001'
            )
            ->andReturnUsing(function (string $preset, array $input, callable $callback): void {
                $callback('chunk', ['text' => "line 1\n"]);
                $callback('complete', [
                    'preset' => [
                        'id' => $preset,
                        'title' => '掃群組 token：跑 8001',
                        'summary' => '先掃描 token，再把待處理項目派送到 8001。',
                    ],
                    'success' => true,
                    'exit_code' => 0,
                    'duration_ms' => 321,
                    'finished_at' => '2026-04-03 20:10:00',
                ]);
            });

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->post(route('command-runner.stream'), [
            'preset' => 'scan_group_tokens_port_8001',
            'run_token' => 'runner-token-001',
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

    public function test_run_endpoint_forwards_scan_only_path_to_runner(): void
    {
        $expected = [
            'preset' => [
                'id' => 'scan_video_duplicates',
                'title' => '掃描資料夾重複影片（只掃描不搬移）',
                'summary' => '直接掃你指定的資料夾，找出重複影片並輸出比對結果，不會搬動檔案。',
            ],
            'success' => true,
            'cancelled' => false,
            'exit_code' => 0,
            'duration_ms' => 4321,
            'finished_at' => '2026-04-03 20:18:00',
            'output' => "scan only output\n",
        ];

        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('run')
            ->once()
            ->with('scan_video_duplicates', [
                'path' => 'Z:\\FC2-2026(new)',
            ])
            ->andReturn($expected);

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->postJson(route('command-runner.run'), [
            'preset' => 'scan_video_duplicates',
            'path' => 'Z:\\FC2-2026(new)',
        ]);

        $response->assertOk();
        $response->assertExactJson($expected);
    }

    public function test_stop_endpoint_forwards_run_token_to_runner(): void
    {
        $mock = Mockery::mock(PresetCommandRunnerService::class);
        $mock->shouldReceive('requestStop')
            ->once()
            ->with('runner-token-stop')
            ->andReturn([
                'accepted' => true,
                'message' => '已送出停止要求，正在中止目前指令。',
            ]);

        $this->app->instance(PresetCommandRunnerService::class, $mock);

        $response = $this->postJson(route('command-runner.stop'), [
            'run_token' => 'runner-token-stop',
        ]);

        $response->assertOk();
        $response->assertExactJson([
            'accepted' => true,
            'message' => '已送出停止要求，正在中止目前指令。',
        ]);
    }
}
