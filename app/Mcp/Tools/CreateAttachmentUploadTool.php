<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\McpAttachmentException;
use App\Mcp\Support\McpAttachmentWriter;
use App\Mcp\Support\McpVaultAccess;
use App\Models\McpAttachmentUpload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Sanctum\PersonalAccessToken;
use Override;

#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class CreateAttachmentUploadTool extends ManyNotesTool
{
    protected string $name = 'create_attachment_upload';

    protected string $description =
        'Create a short-lived, one-time binary upload for an attachment. Send the local file bytes directly ' .
        'to the returned HTTP PUT URL, then call complete_attachment_upload. The bytes never enter MCP tool ' .
        'arguments or model context.';

    public function __construct(
        McpVaultAccess $access,
        private readonly McpAttachmentWriter $attachmentWriter,
    ) {
        parent::__construct($access);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'parent_id' => $schema->integer()->description('Destination folder ID. Omit for the vault root.'),
            'file_name' => $schema->string()
                ->min(1)
                ->max(255)
                ->description('File name including its extension, without directory segments.')
                ->required(),
            'bytes' => $schema->integer()
                ->min(1)
                ->max($this->attachmentWriter->maxBytes())
                ->description('Exact local file size in bytes.')
                ->required(),
            'sha256' => $schema->string()
                ->min(64)
                ->max(64)
                ->description('Optional lowercase or uppercase SHA-256 digest for end-to-end integrity checking.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'file_name' => ['required', 'string', 'min:1', 'max:255'],
            'bytes' => ['required', 'integer', 'min:1', 'max:' . $this->attachmentWriter->maxBytes()],
            'sha256' => ['nullable', 'string', 'regex:/\A[0-9a-fA-F]{64}\z/'],
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
            $this->attachmentWriter->validateFileName($fileName);
        } catch (McpAttachmentException $exception) {
            return $this->structuredError(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
            );
        }

        $accessToken = $this->access->token($request);

        if (!$accessToken instanceof PersonalAccessToken) {
            return $this->structuredError('access_token_required', 'A persistent MCP access token is required.');
        }

        (new McpAttachmentUpload())->pruneAll(100);
        $activeUploads = McpAttachmentUpload::query()
            ->where('personal_access_token_id', $accessToken->id)
            ->where('expires_at', '>', now())
            ->whereIn('status', [
                McpAttachmentUpload::PENDING,
                McpAttachmentUpload::UPLOADING,
                McpAttachmentUpload::UPLOADED,
            ])
            ->count();

        if ($activeUploads >= $this->maxActiveUploads()) {
            return $this->structuredError(
                'too_many_active_uploads',
                'Complete or wait for an existing attachment upload before creating another.',
                ['max_active_uploads' => $this->maxActiveUploads()],
            );
        }

        $uploadToken = Str::random(64);
        $ttlSeconds = $this->ttlSeconds();
        $upload = McpAttachmentUpload::query()->create([
            'personal_access_token_id' => $accessToken->id,
            'vault_id' => $vault->id,
            'parent_id' => $parentId,
            'file_name' => $fileName,
            'expected_bytes' => $this->intValue($data, 'bytes'),
            'expected_sha256' => isset($data['sha256'])
                ? mb_strtolower($this->stringValue($data, 'sha256'))
                : null,
            'token_hash' => hash('sha256', $uploadToken),
            'status' => McpAttachmentUpload::PENDING,
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);
        $uploadUrl = mb_rtrim($this->uploadBaseUrl(), '/') . '/' . $upload->id;

        return Response::structured([
            'upload' => [
                'id' => $upload->id,
                'status' => $upload->status,
                'method' => 'PUT',
                'url' => $uploadUrl,
                'headers' => [
                    'Authorization' => 'Upload ' . $uploadToken,
                    'Content-Type' => 'application/octet-stream',
                    'Content-Length' => (string) $upload->expected_bytes,
                ],
                'bytes' => $upload->expected_bytes,
                'sha256' => $upload->expected_sha256,
                'expires_at' => $upload->expires_at->toIso8601String(),
                'next_tool' => 'complete_attachment_upload',
            ],
            'instructions' => [
                'Stream the exact local file bytes to upload.url with HTTP PUT and the returned headers.',
                'Do not Base64-encode the body.',
                'After HTTP 200, call complete_attachment_upload with upload.id.',
            ],
        ]);
    }

    private function ttlSeconds(): int
    {
        $configured = config('many_notes_mcp.attachment_upload_ttl_seconds');

        return is_int($configured) ? max(60, $configured) : 600;
    }

    private function uploadBaseUrl(): string
    {
        $configured = config('many_notes_mcp.attachment_upload_url');

        return is_string($configured) && $configured !== ''
            ? $configured
            : 'https://mcp.jcrewnote.top/mcp/attachment-uploads';
    }

    private function maxActiveUploads(): int
    {
        $configured = config('many_notes_mcp.max_active_attachment_uploads_per_token');

        return is_int($configured) ? max(1, $configured) : 5;
    }
}
