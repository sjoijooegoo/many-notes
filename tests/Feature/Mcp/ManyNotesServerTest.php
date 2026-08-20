<?php

declare(strict_types=1);

use App\Actions\CreateVault;
use App\Actions\CreateVaultNode;
use App\Actions\GetPathFromVaultNode;
use App\Mcp\ManyNotesServer;
use App\Mcp\Tools\CreateDocumentTool;
use App\Mcp\Tools\EditDocumentTool;
use App\Mcp\Tools\GetDocumentTool;
use App\Mcp\Tools\ListTreeTool;
use App\Mcp\Tools\ListVaultsTool;
use App\Mcp\Tools\MoveNodeTool;
use App\Mcp\Tools\RenameNodeTool;
use App\Mcp\Tools\UpdateDocumentTool;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\Fluent\AssertableJson;
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
        ->assertSee('# Existing guide')
        ->assertStructuredContent(fn(AssertableJson $json): AssertableJson => $json
            ->where('document.revision', 1)
            ->where('document.content_hash', hash('sha256', '# Existing guide'))
            ->etc());
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
        ->assertHasErrors(['Document changed after it was read'])
        ->assertStructuredContent(fn(AssertableJson $json): AssertableJson => $json
            ->where('error.code', 'version_conflict')
            ->where('latest_document.revision', 2)
            ->where('latest_document.content', '# Second version')
            ->etc());

    expect($document->refresh()->content)->toBe('# Second version');
});

it('partially edits unique text and safely rebases over unrelated changes', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $document = new CreateVaultNode()->handle($vault, [
        'is_file' => true,
        'name' => 'Guide',
        'extension' => 'md',
        'content' => "# Guide\n\nOld instructions.\n",
    ]);
    $token = $user->createToken('test', [
        "mcp:vault:{$vault->id}:read",
        "mcp:vault:{$vault->id}:write",
    ]);
    $user->withAccessToken($token->accessToken);

    app(App\Actions\UpdateVaultNode::class)->handle($document, [
        'content' => "# Guide\n\nOld instructions.\n\nConcurrent footer.\n",
    ]);

    ManyNotesServer::actingAs($user)
        ->tool(EditDocumentTool::class, [
            'vault_id' => $vault->id,
            'document_id' => $document->id,
            'expected_revision' => 1,
            'mode' => 'exact_text',
            'target' => 'Old instructions.',
            'replacement' => 'New instructions.',
        ])
        ->assertOk()
        ->assertStructuredContent(fn(AssertableJson $json): AssertableJson => $json
            ->where('document.revision', 3)
            ->where('edit.rebased', true)
            ->where('edit.applied_to_revision', 2)
            ->etc());

    expect($document->refresh()->content)
        ->toBe("# Guide\n\nNew instructions.\n\nConcurrent footer.\n");
});

it('replaces a unique heading section while ignoring headings in fenced code', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $document = new CreateVaultNode()->handle($vault, [
        'is_file' => true,
        'name' => 'Guide',
        'extension' => 'md',
        'content' => "# Guide\n\n```md\n## Setup\nexample\n```\n\n## Setup\nOld body.\n\n## Next\nKeep me.\n",
    ]);
    $token = $user->createToken('test', ["mcp:vault:{$vault->id}:write"]);
    $user->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($user)
        ->tool(EditDocumentTool::class, [
            'vault_id' => $vault->id,
            'document_id' => $document->id,
            'expected_revision' => 1,
            'mode' => 'heading_section',
            'target' => '## Setup',
            'replacement' => "New body.\n",
        ])
        ->assertOk();

    expect($document->refresh()->content)
        ->toContain("```md\n## Setup\nexample\n```")
        ->toContain("## Setup\nNew body.\n## Next\nKeep me.")
        ->not->toContain('Old body.');
});

