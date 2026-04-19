<?php

namespace App\Services;

use RuntimeException;

class ImagePerceptualHashService
{
    private const HASH_BITS = 64;

    public function computeDhashHex(string $imagePath): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('需要 GD extension 才能計算圖片 dHash');
        }

        $contents = @file_get_contents($imagePath);
        if (!is_string($contents) || $contents === '') {
            throw new RuntimeException('讀取圖片失敗：' . $imagePath);
        }

        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            throw new RuntimeException('解析圖片失敗：' . $imagePath);
        }

        $small = imagecreatetruecolor(9, 8);
        if ($small === false) {
            imagedestroy($image);
            throw new RuntimeException('建立 dHash 暫存畫布失敗：' . $imagePath);
        }

        imagecopyresampled(
            $small,
            $image,
            0,
            0,
            0,
            0,
            9,
            8,
            imagesx($image),
            imagesy($image)
        );

        imagedestroy($image);

        $bytes = array_fill(0, 8, 0);
        $bitIndex = 0;

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = $this->grayAt($small, $x, $y);
                $right = $this->grayAt($small, $x + 1, $y);
                $bit = $left > $right ? 1 : 0;

                $bytePos = intdiv($bitIndex, 8);
                $bitPos = 7 - ($bitIndex % 8);

                if ($bit === 1) {
                    $bytes[$bytePos] |= 1 << $bitPos;
                }

                $bitIndex++;
            }
        }

        imagedestroy($small);

        $hex = '';
        foreach ($bytes as $byte) {
            $hex .= str_pad(dechex($byte & 255), 2, '0', STR_PAD_LEFT);
        }

        return strtolower($hex);
    }

    public function similarityPercent(string $hashA, string $hashB): float
    {
        $distance = $this->hammingDistanceHex64($hashA, $hashB);
        $similarity = max(0, self::HASH_BITS - $distance) / self::HASH_BITS;

        return round($similarity * 100, 2);
    }

    public function hammingDistanceHex64(string $hashA, string $hashB): int
    {
        $hashA = strtolower(trim($hashA));
        $hashB = strtolower(trim($hashB));

        if (!$this->isValidDhash($hashA) || !$this->isValidDhash($hashB)) {
            return self::HASH_BITS;
        }

        $lookup = [
            '0' => 0, '1' => 1, '2' => 2, '3' => 3,
            '4' => 4, '5' => 5, '6' => 6, '7' => 7,
            '8' => 8, '9' => 9, 'a' => 10, 'b' => 11,
            'c' => 12, 'd' => 13, 'e' => 14, 'f' => 15,
        ];
        $popCount = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];

        $distance = 0;
        for ($i = 0; $i < 16; $i++) {
            $distance += $popCount[$lookup[$hashA[$i]] ^ $lookup[$hashB[$i]]];
        }

        return $distance;
    }

    private function isValidDhash(string $hash): bool
    {
        return preg_match('/\A[a-f0-9]{16}\z/', $hash) === 1;
    }

    private function grayAt($image, int $x, int $y): int
    {
        $rgb = imagecolorat($image, $x, $y);

        $red = ($rgb >> 16) & 255;
        $green = ($rgb >> 8) & 255;
        $blue = $rgb & 255;

        return (int) floor(($red + $green + $blue) / 3);
    }
}
