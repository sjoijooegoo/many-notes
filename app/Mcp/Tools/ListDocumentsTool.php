<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpVaultAccess;
use App\Models\VaultNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
final class ListDocumentsTool extends ManyNotesTool
{
    protected string $name = 'list_documents';

    protected string $description = 'List folders and Markdown documents directly inside one Many Notes folder.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'parent_id' => $schema->integer()->description('Folder ID. Omit for the vault root.'),
            'offset' => $schema->integer()->min(0)->description('Number of entries to skip. Defaults to 0.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Maximum entries to return. Defaults to 50.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'offset' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::READ);

        if ($vault instanceof Response) {
            return $vault;
        }

        $parentId = $this->nullableIntValue($data, 'parent_id');
        $parent = $this->parentFolder($vault, $parentId);

        if ($parent instanceof Response) {
            return $parent;
        }

        $offset = $this->nullableIntValue($data, 'offset') ?? 0;
        $limit = $this->nullableIntValue($data, 'limit') ?? 50;
        $nodes = $vault->nodes()
            ->where('parent_id', $parentId)
            ->where(function ($query): void {
                $query->where('is_file', false)
                    ->orWhere(function ($query): void {
                        $query->where('is_file', true)->where('extension', 'md');
                    });
            })
            ->orderBy('is_file')
            ->orderBy('name')
            ->offset($offset)
            ->limit($limit + 1)
            ->get();
        $hasMore = $nodes->count() > $limit;

        $entries = $nodes
            ->take($limit)
            ->map(fn(VaultNode $node): array => [
                'id' => $node->id,
                'parent_id' => $node->parent_id,
                'kind' => $node->is_file ? 'document' : 'folder',
                'name' => $node->name,
                'path' => '/' . $node->fullPath() . ($node->is_file ? '.md' : ''),
                'updated_at' => $node->updated_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return Response::structured([
            'vault_id' => $vault->id,
            'parent_id' => $parentId,
            'entries' => $entries,
            'offset' => $offset,
            'next_offset' => $hasMore ? $offset + $limit : null,
        ]);
    }
}
