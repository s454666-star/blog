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
            [$videoPath, $imagePath, $root] = $this->validatedPaths($record);

            if ($action === 'delete') {
                $this->recycleBin->move([$videoPath, $imagePath]);
            } elseif (in_array($action, ['ok', 'watermark'], true)) {
                $subdirectory = $action === 'ok'
                    ? (string) config('tg_video_review.ok_subdirectory')
                    : (string) config('tg_video_review.watermark_subdirectory');
                $this->moveVideoAndDeleteImage($videoPath, $imagePath, $root, $subdirectory);
            } else {
                throw new RuntimeException('不支援的操作。');
            }

            $record->delete();

            return ['ok' => true, 'message' => '處理完成。'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function validatedPaths(TgVideoReview $record): array
    {
        $root = realpath((string) config('tg_video_review.root'));
        $video = realpath((string) $record->video_path);
        $image = realpath((string) $record->image_path);

        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('TG 暫存根目錄不存在。');
        }
        if (!is_string($video) || !is_file($video) || !is_string($image) || !is_file($image)) {
            throw new RuntimeException('影片或接觸表已不存在，未刪除資料列。');
        }

        $rootKey = $this->pathKey($root);
        if ($this->pathKey(dirname($video)) !== $rootKey || $this->pathKey(dirname($image)) !== $rootKey) {
            throw new RuntimeException('只允許處理 TG 暫存根目錄第一層的檔案。');
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

        if (!unlink($image)) {
            @rename($destination, $video);
            throw new RuntimeException('圖片刪除失敗，影片已嘗試搬回原位。');
        }
    }

    private function pathKey(string $path): string
    {
        return mb_strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }
}
