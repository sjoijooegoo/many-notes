<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\McpAttachmentUpload;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class McpAttachmentUploadController
{
    public function __invoke(Request $request, string $uploadId): JsonResponse
    {
        $upload = McpAttachmentUpload::query()->find($uploadId);

        if (!$upload || !$this->validToken($request, $upload)) {
            return response()->json(['error' => 'Invalid upload session or token.'], 401);
        }

        if ($upload->status === McpAttachmentUpload::COMPLETED) {
            return response()->json(['error' => 'Upload session has already been completed.'], 409);
        }

        if ($upload->status === McpAttachmentUpload::UPLOADING) {
            return response()->json(['error' => 'Upload session is currently in use.'], 409);
        }

        if ($upload->expires_at->isPast()) {
            $this->expire($upload);

            return response()->json(['error' => 'Upload session has expired.'], 410);
        }

        if ($upload->status === McpAttachmentUpload::UPLOADED) {
            return $this->uploadedResponse($upload, true);
        }

        if ($upload->status !== McpAttachmentUpload::PENDING) {
            return response()->json(['error' => 'Upload session is currently in use.'], 409);
        }

        $contentLength = $request->header('Content-Length');

        if (
            $contentLength !== null
            && ctype_digit($contentLength)
            && (int) $contentLength !== $upload->expected_bytes
        ) {
            return response()->json([
                'error' => 'Content-Length does not match the declared file size.',
                'expected_bytes' => $upload->expected_bytes,
            ], 422);
        }

        $claimed = McpAttachmentUpload::query()
            ->whereKey($upload->id)
            ->where('status', McpAttachmentUpload::PENDING)
            ->where('expires_at', '>', now())
            ->update(['status' => McpAttachmentUpload::UPLOADING]);

        if ($claimed !== 1) {
            return response()->json(['error' => 'Upload session could not be claimed.'], 409);
        }

        $tempPath = 'mcp-attachment-uploads/' . $upload->id . '.upload';

        try {
            $result = $this->streamRequest($request, $tempPath, $upload->expected_bytes);

            if (
                $upload->expected_sha256 !== null
                && !hash_equals($upload->expected_sha256, $result['sha256'])
            ) {
                $this->resetFailedUpload($upload, $tempPath);

                return response()->json([
                    'error' => 'Uploaded bytes do not match the declared SHA-256 digest.',
                    'actual_sha256' => $result['sha256'],
                ], 422);
            }

            $updated = McpAttachmentUpload::query()
                ->whereKey($upload->id)
                ->where('status', McpAttachmentUpload::UPLOADING)
                ->update([
                    'status' => McpAttachmentUpload::UPLOADED,
                    'temp_path' => $tempPath,
                    'actual_bytes' => $result['bytes'],
                    'actual_sha256' => $result['sha256'],
                    'mime_type' => $result['mime_type'],
                    'uploaded_at' => now(),
                ]);

            if ($updated !== 1) {
                Storage::disk('local')->delete($tempPath);

                return response()->json(['error' => 'Upload session changed while receiving bytes.'], 409);
            }

            return $this->uploadedResponse($upload->refresh(), false);
        } catch (InvalidArgumentException $exception) {
            $this->resetFailedUpload($upload, $tempPath);

            return response()->json(['error' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);
            $this->resetFailedUpload($upload, $tempPath);

            return response()->json(['error' => 'The binary upload could not be stored.'], 500);
        }
    }

    private function validToken(Request $request, McpAttachmentUpload $upload): bool
    {
        $authorization = $request->header('Authorization', '');

        if (!str_starts_with($authorization, 'Upload ')) {
            return false;
        }

        $token = mb_substr($authorization, 7);

        return $token !== '' && hash_equals($upload->token_hash, hash('sha256', $token));
    }

    /** @return array{bytes: int, sha256: string, mime_type: string} */
    private function streamRequest(Request $request, string $tempPath, int $expectedBytes): array
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory('mcp-attachment-uploads');
        $disk->delete($tempPath);
        $source = $request->getContent(true);

        if (!is_resource($source)) {
            throw new RuntimeException('The request body is not readable.');
        }

        $target = fopen($disk->path($tempPath), 'wb');

        if (!is_resource($target)) {
            throw new RuntimeException('The temporary upload file is not writable.');
        }

        $hash = hash_init('sha256');
        $bytes = 0;

        try {
            while (!feof($source)) {
                $chunk = fread($source, 1024 * 1024);

                if ($chunk === false) {
                    throw new RuntimeException('The request body could not be read.');
                }

                if ($chunk === '') {
                    continue;
                }

                $bytes += mb_strlen($chunk, '8bit');

                if ($bytes > $expectedBytes || $bytes > $this->maxBytes()) {
                    throw new InvalidArgumentException('The request body exceeds the declared file size.');
                }

                hash_update($hash, $chunk);

                $remaining = $chunk;

                while ($remaining !== '') {
                    $written = fwrite($target, $remaining);

                    if ($written === false || $written === 0) {
                        throw new RuntimeException('The request body could not be written.');
                    }

                    $remaining = mb_strcut($remaining, $written, null, '8bit');
                }
            }
        } finally {
            fclose($target);
        }

        if ($bytes !== $expectedBytes) {
            throw new InvalidArgumentException('The request body does not match the declared file size.');
        }

        $mimeType = new finfo(FILEINFO_MIME_TYPE)->file($disk->path($tempPath));

        return [
            'bytes' => $bytes,
            'sha256' => hash_final($hash),
            'mime_type' => is_string($mimeType) && $mimeType !== ''
                ? $mimeType
                : 'application/octet-stream',
        ];
    }

    private function resetFailedUpload(McpAttachmentUpload $upload, string $tempPath): void
    {
        Storage::disk('local')->delete($tempPath);
        McpAttachmentUpload::query()
            ->whereKey($upload->id)
            ->where('status', McpAttachmentUpload::UPLOADING)
            ->update(['status' => McpAttachmentUpload::PENDING]);
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

    private function uploadedResponse(McpAttachmentUpload $upload, bool $idempotent): JsonResponse
    {
        return response()->json([
            'upload' => [
                'id' => $upload->id,
                'status' => $upload->status,
                'bytes' => $upload->actual_bytes,
                'sha256' => $upload->actual_sha256,
                'mime_type' => $upload->mime_type,
                'idempotent' => $idempotent,
                'next_tool' => 'complete_attachment_upload',
            ],
        ]);
    }

    private function maxBytes(): int
    {
        $configured = config('many_notes_mcp.max_attachment_bytes');

        return is_int($configured) ? max(1, $configured) : 10 * 1024 * 1024;
    }
}
