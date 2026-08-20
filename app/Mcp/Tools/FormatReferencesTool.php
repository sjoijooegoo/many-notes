<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpVaultAccess;
use App\Models\VaultNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
#[IsOpenWorld(false)]
final class FormatReferencesTool extends ManyNotesTool
{
    /** @var list<string> */
    private const array STYLES = ['auto', 'link', 'embed'];

    protected string $name = 'format_references';

    protected string $description =
        'Resolve file IDs from one authorized vault to current paths and safe, ready-to-paste Markdown. ' .
        'Use auto to embed images and link every other file type.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'node_ids' => $schema->array()
                ->items($schema->integer()->min(1))
                ->min(1)
                ->max(50)
                ->unique()
                ->description('File IDs returned by search_nodes, list_tree, or another read tool.')
                ->required(),
            'style' => $schema->string()
                ->enum(self::STYLES)
                ->description(
                    'auto embeds images and links other files; link always links; embed only supports images.',
                ),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'node_ids' => ['required', 'array', 'min:1', 'max:50'],
            'node_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'style' => ['nullable', 'string', Rule::in(self::STYLES)],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::READ);

        if ($vault instanceof Response) {
            return $vault;
        }

        $rawNodeIds = is_array($data['node_ids'] ?? null) ? $data['node_ids'] : [];
        $nodeIds = [];

        foreach ($rawNodeIds as $rawNodeId) {
            if (is_int($rawNodeId) || is_string($rawNodeId)) {
                $nodeIds[] = (int) $rawNodeId;
            }
        }

        $style = $this->stringValue($data, 'style', 'auto');
        $nodes = $vault->nodes()
            ->where('is_file', true)
            ->whereIn('id', $nodeIds)
            ->get()
            ->keyBy('id');
        $references = [];
        $missingNodeIds = [];
        $unsupportedNodeIds = [];

        foreach ($nodeIds as $nodeId) {
            $node = $nodes->get($nodeId);

            if (!$node instanceof VaultNode) {
                $missingNodeIds[] = $nodeId;

                continue;
            }

            $nodeData = $this->access->nodeData($node);
            /** @var array{link: string, embed: string|null, recommended: string} $reference */
            $reference = $nodeData['reference'];
            $appliedStyle = $style;
            $markdown = match ($style) {
                'link' => $reference['link'],
                'embed' => $reference['embed'],
                default => $reference['recommended'],
            };

            if ($style === 'auto') {
                $appliedStyle = $reference['embed'] === null ? 'link' : 'embed';
            }

            if ($markdown === null) {
                $unsupportedNodeIds[] = $nodeId;
            }

            $references[] = [
                ...$nodeData,
                'requested_style' => $style,
                'applied_style' => $markdown === null ? null : $appliedStyle,
                'markdown' => $markdown,
                'error' => $markdown === null ? 'Only image files support embed style.' : null,
            ];
        }

        return Response::structured([
            'vault_id' => $vault->id,
            'requested_style' => $style,
            'references' => $references,
            'missing_node_ids' => $missingNodeIds,
            'unsupported_node_ids' => $unsupportedNodeIds,
        ]);
    }
}
