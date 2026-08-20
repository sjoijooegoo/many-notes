<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\Vault;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld(false)]
final class ListVaultsTool extends ManyNotesTool
{
    protected string $name = 'list_vaults';

    protected string $description = 'List the Many Notes vaults that this API token is allowed to read.';

    public function handle(Request $request): ResponseFactory
    {
        $vaults = $this->access->readableVaults($request)
            ->map(fn(Vault $vault): array => [
                'id' => $vault->id,
                'name' => $vault->name,
                'updated_at' => $vault->updated_at->toIso8601String(),
            ])
            ->all();

        return Response::structured(['vaults' => $vaults]);
    }
}
