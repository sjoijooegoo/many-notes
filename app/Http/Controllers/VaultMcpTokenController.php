<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreVaultMcpTokenRequest;
use App\Mcp\Support\McpVaultAccess;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

final readonly class VaultMcpTokenController
{
    public function index(
        Vault $vault,
        #[CurrentUser] User $currentUser,
        McpVaultAccess $access,
    ): JsonResponse {
        abort_unless($currentUser->can('view', $vault), 403);

        $tokens = $currentUser->tokens()
            ->orderByDesc('id')
            ->get()
            ->filter(fn(PersonalAccessToken $token): bool => $this->appliesToVault($token, $vault, $access))
            ->map(fn(PersonalAccessToken $token): array => $this->tokenData($token, $vault, $access))
            ->values();

        return $this->privateJson([
            'data' => [
                'endpoint' => config('many_notes_mcp.url'),
                'vault' => [
                    'id' => $vault->id,
                    'name' => $vault->name,
                ],
                'tokens' => $tokens,
            ],
        ]);
    }

    public function store(
        StoreVaultMcpTokenRequest $request,
        Vault $vault,
        #[CurrentUser] User $currentUser,
        McpVaultAccess $access,
    ): JsonResponse {
        abort_unless($currentUser->can('view', $vault), 403);

        $request->validated();
        $name = mb_trim($request->string('name')->toString());
        $expires = $request->integer('expires');
        $readAllVaults = $request->boolean('read_all_vaults');
        $canWrite = $request->boolean('can_write');

        if ($canWrite) {
            abort_unless($currentUser->can('update', $vault), 403);
        }

        $abilities = [$access->ability($vault->id, McpVaultAccess::READ)];

        if ($readAllVaults) {
            $abilities[] = McpVaultAccess::READ_ALL_VISIBLE_ABILITY;
        }

        if ($canWrite) {
            $abilities[] = $access->ability($vault->id, McpVaultAccess::WRITE);
        }

        $token = $currentUser->createToken(
            $name,
            $abilities,
            now()->addDays($expires),
        );

        return $this->privateJson([
            'data' => [
                'token' => $token->plainTextToken,
                'metadata' => $this->tokenData($token->accessToken, $vault, $access),
            ],
        ], 201);
    }

    public function destroy(
        Vault $vault,
        PersonalAccessToken $token,
        #[CurrentUser] User $currentUser,
        McpVaultAccess $access,
    ): JsonResponse {
        abort_unless($currentUser->can('view', $vault), 403);
        abort_unless(
            $token->tokenable_type === User::class
            && $token->tokenable_id === $currentUser->id
            && $this->appliesToVault($token, $vault, $access),
            404,
        );

        $token->delete();

        return $this->privateJson([]);
    }

    private function appliesToVault(PersonalAccessToken $token, Vault $vault, McpVaultAccess $access): bool
    {
        if ($token->can(McpVaultAccess::READ_ALL_VISIBLE_ABILITY)) {
            return true;
        }
        if ($token->can($access->ability($vault->id, McpVaultAccess::READ))) {
            return true;
        }

        return $token->can($access->ability($vault->id, McpVaultAccess::WRITE));
    }

    /** @return array<string, bool|int|string|null> */
    private function tokenData(
        PersonalAccessToken $token,
        Vault $vault,
        McpVaultAccess $access,
    ): array {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'read_all_vaults' => $token->can(McpVaultAccess::READ_ALL_VISIBLE_ABILITY),
            'can_write' => $token->can($access->ability($vault->id, McpVaultAccess::WRITE)),
            'created_at' => $token->created_at?->toIso8601String(),
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'expired' => $token->expires_at?->isPast() ?? false,
        ];
    }

    /** @param array<string, mixed> $data */
    private function privateJson(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
