<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\CreateDocumentTool;
use App\Mcp\Tools\CreateFolderTool;
use App\Mcp\Tools\GetDocumentTool;
use App\Mcp\Tools\ListDocumentsTool;
use App\Mcp\Tools\ListVaultsTool;
use App\Mcp\Tools\SearchDocumentsTool;
use App\Mcp\Tools\UpdateDocumentTool;
use Laravel\Mcp\Server;

final class ManyNotesServer extends Server
{
    protected string $name = 'Many Notes';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Read and manage Markdown documents in the user's authorized Many Notes vaults.
        Always read a document before updating it and pass its updated_at value back as expected_updated_at.
        This server intentionally does not provide a document deletion capability.
    MARKDOWN;

    protected array $tools = [
        ListVaultsTool::class,
        ListDocumentsTool::class,
        GetDocumentTool::class,
        SearchDocumentsTool::class,
        CreateFolderTool::class,
        CreateDocumentTool::class,
        UpdateDocumentTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
