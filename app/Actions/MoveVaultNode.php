<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\InvalidVaultNodeMove;
use App\Models\VaultNode;

final readonly class MoveVaultNode
{
    public function __construct(private UpdateVaultNode $updateVaultNode)
    {
        //
    }

    public function handle(VaultNode $node, ?int $parentId, ?int $expectedRevision = null): VaultNode
    {
        if ($node->id === $parentId) {
            throw new InvalidVaultNodeMove(
                InvalidVaultNodeMove::SELF_PARENT,
                'A node cannot be its own parent.',
            );
        }

        if ($parentId !== null) {
            $parent = $node->vault->nodes()->whereKey($parentId)->first();

            if (!$parent) {
                throw new InvalidVaultNodeMove(
                    InvalidVaultNodeMove::PARENT_NOT_FOUND,
                    'Parent folder not found in this vault.',
                );
            }

            if ($parent->is_file) {
                throw new InvalidVaultNodeMove(
                    InvalidVaultNodeMove::PARENT_IS_FILE,
                    'A file cannot be used as a parent folder.',
                );
            }

            if (!$node->is_file && $node->descendants()->whereKey($parentId)->exists()) {
                throw new InvalidVaultNodeMove(
                    InvalidVaultNodeMove::DESCENDANT_PARENT,
                    'A folder cannot be moved inside one of its descendants.',
                );
            }
        }

        if ($node->parent_id === $parentId) {
            return $node;
        }

        $duplicateExists = $node->vault->nodes()
            ->where('parent_id', $parentId)
            ->where('is_file', $node->is_file)
            ->where('extension', $node->extension)
            ->where('name', $node->name)
            ->whereKeyNot($node->id)
            ->exists();

        if ($duplicateExists) {
            throw new InvalidVaultNodeMove(
                InvalidVaultNodeMove::NAME_CONFLICT,
                'A node with the same name already exists in the destination folder.',
            );
        }

        return $this->updateVaultNode->handle($node, ['parent_id' => $parentId], $expectedRevision);
    }
}