it('returns the latest section when a heading edit conflicts', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $document = new CreateVaultNode()->handle($vault, [
        'is_file' => true,
        'name' => 'Guide',
        'extension' => 'md',
        'content' => "# Guide\n\n## Setup\nOld body.\n",
    ]);
    $token = $user->createToken('test', ["mcp:vault:{$vault->id}:write"]);
    $user->withAccessToken($token->accessToken);
    app(App\Actions\UpdateVaultNode::class)->handle($document, [
        'content' => "# Guide\n\n## Setup\nSomeone else's body.\n",
    ]);

    ManyNotesServer::actingAs($user)
        ->tool(EditDocumentTool::class, [
            'vault_id' => $vault->id,
            'document_id' => $document->id,
            'expected_revision' => 1,
            'mode' => 'heading_section',
            'target' => 'Setup',
            'replacement' => 'My body.',
        ])
        ->assertHasErrors(['Document changed after it was read'])
        ->assertStructuredContent(fn(AssertableJson $json): AssertableJson => $json
            ->where('error.code', 'version_conflict')
            ->where('latest_document.revision', 2)
            ->where('target_status', 'found_but_not_safe_to_rebase')
            ->where('current_section', "Someone else's body.\n")
            ->etc());
});

it('renames and moves nodes and recursively lists their revisions', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $folder = new CreateVaultNode()->handle($vault, ['is_file' => false, 'name' => 'Guides']);
    $nested = new CreateVaultNode()->handle($vault, [
        'parent_id' => $folder->id,
        'is_file' => false,
        'name' => 'Linux',
    ]);
    $document = new CreateVaultNode()->handle($vault, [
        'is_file' => true,
        'name' => 'Commands',
        'extension' => 'md',
        'content' => '# Commands',
    ]);
    $token = $user->createToken('test', [
        "mcp:vault:{$vault->id}:read",
        "mcp:vault:{$vault->id}:write",
    ]);
    $user->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($user)
        ->tool(RenameNodeTool::class, [
            'vault_id' => $vault->id,
            'node_id' => $folder->id,
            'expected_revision' => 1,
            'name' => 'Handbook',
        ])
        ->assertOk();

    ManyNotesServer::actingAs($user)
        ->tool(MoveNodeTool::class, [
            'vault_id' => $vault->id,
            'node_id' => $document->id,
            'expected_revision' => 1,
            'destination_parent_id' => $nested->id,
        ])
        ->assertOk();

    ManyNotesServer::actingAs($user)
        ->tool(ListTreeTool::class, [
            'vault_id' => $vault->id,
            'max_depth' => 5,
        ])
        ->assertOk()
        ->assertSee('/Handbook/Linux/Commands.md')
        ->assertStructuredContent(fn(AssertableJson $json): AssertableJson => $json
            ->where('returned', 3)
            ->where('truncated', false)
            ->where('depth_limited', false)
            ->etc());

    expect($folder->refresh()->revision)->toBe(2)
        ->and($document->refresh()->revision)->toBe(2)
        ->and($document->parent_id)->toBe($nested->id);
});

it('does not move a folder inside one of its descendants through MCP', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $folder = new CreateVaultNode()->handle($vault, ['is_file' => false, 'name' => 'Parent']);
    $child = new CreateVaultNode()->handle($vault, [
        'parent_id' => $folder->id,
        'is_file' => false,
        'name' => 'Child',
    ]);
    $token = $user->createToken('test', ["mcp:vault:{$vault->id}:write"]);
    $user->withAccessToken($token->accessToken);

    ManyNotesServer::actingAs($user)
        ->tool(MoveNodeTool::class, [
            'vault_id' => $vault->id,
            'node_id' => $folder->id,
            'expected_revision' => 1,
            'destination_parent_id' => $child->id,
        ])
        ->assertHasErrors(['cannot be moved inside one of its descendants'])
        ->assertStructuredContent(fn(AssertableJson $json): AssertableJson => $json
            ->where('error.code', 'descendant_parent')
            ->etc());

    expect($folder->refresh()->parent_id)->toBeNull();
});

it('does not expose a deletion tool', function (): void {
    $server = app(ManyNotesServer::class, ['transport' => new FakeTransporter()]);
    $server->start();
    $toolNames = $server->createContext()->tools()->map->name()->all();

    expect($toolNames)->not->toContain('delete_document');
});
