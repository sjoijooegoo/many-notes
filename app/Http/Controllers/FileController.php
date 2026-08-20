<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\GetPathFromVaultNode;
use App\Actions\GetVaultNodeFromPath;
use App\Actions\ResolveTwoPaths;
use App\Enums\VaultNodeType;
use App\Models\User;
use App\Models\Vault;
use App\Models\VaultNode;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final readonly class FileController
{
    public function show(
        Request $request,
        Vault $vault,
        #[CurrentUser] User $user,
        ResolveTwoPaths $resolveTwoPaths,
        GetVaultNodeFromPath $getVaultNodeFromPath,
        GetPathFromVaultNode $getPathFromVaultNode,
    ): BinaryFileResponse {
        abort_unless($user->can('view', $vault), 403);

        abort_unless($request->has('path'), 404);

        /** @var string $path */
        $path = $request->path;

        if (!str_starts_with($path, '/') && $request->has('node')) {
            /** @var VaultNode $node */
            $node = $vault->nodes()->findOrFail($request->node);

            /**
             * @var string $currentPath
             *
             * @phpstan-ignore-next-line larastan.noUnnecessaryCollectionCall
             */
            $currentPath = $node->ancestorsAndSelf()->get()->last()->full_path;
            $path = $resolveTwoPaths->handle($currentPath, $path);
        }

        $target = $request->integer('target');
        $node = $target > 0
            ? $vault->nodes()->where('is_file', true)->find($target)
            : $getVaultNodeFromPath->handle($vault->id, $path);

        abort_unless($node instanceof VaultNode, 404);

        $type = $node->type();

        abort_if($type === VaultNodeType::NOTE, 403);

        $relativePath = $getPathFromVaultNode->handle($node);
        $absolutePath = Storage::disk('local')->path($relativePath);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        if (
            in_array(
                $type,
                [
                    VaultNodeType::AUDIO,
                    VaultNodeType::IMAGE,
                    VaultNodeType::PDF,
                    VaultNodeType::VIDEO,
                ],
                true,
            )
        ) {
            return response()->file($absolutePath, $headers);
        }

        $extension = $node->extension ? ".{$node->extension}" : '';

        return response()->download($absolutePath, $node->name . $extension, $headers);
    }
}
