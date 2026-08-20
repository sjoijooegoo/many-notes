<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateVaultNode;
use App\Actions\EnsureImportedDirectoryPath;
use App\Actions\ProcessImportedFile;
use App\Http\Requests\ImportVaultNodeRequest;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultNode;
use App\ViewModels\VaultNodeViewModel;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final readonly class VaultNodeImportController
{
    public function __invoke(
        ImportVaultNodeRequest $request,
        Vault $vault,
        #[CurrentUser] User $user,
        ProcessImportedFile $processImportedFile,
        EnsureImportedDirectoryPath $ensureImportedDirectoryPath,
        CreateVaultNode $createVaultNode,
    ): JsonResponse {
        if ($user->cannot('update', $vault)) {
            abort(403);
        }

        /**
         * @var array{
         *   parent_id?: int|null,
         *   root_name?: string|null,
         *   relative_paths?: array<int, string>,
         *   files: array<int, UploadedFile>
         * } $data
         */
        $data = $request->validated();
        $parent = null;

        if (($data['parent_id'] ?? null) !== null) {
            $parent = $vault->nodes()
                ->where('id', $data['parent_id'])
                ->where('is_file', false)
                ->first();

            if (!$parent) {
                abort(404);
            }
        }

        $root = null;

        if (isset($data['root_name']) && $data['root_name'] !== '') {
            try {
                $ensureImportedDirectoryPath->validateSegment($data['root_name']);
            } catch (InvalidArgumentException) {
                abort(422, 'Invalid root folder name.');
            }

            $root = $createVaultNode->handle($vault, [
                'parent_id' => $parent?->id,
                'is_file' => false,
                'name' => $data['root_name'],
            ]);
            $parent = $root;
        }

        $importedFiles = [];
        $createdFolders = [];
        $skippedFiles = [];

        foreach ($data['files'] as $index => $file) {
            $relativePath = $data['relative_paths'][$index] ?? $file->getClientOriginalName();

            try {
                $resolved = $ensureImportedDirectoryPath->handle($vault, $parent, $relativePath);
            } catch (InvalidArgumentException) {
                $skippedFiles[] = $file->getClientOriginalName();

                continue;
            }

            foreach ($resolved['folders'] as $folder) {
                $createdFolders[$folder->id] = $folder;
            }

            $fileMimeType = $file->getMimeType() ?? '';
            $fileName = $file->getClientOriginalName();
            $filePath = $file->getRealPath();
            $node = $processImportedFile->handle(
                $vault,
                $resolved['parent'],
                $fileName,
                $filePath,
                $fileMimeType,
            );

            $importedFiles[] = VaultNodeViewModel::fromModel($node);
        }

        return response()->json([
            'files' => $importedFiles,
            'folders' => array_values(array_map(
                VaultNodeViewModel::fromModel(...),
                $createdFolders,
            )),
            'root' => $root instanceof VaultNode ? VaultNodeViewModel::fromModel($root) : null,
            'skipped_files' => $skippedFiles,
        ]);
    }
}
