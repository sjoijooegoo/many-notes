<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mcp\Support\McpVaultAccess;
use App\Models\User;
use App\Models\Vault;
use Illuminate\Console\Command;

final class CreateMcpTokenCommand extends Command
{
    protected $signature = 'mcp:token:create
        {email : Many Notes account email}
        {vault : Vault ID}
        {--name=AI client : Token name}
        {--read-only : Do not allow creating or updating documents}
        {--expires=365 : Expiration in days}';

    protected $description = 'Create a vault-scoped API token for the Many Notes MCP server';

    public function handle(McpVaultAccess $access): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (!$user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $vault = Vault::query()->find((int) $this->argument('vault'));
        $readOnly = (bool) $this->option('read-only');

        if (!$vault || !$user->can($readOnly ? 'view' : 'update', $vault)) {
            $this->error('Vault not found or the user does not have the requested access.');

            return self::FAILURE;
        }

        $expires = filter_var($this->option('expires'), FILTER_VALIDATE_INT);

        if (!is_int($expires) || $expires < 1 || $expires > 3650) {
            $this->error('The expiration must be between 1 and 3650 days.');

            return self::FAILURE;
        }

        $abilities = [$access->ability($vault->id, McpVaultAccess::READ)];

        if (!$readOnly) {
            $abilities[] = $access->ability($vault->id, McpVaultAccess::WRITE);
        }

        $token = $user->createToken(
            (string) $this->option('name'),
            $abilities,
            now()->addDays($expires),
        );

        $this->info("Created token ID {$token->accessToken->id} for vault {$vault->id} ({$vault->name}).");
        $this->warn('Copy this token now. It will not be shown again:');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
