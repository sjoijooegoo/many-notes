<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpVaultAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
#[IsOpenWorld(false)]
final class SearchNodesTool extends ManyNotesTool
{
    /** @var list<string> */
    private const array DEFAULT_TYPES = [
        'document',
        'text',
        'image',
        'pdf',
        'audio',
        'video',
        'file',
    ];

    /** @var list<string> */
    private const array TYPES = [
        ...self::DEFAULT_TYPES,
        'folder',
    ];

    private const int MAX_CANDIDATES = 1000;

    protected string $name = 'search_nodes';

    protected string $description =
        'Search documents, editable text, images, PDFs, media, attachments, and optionally folders in one ' .
        'authorized vault. File results include server-generated Markdown references; never invent a path.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'query' => $schema->string()->min(1)->max(200)
                ->description('Text to search for in node names and editable text content.')
                ->required(),
            'types' => $schema->array()
                ->items($schema->string()->enum(self::TYPES))
                ->min(1)
                ->max(count(self::TYPES))
                ->unique()
                ->description('Node types to return. Defaults to every file type, excluding folders.'),
            'path_prefix' => $schema->string()->min(1)->max(4096)
                ->description('Optional vault-absolute folder path prefix, such as /Projects/Guide.'),
            'limit' => $schema->integer()->min(1)->max(50)
                ->description('Maximum results to return. Defaults to 20.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'query' => ['required', 'string', 'min:1', 'max:200'],
            'types' => ['nullable', 'array', 'min:1', 'max:' . count(self::TYPES)],
            'types.*' => ['required', 'string', 'distinct', Rule::in(self::TYPES)],
            'path_prefix' => ['nullable', 'string', 'min:1', 'max:4096'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::READ);

        if ($vault instanceof Response) {
            return $vault;
        }

        $query = $this->stringValue($data, 'query');
        $escapedQuery = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $rawTypes = is_array($data['types'] ?? null) ? $data['types'] : self::DEFAULT_TYPES;
        /** @var list<string> $types */
        $types = array_values($rawTypes);
        $pathPrefix = isset($data['path_prefix'])
            ? $this->normalizePathPrefix($this->stringValue($data, 'path_prefix'))
            : null;
        $limit = $this->nullableIntValue($data, 'limit') ?? 20;
        $includeFolders = in_array('folder', $types, true);
        $includeFiles = count(array_diff($types, ['folder'])) > 0;

        $candidateQuery = $vault->nodes()
            ->where(function (Builder $builder) use ($escapedQuery): void {
                $builder->where('name', 'like', "%{$escapedQuery}%")
                    ->orWhere('content', 'like', "%{$escapedQuery}%");
            })
            ->when(
                !$includeFolders,
                fn(Builder $builder): Builder => $builder->where('is_file', true),
            )
            ->when(
                !$includeFiles,
                fn(Builder $builder): Builder => $builder->where('is_file', false),
            )
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(self::MAX_CANDIDATES + 1)
            ->get();

        $candidateLimitReached = $candidateQuery->count() > self::MAX_CANDIDATES;
        $results = [];
        $scanned = 0;
        $moreMatches = false;

        foreach ($candidateQuery->take(self::MAX_CANDIDATES) as $node) {
            $scanned++;
            $nodeData = $this->access->nodeData($node);

            if (!in_array($nodeData['type'], $types, true)) {
                continue;
            }

            $nodePath = $nodeData['path'];

            if (!is_string($nodePath)) {
                continue;
            }

            if ($pathPrefix !== null && !$this->pathStartsWith($nodePath, $pathPrefix)) {
                continue;
            }

            if ($node->content !== null) {
                $nodeData['excerpt'] = Str::limit($node->content, 400);
            }

            $results[] = $nodeData;

            if (count($results) > $limit) {
                $moreMatches = true;

                break;
            }
        }

        return Response::structured([
            'vault_id' => $vault->id,
            'query' => $query,
            'types' => $types,
            'path_prefix' => $pathPrefix,
            'nodes' => array_slice($results, 0, $limit),
            'returned' => min(count($results), $limit),
            'scanned' => $scanned,
            'truncated' => $moreMatches || $candidateLimitReached,
            'candidate_limit_reached' => $candidateLimitReached,
        ]);
    }

    private function normalizePathPrefix(string $path): string
    {
        return '/' . mb_trim($path, " /\t\n\r\0\x0B");
    }

    private function pathStartsWith(string $path, string $prefix): bool
    {
        return $prefix === '/'
            || $path === $prefix
            || str_starts_with($path, $prefix . '/');
    }
}
