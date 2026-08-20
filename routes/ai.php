<?php

declare(strict_types=1);

use App\Mcp\ManyNotesServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Route::domain(config('many_notes_mcp.host'))->group(function (): void {
    Mcp::web('/mcp', ManyNotesServer::class)
        ->middleware(['auth:sanctum', 'throttle:60,1']);
});
