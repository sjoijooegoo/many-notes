<?php

declare(strict_types=1);

use App\Actions\CreateVault;
use App\Actions\CreateVaultNode;
use App\Actions\GetVaultPageData;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('opens an absolute internal link when the source is outside recent files', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $root = new CreateVaultNode()->handle($vault, ['is_file' => false, 'name' => 'ObsidianData']);
    $codex = new CreateVaultNode()->handle($vault, [
        'parent_id' => $root->id,
        'is_file' => false,
        'name' => 'Codex',
    ]);
    $plans = new CreateVaultNode()->handle($vault, [
        'parent_id' => $codex->id,
        'is_file' => false,
        'name' => '方案',
    ]);
    $discussions = new CreateVaultNode()->handle($vault, [
        'parent_id' => $codex->id,
        'is_file' => false,
        'name' => '讨论',
    ]);
    $source = new CreateVaultNode()->handle($vault, [
        'parent_id' => $plans->id,
        'is_file' => true,
        'name' => 'UE5材质实例编辑器UI代码调研',
        'extension' => 'md',
        'content' => '[讨论](/ObsidianData/Codex/讨论/UE5材质实例编辑器UI代码调研.md)',
    ]);
    $target = new CreateVaultNode()->handle($vault, [
        'parent_id' => $discussions->id,
        'is_file' => true,
        'name' => 'UE5材质实例编辑器UI代码调研',
        'extension' => 'md',
        'content' => '# Discussion',
    ]);

    for ($index = 0; $index < 11; $index++) {
        $this->travel(1)->seconds();
        new CreateVaultNode()->handle($vault, [
            'is_file' => true,
            'name' => "Newer document {$index}",
            'extension' => 'md',
            'content' => '# Newer',
        ]);
    }

    $recentIds = app(GetVaultPageData::class)->handle($vault)['recentFiles']->pluck('id');

    expect($recentIds)->not->toContain($source->id);

    $this->actingAs($user)
        ->get(route('vaults.show', [
            'vault' => $vault->id,
            'file' => $source->id,
            'path' => '/ObsidianData/Codex/讨论/UE5材质实例编辑器UI代码调研.md',
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page): Assert => $page
            ->component('vault/Show', false)
            ->where('openedFile.file.id', $target->id)
            ->where('openedFile.file.full_path', '/ObsidianData/Codex/讨论/UE5材质实例编辑器UI代码调研.md'));
});

it('opens an encoded relative internal link with special characters', function (): void {
    $user = User::factory()->create();
    $vault = new CreateVault()->handle($user, ['name' => 'Knowledge']);
    $root = new CreateVaultNode()->handle($vault, ['is_file' => false, 'name' => 'ObsidianData']);
    $plans = new CreateVaultNode()->handle($vault, [
        'parent_id' => $root->id,
        'is_file' => false,
        'name' => '方案',
    ]);
    $discussions = new CreateVaultNode()->handle($vault, [
        'parent_id' => $root->id,
        'is_file' => false,
        'name' => '讨论',
    ]);
    $source = new CreateVaultNode()->handle($vault, [
        'parent_id' => $plans->id,
        'is_file' => true,
        'name' => 'Source',
        'extension' => 'md',
        'content' => '[Target](../讨论/UE5%20材质%20%28新版%29%231.md)',
    ]);
    $target = new CreateVaultNode()->handle($vault, [
        'parent_id' => $discussions->id,
        'is_file' => true,
        'name' => 'UE5 材质 (新版)#1',
        'extension' => 'md',
        'content' => '# Target',
    ]);

    $this->actingAs($user)
        ->get(route('vaults.show', [
            'vault' => $vault->id,
            'file' => $source->id,
            'path' => '../讨论/UE5%20材质%20%28新版%29%231.md',
        ]))
        ->assertOk()
        ->assertInertia(fn(Assert $page): Assert => $page
            ->component('vault/Show', false)
            ->where('openedFile.file.id', $target->id)
            ->where('openedFile.file.full_path', '/ObsidianData/讨论/UE5 材质 (新版)#1.md'));
});
