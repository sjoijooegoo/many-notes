<?php

declare(strict_types=1);

namespace App\Actions;

use App\Events\VaultNodeUpdatedEvent;
use App\Events\VaultOpenedFileDataUpdatedEvent;
use App\Events\VaultTagListUpdatedEvent;
use App\Exceptions\VaultNodeVersionConflict;
use App\Models\VaultNode;
use App\Services\VaultFiles\Types\Note;
use Illuminate\Support\Facades\Storage;

final readonly class UpdateVaultNode
{
    /**
     * @param array{
     *   parent_id?: int|null,
     *   name?: string,
     *   content?: string|null,
     * } $attributes
     */
    public function handle(VaultNode $node, array $attributes, ?int $expectedRevision = null): VaultNode
    {
        $originalPath = app(GetPathFromVaultNode::class)->handle($node);
        $originalLinkPath = '';

        $isNameAttributeChanged = array_key_exists('name', $attributes)
            && $attributes['name'] !== $node->name;
        $isParentIdAttributeChanged = array_key_exists('parent_id', $attributes)
            && $attributes['parent_id'] !== $node->parent_id;
        $isContentAttributeChanged = array_key_exists('content', $attributes)
            && $attributes['content'] !== $node->content;

        $attributes = array_filter(
            $attributes,
            fn(mixed $value, string $key): bool => $value !== $node->getAttribute($key),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($attributes === []) {
            return $node;
        }

        if ($isNameAttributeChanged || $isParentIdAttributeChanged) {
            $originalLinkPath = $node->fullPath();
        }

        // Save the node using an atomic revision comparison when requested by MCP clients.
        if ($expectedRevision !== null) {
            if ($node->revision !== $expectedRevision) {
                throw new VaultNodeVersionConflict();
            }

            $updated = $node->newQuery()
                ->whereKey($node->id)
                ->where('revision', $expectedRevision)
                ->update([
                    ...$attributes,
                    'revision' => $expectedRevision + 1,
                ]);

            if ($updated !== 1) {
                throw new VaultNodeVersionConflict();
            }

            $node->refresh();

            if ($node->shouldBeSearchable()) {
                $node->searchable();
            }
        } else {
            $node->update($attributes);
            $node->refresh();
        }

        // Save content to disk
        if (
            $isContentAttributeChanged
            && $node->is_file
            && in_array($node->extension, Note::extensions())
        ) {
            Storage::disk('local')->put($originalPath, $attributes['content'] ?? '');
        }

        if ($node->is_file && $node->extension === 'md' && $isContentAttributeChanged) {
            $previousLinks = $this->getLinks($node);
            app(ProcessVaultNodeLinks::class)->handle($node);
            $newLinks = $this->getLinks($node);

            $previousTags = $this->getTags($node);
            app(ProcessVaultNodeTags::class)->handle($node);
            $newTags = $this->getTags($node);

            if ($previousLinks !== $newLinks || $previousTags !== $newTags) {
                // Broadcast events
                broadcast(new VaultOpenedFileDataUpdatedEvent($node));
            }

            if ($previousTags !== $newTags) {
                // Broadcast events
                broadcast(new VaultTagListUpdatedEvent($node->vault));
            }
        }

        if ($isNameAttributeChanged || $isParentIdAttributeChanged) {
            // Rename node on disk
            $path = app(GetPathFromVaultNode::class)->handle($node);
            Storage::disk('local')->move($originalPath, $path);

            // Update all backlinks
            app(UpdateVaultNodeBacklinks::class)->handle($node, $originalLinkPath);
        }

        if ($isNameAttributeChanged) {
            $backlinks = $node->backlinks()->get();

            // Broadcast events
            foreach ($backlinks as $backlink) {
                broadcast(new VaultOpenedFileDataUpdatedEvent($backlink))->toOthers();
            }
        }

        // Broadcast events
        broadcast(new VaultNodeUpdatedEvent($node))->toOthers();

        return $node;
    }

    /** @return array<mixed> */
    private function getLinks(VaultNode $node): array
    {
        return $node
            ->links()
            ->get()
            ->pluck('pivot.destination_id', 'pivot.position')
            ->toArray();
    }

    /** @return array<mixed> */
    private function getTags(VaultNode $node): array
    {
        return $node
            ->tags()
            ->get()
            ->pluck('pivot.tag_id', 'pivot.position')
            ->toArray();
    }
}
