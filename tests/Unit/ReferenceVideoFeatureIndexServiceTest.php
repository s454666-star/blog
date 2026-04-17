<?php

namespace Tests\Unit;

use App\Services\ReferenceVideoFeatureIndexService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class ReferenceVideoFeatureIndexServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blog_reference_video_index_' . uniqid('', true);
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_sync_directory_reuses_existing_snapshots_removes_missing_files_and_extracts_new_files(): void
    {
        $keptPath = $this->tempDir . DIRECTORY_SEPARATOR . 'kept.mp4';
        $newPath = $this->tempDir . DIRECTORY_SEPARATOR . 'new.mp4';
        $removedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'removed.mp4';

        file_put_contents($keptPath, 'kept-video');
        file_put_contents($newPath, 'new-video');
        touch($keptPath, time() - 30);
        touch($newPath, time() - 10);

        $indexPath = $this->tempDir . DIRECTORY_SEPARATOR . 'video-feature-index.json';
        file_put_contents($indexPath, json_encode([
            'snapshots' => [
                [
                    'absolute_path' => $keptPath,
                    'video_name' => 'kept.mp4',
                    'file_name' => 'kept.mp4',
                    'file_size_bytes' => filesize($keptPath),
                    'duration_seconds' => 12.3,
                    'file_created_timestamp' => filectime($keptPath),
                    'file_modified_timestamp' => filemtime($keptPath),
                    'screenshot_count' => 1,
                    'feature_version' => 'v1',
                    'capture_rule' => '10s_x4',
                    'frames' => [[
                        'capture_order' => 1,
                        'capture_second' => 10.0,
                        'dhash_hex' => '0011223344556677',
                        'dhash_prefix' => '00',
                        'frame_sha1' => str_repeat('a', 40),
                    ]],
                ],
                [
                    'absolute_path' => $removedPath,
                    'video_name' => 'removed.mp4',
                    'file_name' => 'removed.mp4',
                    'file_size_bytes' => 999,
                    'duration_seconds' => 8.0,
                    'file_created_timestamp' => time() - 20,
                    'file_modified_timestamp' => time() - 20,
                    'screenshot_count' => 1,
                    'feature_version' => 'v1',
                    'capture_rule' => 'lt_10s_at_3s',
                    'frames' => [[
                        'capture_order' => 1,
                        'capture_second' => 3.0,
                        'dhash_hex' => '8899aabbccddeeff',
                        'dhash_prefix' => '88',
                        'frame_sha1' => str_repeat('b', 40),
                    ]],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $newPayload = [
            'absolute_path' => $newPath,
            'video_name' => 'new.mp4',
            'file_name' => 'new.mp4',
            'file_size_bytes' => filesize($newPath),
            'duration_seconds' => 18.8,
            'file_created_at' => now(),
            'file_modified_at' => now(),
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'frames' => [[
                'capture_order' => 1,
                'label_second' => 10.0,
                'capture_second' => 10.0,
                'dhash_hex' => 'ffeeddccbbaa9988',
                'dhash_prefix' => 'ff',
                'frame_sha1' => str_repeat('c', 40),
                'image_width' => 1280,
                'image_height' => 720,
            ]],
        ];

        $featureExtractionService = Mockery::mock(VideoFeatureExtractionService::class);
        $featureExtractionService->shouldReceive('inspectFile')
            ->once()
            ->with($newPath)
            ->andReturn($newPayload);
        $featureExtractionService->shouldReceive('cleanupPayload')
            ->once()
            ->with($newPayload);

        $service = new ReferenceVideoFeatureIndexService($featureExtractionService);
        $result = $service->syncDirectory($this->tempDir);

        $this->assertSame(2, $result['total_files']);
        $this->assertSame(1, $result['reused_count']);
        $this->assertSame(1, $result['extracted_count']);
        $this->assertSame(1, $result['removed_count']);
        $this->assertSame(0, $result['failed_count']);

        $indexedPaths = array_map(
            fn (array $snapshot): string => (string) ($snapshot['absolute_path'] ?? ''),
            $result['snapshots']
        );

        $this->assertContains($keptPath, $indexedPaths);
        $this->assertContains($newPath, $indexedPaths);
        $this->assertNotContains($removedPath, $indexedPaths);

        $storedIndex = json_decode((string) file_get_contents($indexPath), true);
        $storedPaths = array_map(
            fn (array $snapshot): string => (string) ($snapshot['absolute_path'] ?? ''),
            (array) ($storedIndex['snapshots'] ?? [])
        );

        $this->assertContains($keptPath, $storedPaths);
        $this->assertContains($newPath, $storedPaths);
        $this->assertNotContains($removedPath, $storedPaths);
    }
}
