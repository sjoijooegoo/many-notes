<?php

declare(strict_types=1);

use App\Mcp\Support\McpVaultAccess;
use App\Models\User;
use App\Models\Vault;

it('creates a token that reads and writes only the current vault by default', function (): void {
    $user = User::factory()->create();
    $vault = Vault::factory()->for($user)->create();

    $response = $this
        ->actingAs($user)
        ->postJson(route('vaults.mcp-tokens.store', ['vault' => $vault->id]), [
            'name' => 'Windows Codex',
            'expires' => 30,
            'read_all_vaults' => false,
            'can_write' => true,
        ]);

    $response
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.metadata.read_all_vaults', false)
        ->assertJsonPath('data.metadata.can_write', true);

    expect($response->json('data.token'))->toBeString()->toContain('|');

    $token = $user->tokens()->sole();

    expect($token->abilities)->toEqualCanonicalizing([
        "mcp:vault:{$vault->id}:read",
        "mcp:vault:{$vault->id}:write",
    ])->and(implode(' ', $token->abilities ?? []))->not->toContain('delete');
});

it('can grant dynamic read access to every visible vault without cross-vault write access', function (): void {
    $user = User::factory()->create();
    $vault = Vault::factory()->for($user)->create();

    $this
        ->actingAs($user)
        ->postJson(route('vaults.mcp-tokens.store', ['vault' => $vault->id]), [
            'name' => 'Knowledge search',
            'expires' => 365,
            'read_all_vaults' => true,
            'can_write' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('data.metadata.read_all_vaults', true)
        ->assertJsonPath('data.metadata.can_write', false);

    expect($user->tokens()->sole()->abilities)->toEqualCanonicalizing([
        "mcp:vault:{$vault->id}:read",
        McpVaultAccess::READ_ALL_VISIBLE_ABILITY,
    ]);
});

it('lists only the current users tokens that apply to the current vault', function (): void {
    $user = User::factory()->create();
    [$vault, $otherVault] = Vault::factory(2)->for($user)->create();
    $otherUser = User::factory()->create();

    $user->createToken('Current vault', ["mcp:vault:{$vault->id}:read"]);
    $user->createToken('Other vault', ["mcp:vault:{$otherVault->id}:read"]);
    $user->createToken('All vaults', [McpVaultAccess::READ_ALL_VISIBLE_ABILITY]);
    $otherUser->createToken('Someone else', [McpVaultAccess::READ_ALL_VISIBLE_ABILITY]);

    $response = $this
        ->actingAs($user)
        ->getJson(route('vaults.mcp-tokens.index', ['vault' => $vault->id]));

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data.tokens')
        ->assertJsonFragment(['name' => 'Current vault'])
        ->assertJsonFragment(['name' => 'All vaults'])
        ->assertJsonMissing(['name' => 'Other vault'])
        ->assertJsonMissing(['name' => 'Someone else'])
        ->assertJsonMissingPath('data.tokens.0.token');

    expect($response->getContent())->not->toContain($user->tokens()->firstOrFail()->token);
});

it('allows an accepted collaborator to create a token for a writable vault', function (): void {
    [$owner, $collaborator] = User::factory(2)->create();
    $vault = Vault::factory()->for($owner)->create();
    $vault->collaborators()->attach($collaborator, ['accepted' => true]);

    $this
        ->actingAs($collaborator)
        ->postJson(route('vaults.mcp-tokens.store', ['vault' => $vault->id]), [
            'name' => 'Collaborator AI',
            'expires' => 30,
            'read_all_vaults' => false,
            'can_write' => true,
        ])
        ->assertCreated();

    expect($collaborator->tokens()->count())->toBe(1);
});

it('forbids token management for a vault the user cannot view', function (): void {
    [$owner, $otherUser] = User::factory(2)->create();
    $vault = Vault::factory()->for($owner)->create();

    $this
        ->actingAs($otherUser)
        ->getJson(route('vaults.mcp-tokens.index', ['vault' => $vault->id]))
        ->assertForbidden();

    $this
        ->actingAs($otherUser)
        ->postJson(route('vaults.mcp-tokens.store', ['vault' => $vault->id]), [
            'name' => 'Unauthorized',
            'expires' => 30,
            'read_all_vaults' => false,
            'can_write' => false,
        ])
        ->assertForbidden();
});

it('revokes only the current users own token', function (): void {
    [$user, $otherUser] = User::factory(2)->create();
    $vault = Vault::factory()->for($user)->create();
    $ownToken = $user->createToken('Own', ["mcp:vault:{$vault->id}:read"])->accessToken;
    $otherToken = $otherUser->createToken('Other', ["mcp:vault:{$vault->id}:read"])->accessToken;

    $this
        ->actingAs($user)
        ->deleteJson(route('vaults.mcp-tokens.destroy', [
            'vault' => $vault->id,
            'token' => $otherToken->id,
        ]))
        ->assertNotFound();

    $this
        ->actingAs($user)
        ->deleteJson(route('vaults.mcp-tokens.destroy', [
            'vault' => $vault->id,
            'token' => $ownToken->id,
        ]))
        ->assertOk();

    expect($user->tokens()->whereKey($ownToken->id)->exists())->toBeFalse()
        ->and($otherUser->tokens()->whereKey($otherToken->id)->exists())->toBeTrue();
});

it('validates token names, expiry, and explicit permission choices', function (): void {
    $user = User::factory()->create();
    $vault = Vault::factory()->for($user)->create();

    $this
        ->actingAs($user)
        ->postJson(route('vaults.mcp-tokens.store', ['vault' => $vault->id]), [
            'name' => '   ',
            'expires' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'expires', 'read_all_vaults', 'can_write']);
});
