<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use App\Models\User;
use App\Models\Vault;
use App\Models\VaultNode;
use App\Queries\Vaults\VisibleVaultsQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Mcp\Request;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class McpVaultAccess
{
    public const string READ = 'read';

    public const string WRITE = 'write';

    public const string READ_ALL_VISIBLE_ABILITY = 'mcp:vaults:read';

    public function user(Request $request): ?User
    {
        $user = $request->user();

        if (!$user instanceof User) {
            return null;
        }

        // Sanctum may provide a TransientToken at runtime even though its trait's PHPDoc is narrower.
        /** @var mixed $accessToken */
        $accessToken = $user->currentAccessToken();

        return $accessToken instanceof PersonalAccessToken ? $user : null;
    }

    public function vault(Request $request, int $vaultId, string $ability): ?Vault
    {
        $user = $this->user($request);

        if (!$user || !$this->tokenCanAccessVault($user, $vaultId, $ability)) {
            return null;
        }

        $vault = Vault::query()->find($vaultId);

        if (!$vault) {
            return null;
        }

        $policy = $ability === self::READ ? 'view' : 'update';

        return $user->can($policy, $vault) ? $vault : null;
    }

    /** @return Collection<int, Vault> */
    public function readableVaults(Request $request): Collection
    {
        $user = $this->user($request);

        if (!$user instanceof User) {
            return new Collection();
        }

        $canReadAllVisibleVaults = $user->tokenCan(self::READ_ALL_VISIBLE_ABILITY);

        return app(VisibleVaultsQuery::class)($user)
            ->get()
            ->filter(fn(Vault $vault): bool => $canReadAllVisibleVaults
                || $user->tokenCan($this->ability($vault->id, self::READ)))
            ->values();
    }

    public function document(Vault $vault, int $documentId): ?VaultNode
    {
        return $vault->nodes()
            ->whereKey($documentId)
            ->where('is_file', true)
            ->where('extension', 'md')
            ->first();
    }

    public function node(Vault $vault, int $nodeId): ?VaultNode
    {
        return $vault->nodes()
            ->whereKey($nodeId)
            ->where(function (Builder $query): void {
                $query->where('is_file', false)
                    ->orWhere(function (Builder $query): void {
                        $query->where('is_file', true)->where('extension', 'md');
                    });
            })
            ->first();
    }

    public function folder(Vault $vault, ?int $folderId): ?VaultNode
    {
        if ($folderId === null) {
            return null;
        }

        return $vault->nodes()
            ->whereKey($folderId)
            ->where('is_file', false)
            ->first();
    }

    /** @return array<string, mixed> */
    public function documentData(VaultNode $node, bool $includeContent = true): array
    {
        $data = $this->nodeData($node);

        if ($includeContent) {
            $data['content'] = $node->content ?? '';
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function nodeData(VaultNode $node): array
    {
        $data = [
            'id' => $node->id,
            'vault_id' => $node->vault_id,
            'parent_id' => $node->parent_id,
            'kind' => $node->is_file ? 'document' : 'folder',
            'name' => $node->name,
            'path' => '/' . $node->fullPath() . ($node->is_file ? '.md' : ''),
            'revision' => $node->revision,
            'updated_at' => $node->updated_at->toIso8601String(),
        ];

        if ($node->is_file) {
            $data['content_hash'] = hash('sha256', $node->content ?? '');
        }

        return $data;
    }

    public function ability(int $vaultId, string $ability): string
    {
        return "mcp:vault:{$vaultId}:{$ability}";
    }

    private function tokenCanAccessVault(User $user, int $vaultId, string $ability): bool
    {
        if ($user->tokenCan($this->ability($vaultId, $ability))) {
            return true;
        }

        return $ability === self::READ && $user->tokenCan(self::READ_ALL_VISIBLE_ABILITY);
    }
}
