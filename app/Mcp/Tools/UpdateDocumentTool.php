<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Actions\UpdateVaultNode;
use App\Mcp\Support\McpVaultAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsIdempotent]
#[IsOpenWorld(false)]
final class UpdateDocumentTool extends ManyNotesTool
{
    protected string $name = 'update_document';

    protected string $description =
        'Update the name or Markdown content of a document. ' .
        'Requires the updated_at value returned by get_document to prevent overwriting newer edits.';

    public function __construct(McpVaultAccess $access, private readonly UpdateVaultNode $updateVaultNode)
    {
        parent::__construct($access);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vault_id' => $schema->integer()->description('Vault ID returned by list_vaults.')->required(),
            'document_id' => $schema->integer()->description('Document ID returned by get_document.')->required(),
            'expected_updated_at' => $schema->string()
                ->description('Exact updated_at value returned by get_document.')
                ->required(),
            'name' => $schema->string()
                ->min(1)
                ->max(255)
                ->description('New document name, with or without the .md suffix.'),
            'content' => $schema->string()->max(2000000)->description('Complete replacement Markdown content.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'vault_id' => ['required', 'integer'],
            'document_id' => ['required', 'integer'],
            'expected_updated_at' => ['required', 'date'],
            'name' => ['sometimes', 'string', 'min:1', 'max:255', 'regex:/^(?![. ])[\\w\\s.,;_\\-&%#\\[\\]()=]+$/u'],
            'content' => ['sometimes', 'string', 'max:2000000'],
        ]);
        $vault = $this->authorizedVault($request, $this->intValue($data, 'vault_id'), McpVaultAccess::WRITE);

        if ($vault instanceof Response) {
            return $vault;
        }

        $document = $this->markdownDocument($vault, $this->intValue($data, 'document_id'));

        if ($document instanceof Response) {
            return $document;
        }

        if (!$document->updated_at->equalTo(CarbonImmutable::parse($this->stringValue($data, 'expected_updated_at')))) {
            return Response::error(
                'Document changed after it was read. Read it again before updating. Current updated_at: '
                . $document->updated_at->toIso8601String()
            );
        }

        $attributes = [];

        if (array_key_exists('name', $data)) {
            $name = $this->noteName($this->stringValue($data, 'name'));

            if ($name === '') {
                return Response::error('Document name cannot be empty.');
            }

            $duplicateExists = $vault->nodes()
                ->where('parent_id', $document->parent_id)
                ->where('is_file', true)
                ->where('extension', 'md')
                ->where('name', $name)
                ->whereKeyNot($document->id)
                ->exists();

            if ($duplicateExists) {
                return Response::error('A document with this name already exists in the same folder.');
            }

            $attributes['name'] = $name;
        }

        if (array_key_exists('content', $data)) {
            $attributes['content'] = $this->stringValue($data, 'content');
        }

        if ($attributes === []) {
            return Response::error('Provide at least one of name or content.');
        }

        $document = $this->updateVaultNode->handle($document, $attributes);

        return Response::structured(['document' => $this->access->documentData($document)]);
    }
}
