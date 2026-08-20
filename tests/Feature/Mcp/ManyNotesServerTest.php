<?php

declare(strict_types=1);

use App\Actions\CreateVault;
use App\Actions\CreateVaultNode;
use App\Actions\GetPathFromVaultNode;
use App\Mcp\ManyNotesServer;
use App\Mcp\Tools\CreateDocumentTool;
use App\Mcp\Tools\GetDocumentTool;
use App\Mcp\Tools\ListVaultsTool;
use App\Mcp\Tools\UpdateDocumentTool;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Server\Transport\FakeTransporter;

it('only lists vaults granted to the API token', function (): void {
    $user = User::factory()->create();
    $allowedVault = new CreateVault()->handle($user, ['name' => 'Allowed vault']);
    new CreateVault()->handle($user, ['name' => 'Other vault']);
    $token = $user->createToken('test', ["mcp:vault:{$allowedVault->id}:read"]);
    $user->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($user)
        ->tool(ListVaultsTool::class)
        ->assertOk()
        ->assertSee('Allowed vault')
        ->assertDontSee('Other vault');
});

it('reads every currently visible vault with the global read ability', function (): void {
    $user = User::factory()->create();
    $firstVault = new CreateVault()->handle($user, ['name' => 'First vault']);
    $secondVault = new CreateVault()->handle($user, ['name' => 'Second vault']);
    $token = $user->createToken('test', [
        "mcp:vault:{$firstVault->id}:read",
        App\Mcp\Support\McpVaultAccess::READ_ALL_VISIBLE_ABILITY,
    ]);
    $user->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($user)
        ->tool(ListVaultsTool::class)
        ->assertOk()
        ->assertSee('First vault')
        ->assertSee('Second vault');

    ManyNotesServer::actingAs($user)
        ->tool(CreateDocumentTool::class, [
            'vault_id' => $secondVault->id,
            'name' => 'Write should fail',
        ])
        ->assertHasErrors(['Access denied']);
});

it('stops globally readable tokens from seeing a collaboration after access is revoked', function (): void {
    [$owner, $collaborator] = User::factory(2)->create();
    $vault = new CreateVault()->handle($owner, ['name' => 'Shared vault']);
    $vault->collaborators()->attach($collaborator, ['accepted' => true]);
    $token = $collaborator->createToken('test', [
        App\Mcp\Support\McpVaultAccess::READ_ALL_VISIBLE_ABILITY,
    ]);
    $collaborator->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($collaborator)
        ->tool(ListVaultsTool::class)
        ->assertOk()
        ->assertSee('Shared vault');

    $vault->collaborators()->detach($collaborator);

    ManyNotesServer::actingAs($collaborator)
        ->tool(ListVaultsTool::class)
        ->assertOk()
        ->assertDontSee('Shared vault');
});

it('does not treat a browser session as an MCP API token', function (): void {
    $user = User::factory()->create();
    new CreateVault()->handle($user, ['name' => 'Private vault']);

    ManyNotesServer::actingAs($user)
        ->tool(ListVaultsTool::class)
        ->assertOk()
        ->assertDontSee('Private vault');
});

it('reads a Markdown document from an authorized vault', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $document = new CreateVaultNode()->handle($vault, [
        'is_file' => true,
        'name' => 'Guide',
        'extension' => 'md',
        'content' => '# Existing guide',
    ]);
    $token = $user->createToken('test', ["mcp:vault:{$vault->id}:read"]);
    $user->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($user)
        ->tool(GetDocumentTool::class, [
            'vault_id' => $vault->id,
            'document_id' => $document->id,
        ])
        ->assertOk()
        ->assertSee('# Existing guide');
});

it('does not allow a read-only token to create documents', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $token = $user->createToken('test', ["mcp:vault:{$vault->id}:read"]);
    $user->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($user)
        ->tool(CreateDocumentTool::class, [
            'vault_id' => $vault->id,
            'name' => 'Unauthorized note',
            'content' => 'Should not be created',
        ])
        ->assertHasErrors(['Access denied']);

    expect($vault->nodes()->where('name', 'Unauthorized note')->exists())->toBeFalse();
});

it('creates and safely updates Markdown documents', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $token = $user->createToken('test', [
        "mcp:vault:{$vault->id}:read",
        "mcp:vault:{$vault->id}:write",
    ]);
    $user->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($user)
        ->tool(CreateDocumentTool::class, [
            'vault_id' => $vault->id,
            'name' => 'MCP note.md',
            'content' => '# First version',
        ])
        ->assertOk();

    $document = $vault->nodes()->where('name', 'MCP note')->firstOrFail();
    $originalUpdatedAt = $document->updated_at->toIso8601String();
    $this->travel(1)->seconds();

    ManyNotesServer::actingAs($user)
        ->tool(UpdateDocumentTool::class, [
            'vault_id' => $vault->id,
            'document_id' => $document->id,
            'expected_updated_at' => $originalUpdatedAt,
            'content' => '# Second version',
        ])
        ->assertOk();

    $document->refresh();
    $path = new GetPathFromVaultNode()->handle($document);

    expect($document->content)->toBe('# Second version')
        ->and(Storage::disk('local')->get($path))->toBe('# Second version');

    ManyNotesServer::actingAs($user)
        ->tool(UpdateDocumentTool::class, [
            'vault_id' => $vault->id,
            'document_id' => $document->id,
            'expected_updated_at' => $originalUpdatedAt,
            'content' => '# Stale overwrite',
        ])
        ->assertHasErrors(['Document changed after it was read']);

    expect($document->refresh()->content)->toBe('# Second version');
});

it('does not expose a deletion tool', function (): void {
    $server = app(ManyNotesServer::class, ['transport' => new FakeTransporter()]);
    $server->start();
    $toolNames = $server->createContext()->tools()->map->name()->all();

    expect($toolNames)->not->toContain('delete_document');
});
