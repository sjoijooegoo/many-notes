<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\CreateDocumentTool;
use App\Mcp\Tools\CreateFolderTool;
use App\Mcp\Tools\EditDocumentTool;
use App\Mcp\Tools\FormatReferencesTool;
use App\Mcp\Tools\GetDocumentTool;
use App\Mcp\Tools\ListDocumentsTool;
use App\Mcp\Tools\ListTreeTool;
use App\Mcp\Tools\ListVaultsTool;
use App\Mcp\Tools\MoveNodeTool;
use App\Mcp\Tools\RenameNodeTool;
use App\Mcp\Tools\SearchDocumentsTool;
use App\Mcp\Tools\SearchNodesTool;
use App\Mcp\Tools\UpdateDocumentTool;
use App\Mcp\Tools\UploadAttachmentTool;
use Laravel\Mcp\Server;

final class ManyNotesServer extends Server
{
    protected string $name = 'Many Notes';

    protected string $version = '1.3.0';

    protected string $instructions = <<<'MARKDOWN'
        Read and manage Markdown documents in the user's authorized Many Notes vaults.
        Always read a document before updating it and pass its revision value back as expected_revision.
        Prefer edit_document for small changes instead of uploading a complete Markdown document.
        Use search_nodes to discover documents and attachments that should be cited.
        Use each result's reference.recommended value or format_references; never invent vault paths.
        Use upload_attachment for local or generated files, then insert its returned reference with edit_document.
        Internal references only resolve inside the same vault as the document being written.
        This server intentionally does not provide a document deletion capability.
    MARKDOWN;

    protected array $tools = [
        ListVaultsTool::class,
        ListDocumentsTool::class,
        ListTreeTool::class,
        GetDocumentTool::class,
        SearchDocumentsTool::class,
        SearchNodesTool::class,
        FormatReferencesTool::class,
        UploadAttachmentTool::class,
        CreateFolderTool::class,
        CreateDocumentTool::class,
        UpdateDocumentTool::class,
        EditDocumentTool::class,
        RenameNodeTool::class,
        MoveNodeTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
