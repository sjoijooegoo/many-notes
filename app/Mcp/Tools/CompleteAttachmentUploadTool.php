<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Exceptions\McpAttachmentException;
use App\Mcp\Support\McpAttachmentWriter;
use App\Mcp\Support\McpVaultAccess;
use App\Models\McpAttachmentUpload;
use App\Models\VaultNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Override;
use RuntimeException;

#[IsDestructive(false)]
#[IsIdempotent(true)]
#[IsOpenWorld(false)]
final class CompleteAttachmentUploadTool extends ManyNotesTool
{
    protected string $name = 'complete_attachment_upload';

    protected string $description =
        'Finalize a binary attachment after create_attachment_upload and its HTTP PUT have succeeded. ' .
        'This rechecks the original MCP token, vault write permission, destination folder, byte count, and ' .
        'SHA-256 digest before creating the attachment and returning a safe Markdown reference.';

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
            'upload_id' => $schema->string()
                ->min(36)
                ->max(36)
                ->description('Upload ID returned by create_attachment_upload.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
        ]);
        $uploadId = $this->stringValue($data, 'upload_id');
        $upload = McpAttachmentUpload::query()->find($uploadId);
        $accessToken = $this->access->token($request);

        if (!$upload || !$accessToken || $upload->personal_access_token_id !== (int) $accessToken->id) {
            return $this->structuredError('upload_not_found', 'Attachment upload was not found.');
        }

        $vault = $this->authorizedVault($request, $upload->vault_id, McpVaultAccess::WRITE);

        if ($vault instanceof Response) {
            return $vault;
        }

        $parent = $this->parentFolder($vault, $upload->parent_id);

        if ($parent instanceof Response) {
            return $parent;
        }

        if ($upload->status === McpAttachmentUpload::COMPLETED) {
            return $this->completedResponse($upload, true);
        }

        if ($upload->expires_at->isPast()) {
            $this->expire($upload);

            return $this->structuredError('upload_expired', 'Attachment upload has expired.');
        }

        if ($upload->status !== McpAttachmentUpload::UPLOADED || $upload->temp_path === null) {
            return $this->structuredError(
                'upload_not_ready',
                'Binary upload is not ready. Complete the HTTP PUT before calling this tool.',
                ['status' => $upload->status],
            );
        }

        try {
            $completed = DB::transaction(function () use ($upload, $vault): McpAttachmentUpload {
                $locked = McpAttachmentUpload::query()->lockForUpdate()->findOrFail($upload->id);

                if ($locked->status === McpAttachmentUpload::COMPLETED) {
                    return $locked;
                }

                if ($locked->status !== McpAttachmentUpload::UPLOADED || $locked->temp_path === null) {
                    throw new RuntimeException('Attachment upload changed before it could be completed.');
                }

                $disk = Storage::disk('local');

                if (!$disk->exists($locked->temp_path)) {
                    throw new RuntimeException('Uploaded attachment bytes are missing.');
                }

                $content = $disk->get($locked->temp_path);

                if (!is_string($content)) {
                    throw new RuntimeException('Uploaded attachment bytes could not be read.');
                }

                $result = $this->attachmentWriter->store(
                    $vault,
                    $locked->parent_id,
                    $locked->file_name,
                    $content,
                );

                if (
                    $result['bytes'] !== $locked->actual_bytes
                    || $result['sha256'] !== $locked->actual_sha256
                ) {
                    throw new RuntimeException('Uploaded attachment bytes failed final integrity validation.');
                }

                $locked->update([
                    'status' => McpAttachmentUpload::COMPLETED,
                    'attachment_id' => $result['attachment']->id,
                    'completed_at' => now(),
                ]);

                return $locked->refresh();
            });
        } catch (McpAttachmentException $exception) {
            return $this->structuredError(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
            );
        } catch (RuntimeException $exception) {
            return $this->structuredError('upload_integrity_error', $exception->getMessage());
        }

        $response = $this->completedResponse($completed, false);

        if ($completed->temp_path !== null) {
            Storage::disk('local')->delete($completed->temp_path);
            $completed->update(['temp_path' => null]);
        }

        return $response;
    }

    private function completedResponse(McpAttachmentUpload $upload, bool $idempotent): ResponseFactory
    {
        $attachment = $upload->attachment_id === null
            ? null
            : VaultNode::query()
                ->whereKey($upload->attachment_id)
                ->where('vault_id', $upload->vault_id)
                ->where('is_file', true)
                ->first();

        if (!$attachment) {
            return $this->structuredError(
                'completed_attachment_missing',
                'The completed attachment no longer exists.',
            );
        }

        return Response::structured([
            'upload' => [
                'id' => $upload->id,
                'status' => $upload->status,
                'transport' => 'direct_binary',
                'idempotent' => $idempotent,
            ],
            'attachment' => $this->access->nodeData($attachment),
            'bytes' => $upload->actual_bytes,
            'sha256' => $upload->actual_sha256,
        ]);
    }

    private function expire(McpAttachmentUpload $upload): void
    {
        if ($upload->temp_path !== null) {
            Storage::disk('local')->delete($upload->temp_path);
        }

        $upload->update([
            'status' => McpAttachmentUpload::EXPIRED,
            'temp_path' => null,
        ]);
    }
}
