<?php

declare(strict_types=1);

use App\Http\Controllers\McpAttachmentUploadController;
use App\Mcp\ManyNotesServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

$mcpHost = config('many_notes_mcp.host');
$mcpHost = is_string($mcpHost) ? $mcpHost : 'mcp.jcrewnote.top';

Route::domain($mcpHost)->group(function (): void {
    Mcp::web('/mcp', ManyNotesServer::class)
        ->middleware(['auth:sanctum', 'throttle:60,1']);

    Route::put('/mcp/attachment-uploads/{uploadId}', McpAttachmentUploadController::class)
        ->whereUuid('uploadId')
        ->middleware('throttle:30,1')
        ->name('mcp.attachment-uploads.store');
});
