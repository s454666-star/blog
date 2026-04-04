<?php

namespace App\Services;

use App\Models\VideoRerunSyncActionLog;
use App\Support\VideoRerunSyncSource;
use Illuminate\Support\Facades\File;

class VideoRerunSyncActionService
{
    public function __construct(
        private readonly VideoRerunDiffService $diffService,
        private readonly VideoRerunEagleClient $eagleClient,
        private readonly VideoRerunSyncService $syncService,
    ) {
    }

    public function apply(string $action, array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter(array_map('strval', $hashes))));
        $processed = 0;
        $logs = [];

        foreach ($hashes as $hash) {
            $group = $this->diffService->findGroup($hash);
            if ($group === null) {
                continue;
            }

            $processed++;
            $logs = array_merge($logs, match ($action) {
                'delete_extras' => $this->deleteExtras($group),
                'fill_missing' => $this->fillMissing($group),
                default => [],
            });
        }

        $run = $this->syncService->scan(false);

        return [
            'processed' => $processed,
            'logs' => $logs,
            'run_id' => $run->id,
        ];
    }

    private function deleteExtras(array $group): array
    {
        $logs = [];
        $hash = $group['hash'];

        foreach ($group['delete_targets'] as $targetSource) {
            $entries = $group['sources'][$targetSource]['entries'] ?? [];
            if ($entries === []) {
                continue;
            }

            $entriesToDelete = ($group['sources'][$targetSource]['count'] ?? 0) > 1
                ? array_slice($entries, 1)
                : $entries;

            foreach ($entriesToDelete as $entry) {
                try {
                    if ($targetSource === VideoRerunSyncSource::RERUN_DISK) {
                        if (!empty($entry['absolute_path']) && File::exists($entry['absolute_path'])) {
                            File::delete($entry['absolute_path']);
                        }
                    }

                    if ($targetSource === VideoRerunSyncSource::EAGLE) {
                        $itemId = (string) ($entry['source_item_id'] ?: ($entry['metadata']['eagle_id'] ?? ''));
                        if ($itemId !== '') {
                            $this->eagleClient->moveToTrash($itemId);
                        }
                    }

                    $logs[] = $this->logAction('delete_extras', $hash, $targetSource, (string) $entry['source_key'], 'success', '已刪除多出來源。', $entry);
                } catch (\Throwable $e) {
                    $logs[] = $this->logAction('delete_extras', $hash, $targetSource, (string) $entry['source_key'], 'failed', $e->getMessage(), $entry);
                }
            }
        }

        return $logs;
    }

    private function fillMissing(array $group): array
    {
        $logs = [];
        $hash = $group['hash'];
        $sourcePath = $this->preferredSourcePath($group);

        if ($sourcePath === null) {
            return [
                $this->logAction('fill_missing', $hash, 'n/a', null, 'skipped', '找不到可用來源檔案，無法補齊。', [
                    'group' => $group['title'],
                ]),
            ];
        }

        foreach ($group['missing_targets'] as $targetSource) {
            try {
                if ($targetSource === VideoRerunSyncSource::RERUN_DISK) {
                    $targetPath = $this->buildRerunTargetPath($group, $sourcePath);
                    File::ensureDirectoryExists(dirname($targetPath));
                    File::copy($sourcePath, $targetPath);

                    $logs[] = $this->logAction('fill_missing', $hash, $targetSource, $targetPath, 'success', '已補齊重跑資料夾。', [
                        'source_path' => $sourcePath,
                        'target_path' => $targetPath,
                    ]);
                }

                if ($targetSource === VideoRerunSyncSource::EAGLE) {
                    $this->eagleClient->addFromPath($sourcePath, $group['preferred_name']);

                    $logs[] = $this->logAction('fill_missing', $hash, $targetSource, $group['preferred_name'], 'success', '已補齊 Eagle。', [
                        'source_path' => $sourcePath,
                        'name' => $group['preferred_name'],
                    ]);
                }
            } catch (\Throwable $e) {
                $logs[] = $this->logAction('fill_missing', $hash, $targetSource, null, 'failed', $e->getMessage(), [
                    'source_path' => $sourcePath,
                ]);
            }
        }

        return $logs;
    }

    private function preferredSourcePath(array $group): ?string
    {
        foreach ([VideoRerunSyncSource::DB, VideoRerunSyncSource::RERUN_DISK, VideoRerunSyncSource::EAGLE] as $source) {
            foreach ($group['sources'][$source]['entries'] ?? [] as $entry) {
                $path = (string) ($entry['absolute_path'] ?? '');
                if ($path !== '' && File::exists($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function buildRerunTargetPath(array $group, string $sourcePath): string
    {
        $root = rtrim((string) config('video_rerun_sync.rerun_root', ''), '/\\');
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION) ?: ($group['preferred_extension'] ?? 'mp4'));
        $basename = $group['preferred_name'];
        $candidate = $root . DIRECTORY_SEPARATOR . $basename . '.' . $extension;

        if (!File::exists($candidate)) {
            return $candidate;
        }

        $existingHash = sha1_file($candidate) ?: null;
        if ($existingHash === $group['hash']) {
            return $candidate;
        }

        return $root . DIRECTORY_SEPARATOR . $basename . '__sync_' . substr($group['hash'], 0, 8) . '.' . $extension;
    }

    private function logAction(
        string $action,
        ?string $hash,
        string $targetSource,
        ?string $targetKey,
        string $status,
        string $message,
        array $payload,
    ): array {
        VideoRerunSyncActionLog::create([
            'action_type' => $action,
            'content_sha1' => $hash,
            'target_source' => $targetSource,
            'target_key' => $targetKey,
            'status' => $status,
            'message' => $message,
            'payload_json' => $payload,
        ]);

        return [
            'action' => $action,
            'hash' => $hash,
            'target_source' => $targetSource,
            'target_key' => $targetKey,
            'status' => $status,
            'message' => $message,
        ];
    }
}
