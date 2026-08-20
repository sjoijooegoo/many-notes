<?php

declare(strict_types=1);

namespace App\Services;

final class VaultFileName
{
    /** @return array{name: string, extension: string} */
    public static function split(string $fileName): array
    {
        $baseName = pathinfo($fileName, PATHINFO_BASENAME);
        $pathInfo = pathinfo($baseName);

        if ($pathInfo['filename'] === '' && str_starts_with($baseName, '.')) {
            return [
                'name' => $baseName,
                'extension' => '',
            ];
        }

        return [
            'name' => $pathInfo['filename'],
            'extension' => mb_strtolower($pathInfo['extension'] ?? ''),
        ];
    }
}
