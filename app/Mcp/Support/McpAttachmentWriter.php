<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Actions\CreateVaultNode;
use App\Actions\EnsureImportedDirectoryPath;
use App\Exceptions\McpAttachmentException;
use App\Models\Vault;
use App\Models\VaultNode;
use App\Services\EditableTextFile;
use App\Services\VaultFile;
use App\Services\VaultFileName;
use finfo;
use InvalidArgumentException;

final readonly class McpAttachmentWriter
{
    public function __construct(
        private CreateVaultNode $createVaultNode,
        private EnsureImportedDirectoryPath $ensureImportedDirectoryPath,
    ) {
        //
    }

    /** @return array{attachment: VaultNode, bytes: int, sha256: string} */
    public function store(Vault $vault, ?int $parentId, string $fileName, string $content): array
    {
        $this->validateFileName($fileName);

        $bytes = mb_strlen($content, '8bit');

        if ($bytes === 0 || $bytes > $this->maxBytes()) {
            throw new McpAttachmentException(
                'invalid_attachment_size',
                'Attachment exceeds the configured maximum size.',
                ['max_bytes' => $this->maxBytes()],
            );
        }

        $mimeType = new finfo(FILEINFO_MIME_TYPE)->buffer($content);
        $mimeType = is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
        $fileNameParts = VaultFileName::split($fileName);
        $editableContent = VaultFile::validate($fileNameParts['extension'], $mimeType)
            && $fileNameParts['extension'] !== 'md'
                ? null
                : EditableTextFile::detect($content);
        $attachment = $this->createVaultNode->handle($vault, [
            'parent_id' => $parentId,
            'is_file' => true,
            'name' => $fileNameParts['name'],
            'extension' => $fileNameParts['extension'],
            'mime_type' => $mimeType,
            'content' => $content,
            'editable_text' => $editableContent !== null,
        ]);

        return [
            'attachment' => $attachment,
            'bytes' => $bytes,
            'sha256' => hash('sha256', $content),
        ];
    }

    public function validateFileName(string $fileName): void
    {
        try {
            $this->ensureImportedDirectoryPath->validateSegment($fileName);
        } catch (InvalidArgumentException) {
            throw new McpAttachmentException(
                'invalid_file_name',
                'File name must be a valid single path segment.',
            );
        }
    }

    public function maxBytes(): int
    {
        $configured = config('many_notes_mcp.max_attachment_bytes');

        return is_int($configured) ? max(1, $configured) : 10 * 1024 * 1024;
    }
}
