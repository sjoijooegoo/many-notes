<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\McpAttachmentException;
use App\Mcp\Support\McpAttachmentWriter;
use App\Mcp\Support\McpVaultAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
        'Compatibility upload for one Base64-encoded file. Prefer create_attachment_upload followed by an ' .
        'HTTP binary PUT and complete_attachment_upload so file bytes do not enter the model tool arguments. ' .
        'The server detects its MIME type and returns a safe Markdown reference. ' .
        'Use create_document for Markdown authored as text; this tool is additive and never edits or ' .
        'deletes a document.';

    public function __construct(
        McpVaultAccess $access,
        private readonly McpAttachmentWriter $attachmentWriter,
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

        try {
            $result = $this->attachmentWriter->store($vault, $parentId, $fileName, $content);
        } catch (McpAttachmentException $exception) {
            return $this->structuredError(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
            );
        }

        return Response::structured([
            'attachment' => $this->access->nodeData($result['attachment']),
            'bytes' => $result['bytes'],
            'sha256' => $result['sha256'],
            'transport' => 'inline_base64',
            'deprecated_transport' => true,
        ]);
    }

    private function maxBytes(): int
    {
        return $this->attachmentWriter->maxBytes();
    }

    private function maxBase64Length(): int
    {
        return 4 * (int) ceil($this->maxBytes() / 3);
    }
}
