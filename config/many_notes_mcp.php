<?php

declare(strict_types=1);

return [
    'host' => env('MCP_HOST', 'mcp.jcrewnote.top'),
    'url' => env('MCP_URL', 'https://' . env('MCP_HOST', 'mcp.jcrewnote.top') . '/mcp'),
];
