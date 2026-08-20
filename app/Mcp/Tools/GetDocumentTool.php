<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Support\McpVaultAccess;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[IsReadOnly]
#[IsOpenWorld(false)]
final class GetDocumentTool extends ManyNotesTool
{
    protected string $name = 'get_document';

    protected string $description =
        'Read one Markdown document and return its content, revision, content hash, and updated_at timestamp.';

    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'document_id' => $schema->integer()
                ->description('Document ID returned by list_documents or search_documents.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'document_id' => ['required', 'integer'],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::READ);

        if ($vault instanceof Response) {
            return $vault;
        }

        $document = $this->markdownDocument($vault, $this->intValue($data, 'document_id'));

        if ($document instanceof Response) {
            return $document;
        }

        return Response::structured(['document' => $this->access->documentData($document)]);
    }
}
