<?php

namespace App\Support;

final class RelativeMediaPath
{
    public static function normalize(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $normalized = str_replace('\\', '/', trim($path));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

        return trim($normalized, '/');
    }

    public static function normalizeDirectory(?string $path): ?string
    {
        $normalized = self::normalize($path);

        return $normalized === '' ? null : $normalized;
    }
}
