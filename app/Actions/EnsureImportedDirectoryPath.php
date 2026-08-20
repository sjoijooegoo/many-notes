<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Vault;
use App\Models\VaultNode;
use InvalidArgumentException;

final readonly class EnsureImportedDirectoryPath
{
    /**
     * @return array{parent: VaultNode|null, folders: list<VaultNode>}
     */
    public function handle(Vault $vault, ?VaultNode $parent, string $relativePath): array
    {
        if (
            $relativePath === ''
            || mb_strlen($relativePath) > 4096
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, '\\')
        ) {
            throw new InvalidArgumentException('Invalid relative file path.');
        }

        $segments = explode('/', $relativePath);

        foreach ($segments as $segment) {
            $this->validateSegment($segment);
        }

        array_pop($segments);
        $createdFolders = [];

        foreach ($segments as $segment) {
            $folder = $vault->nodes()
                ->where('parent_id', $parent?->id)
                ->where('is_file', false)
                ->where('name', $segment)
                ->first();

            if (!$folder) {
                $folder = app(CreateVaultNode::class)->handle($vault, [
                    'parent_id' => $parent?->id,
                    'is_file' => false,
                    'name' => $segment,
                ]);
                $createdFolders[] = $folder;
            }

            $parent = $folder;
        }

        return [
            'parent' => $parent,
            'folders' => $createdFolders,
        ];
    }

    public function validateSegment(string $segment): void
    {
        if (
            in_array($segment, ['', '.', '..'], true)
            || str_contains($segment, '/')
            || str_contains($segment, '\\')
            || mb_strlen($segment) > 255
            || !mb_check_encoding($segment, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $segment) === 1
        ) {
            throw new InvalidArgumentException('Invalid file or folder name.');
        }
    }
}
