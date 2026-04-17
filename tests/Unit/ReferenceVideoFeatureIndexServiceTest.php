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

    public function test_sync_directory_can_limit_processed_files(): void
    {
        $alphaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'alpha.mp4';
        $betaPath = $this->tempDir . DIRECTORY_SEPARATOR . 'beta.mp4';

        file_put_contents($alphaPath, 'alpha-video');
        file_put_contents($betaPath, 'beta-video');

        $alphaPayload = [
            'absolute_path' => $alphaPath,
            'video_name' => 'alpha.mp4',
            'file_name' => 'alpha.mp4',
            'file_size_bytes' => filesize($alphaPath),
            'duration_seconds' => 12.5,
            'file_created_at' => now(),
            'file_modified_at' => now(),
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'frames' => [[
                'capture_order' => 1,
                'label_second' => 10.0,
                'capture_second' => 10.0,
                'dhash_hex' => '0011223344556677',
                'dhash_prefix' => '00',
                'frame_sha1' => str_repeat('a', 40),
                'image_width' => 1280,
                'image_height' => 720,
            ]],
        ];

        $featureExtractionService = Mockery::mock(VideoFeatureExtractionService::class);
        $featureExtractionService->shouldReceive('inspectFile')
            ->once()
            ->with($alphaPath)
            ->andReturn($alphaPayload);
        $featureExtractionService->shouldReceive('cleanupPayload')
            ->once()
            ->with($alphaPayload);

        $service = new ReferenceVideoFeatureIndexService($featureExtractionService);
        $result = $service->syncDirectory($this->tempDir, 1);

        $this->assertSame(1, $result['limit']);
        $this->assertSame(1, $result['total_files']);
        $this->assertSame(1, $result['extracted_count']);
        $this->assertSame(0, $result['failed_count']);
        $this->assertSame($alphaPath, $result['snapshots'][0]['absolute_path']);

        $storedIndex = json_decode((string) file_get_contents($this->tempDir . DIRECTORY_SEPARATOR . 'video-feature-index.json'), true);
        $this->assertSame(1, count((array) ($storedIndex['snapshots'] ?? [])));
        $this->assertSame($alphaPath, $storedIndex['snapshots'][0]['absolute_path']);
    }

    public function test_upsert_payload_snapshot_writes_incremental_json_without_rescanning_directory(): void
    {
        $existingPath = $this->tempDir . DIRECTORY_SEPARATOR . 'existing.mp4';
        $movedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'incoming.mp4';

        file_put_contents($existingPath, 'existing-video');
        file_put_contents($movedPath, 'moved-video');

        $existingSnapshot = [
            'absolute_path' => $existingPath,
            'video_name' => 'existing.mp4',
            'file_name' => 'existing.mp4',
            'file_size_bytes' => filesize($existingPath),
            'duration_seconds' => 12.3,
            'file_created_timestamp' => filectime($existingPath),
            'file_modified_timestamp' => filemtime($existingPath),
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
        ];

        $payload = [
            'absolute_path' => $movedPath,
            'video_name' => 'incoming.mp4',
            'file_name' => 'incoming.mp4',
            'file_size_bytes' => filesize($movedPath),
            'duration_seconds' => 18.8,
            'file_created_at' => filectime($movedPath),
            'file_modified_at' => filemtime($movedPath),
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
        $service = new ReferenceVideoFeatureIndexService($featureExtractionService);
        $result = $service->upsertPayloadSnapshot($this->tempDir, [$existingSnapshot], $payload);

        $this->assertSame(2, $result['total_files']);

        $storedIndex = json_decode((string) file_get_contents($this->tempDir . DIRECTORY_SEPARATOR . 'video-feature-index.json'), true);
        $storedPaths = array_map(
            fn (array $snapshot): string => (string) ($snapshot['absolute_path'] ?? ''),
            (array) ($storedIndex['snapshots'] ?? [])
        );

        $this->assertContains($existingPath, $storedPaths);
        $this->assertContains($movedPath, $storedPaths);
    }
}
