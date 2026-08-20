<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

final class ListMcpTokensCommand extends Command
{
    protected $signature = 'mcp:token:list {email : Many Notes account email}';

    protected $description = 'List MCP API tokens without revealing their secrets';

    public function handle(): int
    {
        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if (!$user) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $rows = $user->tokens()
            ->orderBy('id')
            ->get()
            ->map(fn(PersonalAccessToken $token): array => [
                $token->id,
                $token->name,
                implode(', ', array_filter($token->abilities ?? [], is_string(...))),
                $token->last_used_at?->toIso8601String() ?? 'never',
                $token->expires_at?->toIso8601String() ?? 'never',
            ])
            ->all();

        $this->table(['ID', 'Name', 'Abilities', 'Last used', 'Expires'], $rows);

        return self::SUCCESS;
    }
}
