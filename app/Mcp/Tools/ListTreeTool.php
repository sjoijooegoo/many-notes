<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpVaultAccess;
use App\Models\VaultNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
#[IsOpenWorld(false)]
final class ListTreeTool extends ManyNotesTool
{
    protected string $name = 'list_tree';

    protected string $description =
        'Recursively list folders and Markdown documents below a folder as bounded flat tree entries. ' .
        'Set include_files to include every attachment type and ready-to-paste Markdown references. ' .
        'Each entry includes parent_id, depth, path, and revision.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'root_folder_id' => $schema->integer()->description('Folder to start below. Omit for the vault root.'),
            'max_depth' => $schema->integer()
                ->min(1)
                ->max(10)
                ->description('Maximum descendant depth. Defaults to 5.'),
            'max_nodes' => $schema->integer()
                ->min(1)
                ->max(500)
                ->description('Maximum entries returned. Defaults to 200.'),
            'include_files' => $schema->boolean()
                ->description('Include text, images, PDFs, media, and other attachments. Defaults to false.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'root_folder_id' => ['nullable', 'integer'],
            'max_depth' => ['nullable', 'integer', 'between:1,10'],
            'max_nodes' => ['nullable', 'integer', 'between:1,500'],
            'include_files' => ['nullable', 'boolean'],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::READ);

        if ($vault instanceof Response) {
            return $vault;
        }

        $rootFolderId = $this->nullableIntValue($data, 'root_folder_id');
        $root = $this->parentFolder($vault, $rootFolderId);

        if ($root instanceof Response) {
            return $root;
        }

        $maxDepth = $this->nullableIntValue($data, 'max_depth') ?? 5;
        $maxNodes = $this->nullableIntValue($data, 'max_nodes') ?? 200;
        $includeFiles = (bool) ($data['include_files'] ?? false);
        $frontier = $rootFolderId === null ? [] : [$rootFolderId];
        $paths = $root instanceof VaultNode ? [$root->id => '/' . $root->fullPath()] : [];
        $entries = [];
        $truncated = false;
        $depthLimited = false;

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            $remaining = $maxNodes - count($entries);

            if ($remaining === 0) {
                $truncated = $frontier !== [] && $vault->nodes()
                    ->whereIn('parent_id', $frontier)
                    ->where(function (Builder $query) use ($includeFiles): void {
                        $this->includeableNodes($query, $includeFiles);
                    })
                    ->exists();

                break;
            }

            $nodes = $vault->nodes()
                ->when(
                    $depth === 1 && $rootFolderId === null,
                    fn(Builder $query): Builder => $query->whereNull('parent_id'),
                    fn(Builder $query): Builder => $query->whereIn('parent_id', $frontier),
                )
                ->where(function (Builder $query) use ($includeFiles): void {
                    $this->includeableNodes($query, $includeFiles);
                })
                ->orderBy('is_file')
                ->orderBy('name')
                ->limit($remaining + 1)
                ->get();

            if ($nodes->count() > $remaining) {
                $nodes = $nodes->take($remaining);
                $truncated = true;
            }

            $nextFrontier = [];

            foreach ($nodes as $node) {
                $parentPath = $node->parent_id === null ? '' : ($paths[$node->parent_id] ?? '');
                $extension = $node->is_file && $node->extension ? ".{$node->extension}" : '';
                $path = $parentPath . '/' . $node->name . $extension;
                $entry = [
                    ...$this->access->nodeData($node),
                    'depth' => $depth,
                    'path' => $path,
                ];

                if (!$node->is_file) {
                    $paths[$node->id] = $path;
                    $nextFrontier[] = $node->id;
                }

                $entries[] = $entry;
            }

            if ($truncated || $nextFrontier === []) {
                break;
            }

            $frontier = $nextFrontier;

            if ($depth === $maxDepth) {
                $depthLimited = $vault->nodes()
                    ->whereIn('parent_id', $frontier)
                    ->where(function (Builder $query) use ($includeFiles): void {
                        $this->includeableNodes($query, $includeFiles);
                    })
                    ->exists();
            }
        }

        return Response::structured([
            'vault_id' => $vault->id,
            'root_folder' => $root instanceof VaultNode ? $this->access->nodeData($root) : null,
            'entries' => $entries,
            'returned' => count($entries),
            'max_depth' => $maxDepth,
            'max_nodes' => $maxNodes,
            'include_files' => $includeFiles,
            'truncated' => $truncated,
            'depth_limited' => $depthLimited,
        ]);
    }

    /** @param Builder<VaultNode> $query */
    private function includeableNodes(Builder $query, bool $includeFiles): void
    {
        $query->where('is_file', false)
            ->orWhere(function (Builder $query) use ($includeFiles): void {
                $query->where('is_file', true);

                if (!$includeFiles) {
                    $query->where('extension', 'md');
                }
            });
    }
}
