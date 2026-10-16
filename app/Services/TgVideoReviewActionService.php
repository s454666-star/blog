<?php

namespace App\Services;

use App\Contracts\RecycleBin;
use App\Models\TgVideoReview;
use RuntimeException;
use Throwable;

class TgVideoReviewActionService
{
    public function __construct(private readonly RecycleBin $recycleBin)
    {
    }

    /** @return array{ok: bool, message: string} */
    public function handle(TgVideoReview $record, string $action): array
    {
        try {
            if (!in_array($action, ['delete', 'ok', 'watermark'], true)) {
                throw new RuntimeException('不支援的操作。');
            }

            if ($action === 'delete') {
                return $this->deleteMany([$record])[$record->id];
            }

            [$videoPath, $imagePath, $root] = $this->validatedPaths($record);

            if (!is_file($videoPath)) {
                throw new RuntimeException('影片已不存在，未刪除資料列。');
            }
            $subdirectory = $action === 'ok'
                ? (string) config('tg_video_review.ok_subdirectory')
                : (string) config('tg_video_review.watermark_subdirectory');
            $this->moveVideoAndDeleteImage($videoPath, $imagePath, $root, $subdirectory);

            $record->delete();

            return ['ok' => true, 'message' => '處理完成。'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->utf8Message($e->getMessage())];
        }
    }

    /**
     * Sends every record's files to the recycle bin in one batch, then deletes only the rows whose files are gone.
     *
     * @param iterable<TgVideoReview> $records
     * @return array<int, array{ok: bool, message: string}> keyed by record id
     */
    public function deleteMany(iterable $records): array
    {
        $results = [];
        $pending = [];

        foreach ($records as $record) {
            try {
                if (!file_exists((string) $record->video_path) && !file_exists((string) $record->image_path)) {
                    $results[$record->id] = ['ok' => true, 'message' => '已清除不存在檔案的資料列。'];
                    continue;
                }

                [$videoPath, $imagePath] = $this->validatedPaths($record);
                $pending[$record->id] = array_values(array_filter(
                    [$videoPath, $imagePath],
                    fn (string $path): bool => is_file($path)
                ));
            } catch (Throwable $e) {
                $results[$record->id] = ['ok' => false, 'message' => $this->utf8Message($e->getMessage())];
            }
        }

        $recycleError = null;
        $paths = array_merge(...array_values($pending));
        if ($paths !== []) {
            try {
                $this->recycleBin->move($paths);
            } catch (Throwable $e) {
                $recycleError = $this->utf8Message($e->getMessage());
            }
        }

        foreach ($pending as $id => $recordPaths) {
            $remaining = array_values(array_filter($recordPaths, 'file_exists'));
            $results[$id] = $remaining === []
                ? ['ok' => true, 'message' => '處理完成。']
                : ['ok' => false, 'message' => $this->recycleFailureMessage($remaining, $recycleError)];
        }

        $deletableIds = array_keys(array_filter($results, fn (array $result): bool => $result['ok']));
        foreach (array_chunk($deletableIds, 500) as $chunk) {
            TgVideoReview::query()->whereIn('id', $chunk)->delete();
        }

        return $results;
    }

    /** @param array<int, string> $remaining */
    private function recycleFailureMessage(array $remaining, ?string $recycleError): string
    {
        if ($recycleError === null || $recycleError === '') {
            return '檔案仍存在，垃圾桶操作未完成。';
        }

        $lines = array_filter(
            preg_split('/\R/u', $recycleError) ?: [],
            function (string $line) use ($remaining): bool {
                foreach ($remaining as $path) {
                    if (str_contains($line, $path)) {
                        return true;
                    }
                }

                return false;
            }
        );

        return mb_substr($lines !== [] ? implode(PHP_EOL, $lines) : $recycleError, 0, 500);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function validatedPaths(TgVideoReview $record): array
    {
        $root = realpath((string) config('tg_video_review.root'));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('TG 暫存根目錄不存在。');
        }

        $video = trim((string) $record->video_path);
        $image = trim((string) $record->image_path);
        if ($video === '' || $image === '') {
            throw new RuntimeException('影片或接觸表路徑為空，未進行變更。');
        }

        $rootKey = $this->pathKey($root);
        if ($this->pathKey(dirname($video)) !== $rootKey || $this->pathKey(dirname($image)) !== $rootKey) {
            throw new RuntimeException('只允許處理 TG 暫存根目錄第一層的檔案。');
        }

        foreach ([$video, $image] as $path) {
            if (!file_exists($path)) {
                continue;
            }

            $resolved = realpath($path);
            if (!is_string($resolved) || !is_file($resolved) || $this->pathKey(dirname($resolved)) !== $rootKey) {
                throw new RuntimeException('檔案實際位置不在 TG 暫存根目錄第一層。');
            }
        }

        return [$video, $image, $root];
    }

    private function moveVideoAndDeleteImage(string $video, string $image, string $root, string $subdirectory): void
    {
        $subdirectory = trim(str_replace(['/', '\\'], '', $subdirectory));
        if ($subdirectory === '') {
            throw new RuntimeException('目標子資料夾設定無效。');
        }

        $destinationDirectory = $root . DIRECTORY_SEPARATOR . $subdirectory;
        if (!is_dir($destinationDirectory) && !mkdir($destinationDirectory, 0775, true) && !is_dir($destinationDirectory)) {
            throw new RuntimeException('無法建立目標資料夾。');
        }

        $destination = $destinationDirectory . DIRECTORY_SEPARATOR . basename($video);
        if (file_exists($destination)) {
            throw new RuntimeException('目標資料夾已有同名影片，未進行任何變更。');
        }
        if (!rename($video, $destination)) {
            throw new RuntimeException('影片搬移失敗。');
        }

        if (is_file($image) && !unlink($image)) {
            @rename($destination, $video);
            throw new RuntimeException('圖片刪除失敗，影片已嘗試搬回原位。');
        }
    }

    private function pathKey(string $path): string
    {
        return mb_strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    private function utf8Message(string $message): string
    {
        if (mb_check_encoding($message, 'UTF-8')) {
            return $message;
        }

        $converted = mb_convert_encoding($message, 'UTF-8', 'CP950');

        return mb_check_encoding($converted, 'UTF-8') ? $converted : '處理失敗，Windows 回傳了無法解碼的訊息。';
    }
}
