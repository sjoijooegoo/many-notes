<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\MoveVaultNode;
use App\Exceptions\InvalidVaultNodeMove;
use App\Exceptions\VaultNodeVersionConflict;
use App\Mcp\Support\McpVaultAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Override;

#[IsIdempotent]
#[IsOpenWorld(false)]
final class MoveNodeTool extends ManyNotesTool
{
    protected string $name = 'move_node';

    protected string $description =
        'Move a folder or Markdown document into another folder, or to the vault root. This tool never deletes data.';

    public function __construct(McpVaultAccess $access, private readonly MoveVaultNode $moveVaultNode)
    {
        parent::__construct($access);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'node_id' => $schema->integer()->description('Folder or document ID returned by list_tree.')->required(),
            'expected_revision' => $schema->integer()
                ->min(1)
                ->description('Exact revision returned by list_tree, list_documents, or get_document.')
                ->required(),
            'destination_parent_id' => $schema->integer()
                ->nullable()
                ->description('Destination folder ID, or null to move the node to the vault root.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'node_id' => ['required', 'integer'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'destination_parent_id' => ['present', 'nullable', 'integer'],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::WRITE);

        if ($vault instanceof Response) {
            return $vault;
        }

        $node = $this->manageableNode($vault, $this->intValue($data, 'node_id'));

        if ($node instanceof Response) {
            return $node;
        }

        $expectedRevision = $this->intValue($data, 'expected_revision');

        if ($node->revision !== $expectedRevision) {
            return $this->nodeVersionConflict($node);
        }

        try {
            $node = $this->moveVaultNode->handle(
                $node,
                $this->nullableIntValue($data, 'destination_parent_id'),
                $expectedRevision,
            );
        } catch (InvalidVaultNodeMove $exception) {
            return $this->structuredError($exception->reason, $exception->getMessage());
        } catch (VaultNodeVersionConflict) {
            $node->refresh();

            return $this->nodeVersionConflict($node);
        }

        return Response::structured(['node' => $this->access->nodeData($node)]);
    }
}
