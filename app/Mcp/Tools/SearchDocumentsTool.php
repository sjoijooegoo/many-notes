<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpVaultAccess;
use App\Models\VaultNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
final class SearchDocumentsTool extends ManyNotesTool
{
    protected string $name = 'search_documents';

    protected string $description =
        'Search Markdown document names and content inside one authorized Many Notes vault.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'query' => $schema->string()->min(1)->max(200)->description('Text to search for.')->required(),
            'limit' => $schema->integer()->min(1)->max(50)->description('Maximum results to return. Defaults to 20.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::READ);

        if ($vault instanceof Response) {
            return $vault;
        }

        $query = $this->stringValue($data, 'query');
        $escapedQuery = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $limit = $this->nullableIntValue($data, 'limit') ?? 20;
        $documents = $vault->nodes()
            ->where('is_file', true)
            ->where('extension', 'md')
            ->where(function ($builder) use ($escapedQuery): void {
                $builder->where('name', 'like', "%{$escapedQuery}%")
                    ->orWhere('content', 'like', "%{$escapedQuery}%");
            })
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn(VaultNode $node): array => [
                ...$this->access->documentData($node, false),
                'excerpt' => Str::limit($node->content ?? '', 400),
            ])
            ->all();

        return Response::structured([
            'vault_id' => $vault->id,
            'query' => $query,
            'documents' => $documents,
        ]);
    }
}
