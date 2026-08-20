<?php

declare(strict_types=1);

return [
    'host' => env('MCP_HOST', 'mcp.jcrewnote.top'),
    'url' => env('MCP_URL', 'https://' . env('MCP_HOST', 'mcp.jcrewnote.top') . '/mcp'),
    'attachment_upload_url' => env(
        'MCP_ATTACHMENT_UPLOAD_URL',
        'https://' . env('MCP_HOST', 'mcp.jcrewnote.top') . '/mcp/attachment-uploads',
    ),
    'attachment_upload_ttl_seconds' => 10 * 60,
    'max_active_attachment_uploads_per_token' => 5,
    'max_attachment_bytes' => 10 * 1024 * 1024,
];
