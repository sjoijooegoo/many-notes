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
use Override;

#[IsDestructive(false)]
#[IsOpenWorld(false)]
final class CreateFolderTool extends ManyNotesTool
{
    protected string $name = 'create_folder';

    protected string $description =
        'Create a folder in an authorized Many Notes vault. This is additive and never deletes data.';

    public function __construct(McpVaultAccess $access, private readonly CreateVaultNode $createVaultNode)
    {
        parent::__construct($access);
    }

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'parent_id' => $schema->integer()->description('Parent folder ID. Omit for the vault root.'),
            'name' => $schema->string()->min(1)->max(255)->description('Folder name.')->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'min:1', 'max:255', 'regex:/^(?![. ])[\\w\\s.,;_\\-&%#\\[\\]()=]+$/u'],
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

        $folder = $this->createVaultNode->handle($vault, [
            'parent_id' => $parentId,
            'is_file' => false,
            'name' => mb_trim($this->stringValue($data, 'name')),
        ]);

        return Response::structured(['folder' => $this->access->nodeData($folder)]);
    }
}
