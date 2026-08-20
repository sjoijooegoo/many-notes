<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateVaultNode;
use App\Actions\EnsureImportedDirectoryPath;
use App\Mcp\Support\McpVaultAccess;
use App\Services\EditableTextFile;
use App\Services\VaultFile;
use App\Services\VaultFileName;
use finfo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Override;

#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class UploadAttachmentTool extends ManyNotesTool
{
    protected string $name = 'upload_attachment';

    protected string $description =
        'Upload one Base64-encoded file to an authorized Many Notes vault. ' .
        'The server detects its MIME type and returns a safe Markdown reference. ' .
        'Use create_document for Markdown authored as text; this tool is additive and never edits or ' .
        'deletes a document.';

    public function __construct(
        McpVaultAccess $access,
        private readonly CreateVaultNode $createVaultNode,
        private readonly EnsureImportedDirectoryPath $ensureImportedDirectoryPath,
    ) {
        parent::__construct($access);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        $maxBase64Length = $this->maxBase64Length();

        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'parent_id' => $schema->integer()->description('Destination folder ID. Omit for the vault root.'),
            'file_name' => $schema->string()
                ->min(1)
                ->max(255)
                ->description('File name including its extension, without directory segments.')
                ->required(),
            'content_base64' => $schema->string()
                ->min(1)
                ->max($maxBase64Length)
                ->description('Raw Base64 file content, without a data-URL prefix. Maximum decoded size: 10 MiB.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $maxBytes = $this->maxBytes();
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'file_name' => ['required', 'string', 'min:1', 'max:255'],
            'content_base64' => ['required', 'string', 'min:1', 'max:' . $this->maxBase64Length()],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::WRITE);

        if ($vault instanceof Response) {
            return $vault;
        }

        $parentId = $this->nullableIntValue($data, 'parent_id');
        $parent = $this->parentFolder($vault, $parentId);

        if ($parent instanceof Response) {
            return $parent;
        }

        $fileName = $this->stringValue($data, 'file_name');

        try {
            $this->ensureImportedDirectoryPath->validateSegment($fileName);
        } catch (InvalidArgumentException) {
            return $this->structuredError(
                'invalid_file_name',
                'File name must be a valid single path segment.',
            );
        }

        $encoded = $this->stringValue($data, 'content_base64');

        if (preg_match('/\s/', $encoded) === 1) {
            return $this->structuredError(
                'invalid_base64',
                'content_base64 must contain raw Base64 without whitespace or a data-URL prefix.',
            );
        }

        $content = base64_decode($encoded, true);

        if ($content === false) {
            return $this->structuredError('invalid_base64', 'content_base64 is not valid Base64.');
        }

        $bytes = mb_strlen($content, '8bit');

        if ($bytes === 0 || $bytes > $maxBytes) {
            return $this->structuredError(
                'invalid_attachment_size',
                'Attachment exceeds the configured maximum decoded size.',
                ['max_bytes' => $maxBytes],
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

        return Response::structured([
            'attachment' => $this->access->nodeData($attachment),
            'bytes' => $bytes,
            'sha256' => hash('sha256', $content),
        ]);
    }

    private function maxBytes(): int
    {
        $configured = config('many_notes_mcp.max_attachment_bytes');

        return is_int($configured) ? max(1, $configured) : 10 * 1024 * 1024;
    }

    private function maxBase64Length(): int
    {
        return 4 * (int) ceil($this->maxBytes() / 3);
    }
}
