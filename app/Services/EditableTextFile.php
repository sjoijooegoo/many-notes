<?php

declare(strict_types=1);

namespace App\Services;

final class EditableTextFile
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    public static function read(string $path): ?string
    {
        $size = filesize($path);

        if ($size === false || $size > self::MAX_BYTES) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        return self::detect($content);
    }

    public static function detect(string $content): ?string
    {
        if (mb_strlen($content, '8bit') > self::MAX_BYTES || !mb_check_encoding($content, 'UTF-8')) {
            return null;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content) === 1) {
            return null;
        }

        return $content;
    }
}
