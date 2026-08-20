<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\UpdateVaultNode;
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
final class RenameNodeTool extends ManyNotesTool
{
    protected string $name = 'rename_node';

    protected string $description =
        'Rename a folder or Markdown document without moving it. This tool never deletes data.';

    public function __construct(McpVaultAccess $access, private readonly UpdateVaultNode $updateVaultNode)
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
            'name' => $schema->string()
                ->min(1)
                ->max(255)
                ->description('New name. A document may include or omit its .md suffix.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'node_id' => ['required', 'integer'],
            'expected_revision' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:1', 'max:255', 'regex:/^(?![. ])[\w\s.,;_\-&%#\[\]()=]+$/u'],
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

        $name = $node->is_file
            ? $this->noteName($this->stringValue($data, 'name'))
            : mb_trim($this->stringValue($data, 'name'));

        if ($name === '') {
            return Response::error('Node name cannot be empty.');
        }

        $duplicateExists = $vault->nodes()
            ->where('parent_id', $node->parent_id)
            ->where('is_file', $node->is_file)
            ->where('extension', $node->extension)
            ->where('name', $name)
            ->whereKeyNot($node->id)
            ->exists();

        if ($duplicateExists) {
            return $this->structuredError(
                'name_conflict',
                'A node with this name already exists in the same folder.',
            );
        }

        try {
            $node = $this->updateVaultNode->handle($node, ['name' => $name], $expectedRevision);
        } catch (VaultNodeVersionConflict) {
            $node->refresh();

            return $this->nodeVersionConflict($node);
        }

        return Response::structured(['node' => $this->access->nodeData($node)]);
    }
}
