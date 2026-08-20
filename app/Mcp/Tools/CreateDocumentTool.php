<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\CreateVaultNode;
use App\Mcp\Support\McpVaultAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class CreateDocumentTool extends ManyNotesTool
{
    protected string $name = 'create_document';

    protected string $description =
        'Create a Markdown document in an authorized Many Notes vault. This is additive and never deletes data.';

    public function __construct(McpVaultAccess $access, private readonly CreateVaultNode $createVaultNode)
    {
        parent::__construct($access);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'parent_id' => $schema->integer()->description('Parent folder ID. Omit for the vault root.'),
            'name' => $schema->string()
                ->min(1)
                ->max(255)
                ->description('Document name, with or without the .md suffix.')
                ->required(),
            'content' => $schema->string()
                ->max(2000000)
                ->description('Markdown content. Defaults to an empty document.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'min:1', 'max:255', 'regex:/^(?![. ])[\\w\\s.,;_\\-&%#\\[\\]()=]+$/u'],
            'content' => ['nullable', 'string', 'max:2000000'],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::WRITE);

        if ($vault instanceof Response) {
            return $vault;
        }

        $parentId = $this->nullableIntValue($data, 'parent_id');
        $parent = $this->parentFolder($vault, $parentId);

        if ($parent instanceof Response) {
            return $parent;
        }

        $name = $this->noteName($this->stringValue($data, 'name'));

        if ($name === '') {
            return Response::error('Document name cannot be empty.');
        }

        $document = $this->createVaultNode->handle($vault, [
            'parent_id' => $parentId,
            'is_file' => true,
            'name' => $name,
            'extension' => 'md',
            'content' => $this->stringValue($data, 'content'),
        ]);

        return Response::structured(['document' => $this->access->documentData($document)]);
    }
}
