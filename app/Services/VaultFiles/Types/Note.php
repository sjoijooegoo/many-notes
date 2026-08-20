<?php

declare(strict_types=1);

namespace App\Services\VaultFiles\Types;

use App\Services\VaultFiles\Contracts\VaultFileType;
use App\Services\VaultFiles\Traits\HasVaultFileBehavior;

final class Note implements VaultFileType
{
    use HasVaultFileBehavior;

    private static function extensionsList(): array
    {
        return ['md'];
    }

    private static function mimeTypesList(): array
    {
        return [
            'text/plain',
            'text/markdown',
            'text/x-markdown',
        ];
    }
}
