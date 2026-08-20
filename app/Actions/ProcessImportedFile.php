<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Vault;
use App\Models\VaultNode;
use App\Services\EditableTextFile;
use App\Services\VaultFile;
use App\Services\VaultFileName;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;

final readonly class ProcessImportedFile
{
    public function handle(
        Vault $vault,
        ?VaultNode $parent,
        string $fileName,
        string $filePath,
        string $mimeType,
        bool $broadcast = true,
    ): VaultNode {
        $createVaultNode = app(CreateVaultNode::class);
        $getPathFromVaultNode = app(GetPathFromVaultNode::class);

        $attributes = [
            'parent_id' => $parent?->id,
            'is_file' => true,
        ];
        $fileNameParts = VaultFileName::split($fileName);
        $attributes['name'] = $fileNameParts['name'];
        $attributes['extension'] = $fileNameParts['extension'];
        $attributes['mime_type'] = $mimeType;
        $editableContent = VaultFile::validate($attributes['extension'], $mimeType)
            && $attributes['extension'] !== 'md'
            ? null
            : EditableTextFile::read($filePath);
        $attributes['content'] = $editableContent;
        $attributes['editable_text'] = $editableContent !== null;

        $node = $createVaultNode->handle($vault, $attributes, broadcast: $broadcast);

        if ($editableContent === null) {
            $relativePath = $getPathFromVaultNode->handle($node);
            $pathInfo = pathinfo($relativePath);
            $savePath = $pathInfo['dirname'] ?? '';
            $saveName = $pathInfo['basename'];
            Storage::putFileAs($savePath, new File($filePath), $saveName);
        }

        return $node;
    }
}
